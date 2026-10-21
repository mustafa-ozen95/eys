<?php

declare(strict_types=1);

namespace App\Services\Activity;

use App\Models\ActivityLog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Denetim kaydını değiştirilemez kılan hash zinciri.
 *
 * Her satır kendinden öncekinin özetini taşır ve kendi özeti bir sonrakinin
 * içine girer. Bir satır sonradan değiştirilir ya da silinirse ondan sonraki
 * bütün özetler tutmaz; doğrulama kırılmanın TAM YERİNİ söyler. Amaç kaydı
 * gizlemek değil, DEĞİŞTİRİLMEDİĞİNİ kanıtlayabilmektir.
 *
 * Özet düz SHA değil, gizli anahtarla üretilen HMAC-SHA256'dır. Düz özet
 * kullanılsaydı veritabanına yazabilen biri bir satırı değiştirip zinciri
 * baştan hesaplayabilirdi; anahtar olmadan bunu yapamaz.
 *
 * ÇAPA VE UÇ VERİTABANINDA, MÜHÜRLÜ (activity_chain_state). Zincirin başı
 * (budamadan sonra) ve sonu (son özet, satır sayısı) imzalı tek bir satırda
 * durur; böylece sondaki satırların silinmesi de yakalanır. SINIR: mühür
 * yalnızca satırlar ile zincir anahtarı arasında güven kurar. Anahtarı da
 * ele geçiren biri her şeyi yeniden üretebilir; ve mühür ilk kullanımda
 * MEVCUT zincirden türetildiği için o ana kadar yapılmış bir kurcalama
 * kanıtlanamaz.
 *
 * Sıra önemlidir: iki kayıt aynı anda yazılırsa ikisi de aynı "önceki"yi
 * okuyup zinciri çatallandırabilir. Bu yüzden okuma ve yazma tek kilidin
 * içindedir.
 */
class ActivityChain
{
    public const AUDIT = 'audit';

    private const LOCK = 'activity:chain';

    /**
     * Özet biçiminin sürümü.
     *
     * Payload'a yeni alan eklenirse bu sayı artırılır ve eski sürüm
     * hashFor() içinde KORUNUR. Aksi hâlde biçim değişikliği bütün geçmişi
     * "kırık" gösterir ve zinciri sıfırlamak gerekir — sıfırlamak, zincirin
     * var olma sebebini ortadan kaldırır.
     */
    private const VERSION = 2;

    private const WAIT = 5;

    /** @param  array<string, mixed>  $attributes */
    public function append(array $attributes): ActivityLog
    {
        return $this->exclusive(function () use ($attributes): ActivityLog {
            /* Mühür, YENİ satırdan önce mevcut zincirden türetilir (bkz. durum). */
            $durum = $this->durum();

            /*
             * Zincirin ucu: en son ÖZETLİ satır. Zincirden önce yazılmış
             * satırlar arada durabilir; onların özeti yoktur ve ucu onlar
             * belirlemez.
             */
            $previous = ActivityLog::query()
                ->where('chain', self::AUDIT)
                ->whereNotNull('hash')
                ->latest('id')
                ->value('hash') ?? $durum['anchor_hash'];

            $log = new ActivityLog($attributes);
            $log->created_at = $attributes['created_at'] ?? now();
            $log->chain = self::AUDIT;
            $log->chain_version = self::VERSION;
            $log->previous_hash = $previous;
            $log->hash = $this->hashFor($log, $previous);

            DB::transaction(function () use ($log, $durum): void {
                $log->save();

                /*
                 * Mührü bozuk durum YENİLENMEZ: yeniden imzalamak kurcalamanın
                 * kanıtını silerdi. Doğrulama bunu bildirir.
                 */
                if ($durum['gecerli']) {
                    $this->yaz($durum['anchor_hash'], $log->id, $log->hash, $durum['rows'] + 1);
                }
            });

            return $log;
        });
    }

    /**
     * Zincir kilidi altında çalıştırır (ekleme ve budama aynı kilidi kullanır).
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function exclusive(callable $callback): mixed
    {
        return Cache::lock(self::LOCK, 10)->block(self::WAIT, $callback);
    }

    /**
     * Zinciri doğrular.
     *
     * Zincirden ÖNCE yazılmış satırlar doğrulanmaz ve doğrulanmış gibi de
     * gösterilmez: sayıları ayrıca bildirilir. Onları sonradan özetlemek
     * dürüst olmazdı — değişmediklerine dair elimizde kanıt yok.
     *
     * Üç şey daha sorulur: zincirin SONU mühürdeki uçla uyuşuyor mu (sondaki
     * satırlar silinmiş mi), satır sayısı tutuyor mu ve zincir dışı bir
     * türde (chain != audit) satır sokulmuş mu. Mührün kendisi de imza
     * kontrolünden geçer.
     *
     * @return array{ok: bool, checked: int, unprotected: int, broken_id: ?int, checked_at: string, detail: ?string}
     */
    public function verify(): array
    {
        $durum = $this->exclusive(fn (): array => $this->durum());

        $previous = $durum['anchor_hash'];
        $checked = 0;
        $unprotected = 0;
        $broken = null;
        $lastId = 0;

        $this->yuru($previous, $checked, $unprotected, $broken, $lastId);

        $detail = null;

        /*
         * Yürüyüş sürerken yeni satır eklenmiş olabilir (uzun sürer). Sonu
         * mühürle karşılaştırmadan önce kilit altında artığı yürür ve mührü
         * AYNI ANDA yeniden okur; yoksa canlı bir sistemde sağlam zincir
         * kırık görünürdü.
         */
        $this->exclusive(function () use (&$previous, &$checked, &$unprotected, &$broken, &$lastId, &$detail): void {
            $this->yuru($previous, $checked, $unprotected, $broken, $lastId);

            $mevcut = $this->durum(olustur: false);

            if ($broken !== null || $mevcut === null) {
                return;
            }

            if (! $mevcut['gecerli']) {
                $detail = 'Zincir mührü bozuk: çapa ya da uç imzayla uyuşmuyor.';
            } elseif ($previous !== ($mevcut['head_hash'] ?? $mevcut['anchor_hash'])) {
                $detail = 'Zincirin sonu mühürdeki uçla uyuşmuyor; son satırlar silinmiş olabilir.';
            } elseif ($checked !== $mevcut['rows']) {
                $detail = "Özetli satır sayısı mühürdekiyle uyuşmuyor ({$checked} bulundu, {$mevcut['rows']} bekleniyordu).";
            }
        });

        /* Zincir türü 'audit' olmayan satır: ekranda listelenir ama hiçbir özetle korunmaz. */
        $yabanci = ActivityLog::query()->where('chain', '!=', self::AUDIT)->orderBy('id')->value('id');

        if ($broken === null && $detail === null && $yabanci !== null) {
            $broken = (int) $yabanci;
            $detail = 'Zincir dışı bir türde satır var (#'.$yabanci.'); denetim ekranında görünür ama korunmaz.';
        }

        $result = [
            'ok' => $broken === null && $detail === null,
            'checked' => $checked,
            'unprotected' => $unprotected,
            'broken_id' => $broken,
            'checked_at' => now()->toIso8601String(),
            'detail' => $detail,
        ];

        Cache::put((string) config('activity.verification_key'), $result, now()->addWeek());

        return $result;
    }

    /**
     * Zinciri $lastId'den sonrası için yürür; ilk kırılmada durur.
     *
     * @param  int  $lastId  son doğrulanan satırın kimliği
     */
    private function yuru(?string &$previous, int &$checked, int &$unprotected, ?int &$broken, int &$lastId): void
    {
        if ($broken !== null) {
            return;
        }

        ActivityLog::query()
            ->where('chain', self::AUDIT)
            ->where('id', '>', $lastId)
            ->orderBy('id')
            ->chunkById(500, function ($rows) use (&$previous, &$checked, &$unprotected, &$broken, &$lastId): bool {
                foreach ($rows as $log) {
                    $lastId = $log->id;

                    if ($log->hash === null) {
                        $unprotected++;

                        continue;
                    }

                    $checked++;

                    if ($log->previous_hash !== $previous || ! hash_equals($log->hash, $this->hashFor($log, $previous))) {
                        $broken = $log->id;

                        return false;
                    }

                    $previous = $log->hash;
                }

                return true;
            });
    }

    /**
     * Doğrulama SÜRÜYOR mu?
     *
     * Ekran bunu bilmezse kullanıcı düğmeye basar, hiçbir şey değişmez ve
     * tekrar basar; her basış kuyruğa aynı işi ekler.
     */
    public function isRunning(): bool
    {
        return (bool) Cache::get($this->runningKey(), false);
    }

    /** Doğrulama kuyruğa alındı: ekran bunu göstersin. */
    public function markRunning(): void
    {
        /* Süre, işin en uzun hâlinden biraz fazlası: iş düşse de bayrak takılı kalmaz. */
        Cache::put($this->runningKey(), true, now()->addMinutes(20));
    }

    public function finishRunning(): void
    {
        Cache::forget($this->runningKey());
    }

    private function runningKey(): string
    {
        return (string) config('activity.verification_key').':running';
    }

    /**
     * En son doğrulama sonucu.
     *
     * Ekran her açılışta bütün satırları yeniden özetlemez; son doğrulamanın
     * bıraktığı sonucu okur.
     *
     * @return array{ok: bool, checked: int, unprotected: int, broken_id: ?int, checked_at: string}|null
     */
    public function lastVerification(): ?array
    {
        $result = Cache::get((string) config('activity.verification_key'));

        return is_array($result) ? $result : null;
    }

    /**
     * Budama sonrası zincirin yeni başlangıç noktası.
     *
     * Saklama süresi dolan satırlar silindiğinde kalan ilk satırın "önceki"si
     * artık veritabanında yoktur. Silinen son satırın özeti çapa olarak
     * MÜHÜRLÜ durumda veritabanında saklanır (önbellekte dursaydı yeniden
     * başlatmada kaybolurdu). Çağıran zincir kilidini (exclusive) tutar ve
     * silmeyi AYNI işlemde yapar; $silinenOzetli budanan ÖZETLİ satır sayısıdır.
     */
    public function anchorTo(?string $hash, int $silinenOzetli = 0): void
    {
        $durum = $this->durum();

        if (! $durum['gecerli'] || $hash === null) {
            return;
        }

        $kalan = max(0, $durum['rows'] - $silinenOzetli);

        /* Hepsi budandıysa uç çapanın kendisidir (doğrulama bunu bekler). */
        $this->yaz(
            $hash,
            $kalan === 0 ? null : $durum['head_id'],
            $kalan === 0 ? $hash : $durum['head_hash'],
            $kalan,
        );
    }

    /**
     * Mühürlü durum: çapa, uç ve özetli satır sayısı.
     *
     * Satır yoksa MEVCUT zincirden türetilir (lazy): canlıdaki satırlar
     * böylece doğrulanmaya devam eder. Eski çapa önbellekteyse oradan alınır
     * (geçiş). Türetme anında veritabanına güvenilir; bundan önceki
     * kurcalamayı kanıtlayamayız (sınıf açıklamasındaki sınır).
     *
     * Zincir kilidi altında çağrılır.
     *
     * @return ($olustur is true ? array{anchor_hash: ?string, head_id: ?int, head_hash: ?string, rows: int, gecerli: bool} : array{anchor_hash: ?string, head_id: ?int, head_hash: ?string, rows: int, gecerli: bool}|null)
     */
    private function durum(bool $olustur = true): ?array
    {
        $satir = DB::table('activity_chain_state')->where('id', 1)->first();

        if ($satir === null) {
            if (! $olustur) {
                return null;
            }

            $onbellek = Cache::get((string) config('activity.chain_anchor_key'));
            $ucSatir = ActivityLog::query()
                ->where('chain', self::AUDIT)->whereNotNull('hash')->orderByDesc('id')->first(['id', 'hash']);
            $adet = ActivityLog::query()->where('chain', self::AUDIT)->whereNotNull('hash')->count();
            $capa = is_string($onbellek) ? $onbellek : null;

            $this->yaz($capa, $ucSatir?->id, $ucSatir?->hash ?? $capa, $adet);

            return [
                'anchor_hash' => $capa, 'head_id' => $ucSatir?->id,
                'head_hash' => $ucSatir?->hash ?? $capa, 'rows' => $adet, 'gecerli' => true,
            ];
        }

        $durum = [
            'anchor_hash' => $satir->anchor_hash,
            'head_id' => $satir->head_id === null ? null : (int) $satir->head_id,
            'head_hash' => $satir->head_hash,
            'rows' => (int) $satir->rows,
        ];

        return $durum + ['gecerli' => hash_equals($this->imza($durum), (string) $satir->mac)];
    }

    private function yaz(?string $capa, ?int $uc, ?string $ucOzet, int $adet): void
    {
        $durum = ['anchor_hash' => $capa, 'head_id' => $uc, 'head_hash' => $ucOzet, 'rows' => $adet];

        DB::table('activity_chain_state')->updateOrInsert(['id' => 1], $durum + [
            'mac' => $this->imza($durum),
            'updated_at' => now(),
        ]);
    }

    /** @param  array{anchor_hash: ?string, head_id: ?int, head_hash: ?string, rows: int}  $durum */
    private function imza(array $durum): string
    {
        return hash_hmac('sha256', json_encode(['state', 1, $durum['anchor_hash'], $durum['head_id'], $durum['head_hash'], $durum['rows']]), (string) config('activity.chain_key'));
    }

    /**
     * Satırın özeti.
     *
     * Özete giren alanlar kaydın ANLAMINI taşıyanlardır; biri değiştirilirse
     * özet tutmaz. Sıralama sabittir, aksi hâlde aynı satır farklı özet
     * üretirdi.
     */
    private function hashFor(ActivityLog $log, ?string $previous): string
    {
        $action = $log->action instanceof \BackedEnum ? (string) $log->action->value : (string) $log->action;

        /*
         * SÜRÜM 1 (eski satırlar) KORUNUR: biçim değişikliği geçmişi
         * "kırık" gösterirdi. Sürüm 1'de alanlar '|' ile birleşiyordu — sınır
         * karakteri içeren alanlar kaydırılabiliyordu — ve user_agent ile
         * zincir adı özete girmiyordu. Sürüm 2 JSON dizisi kullanır
         * (birleştirme tek anlamlı) ve her iki alanı da kapsar.
         */
        if ((int) $log->chain_version < 2) {
            $payload = implode('|', [
                $previous ?? '',
                $log->created_at?->format('Y-m-d H:i:s.u') ?? '',
                (string) $log->user_id,
                (string) $log->actor_name,
                (string) $log->subject_type,
                (string) $log->subject_id,
                $action,
                (string) $log->summary,
                (string) $log->reason,
                (string) $log->ip_address,
            ]);

            return hash_hmac('sha256', $payload, (string) config('activity.chain_key'));
        }

        $payload = json_encode([
            2,
            $previous ?? '',
            $log->created_at?->format('Y-m-d H:i:s.u') ?? '',
            (string) $log->user_id,
            (string) $log->actor_name,
            (string) $log->subject_type,
            (string) $log->subject_id,
            $action,
            (string) $log->summary,
            (string) $log->reason,
            (string) $log->ip_address,
            (string) $log->user_agent,
            (string) $log->chain,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return hash_hmac('sha256', $payload, (string) config('activity.chain_key'));
    }
}
