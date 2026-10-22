<?php

declare(strict_types=1);

namespace App\Services\Rotation;

use App\Enums\RotationStatus;
use App\Models\Credential;
use App\Models\PasswordRotation;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Toplu turun TEK göstergesi.
 *
 * NEDEN VAR: "Seçilenleri güncelle" her kayıt için ayrı bir iş kuyruğa
 * atıyordu ve ekran "12 kayıt sıraya alındı" deyip susuyordu. İlerlemeyi
 * görmenin tek yolu kayıtları tek tek açmaktı. Toplu işlemde ilerleme tek
 * göstergede görünmeli.
 *
 * PARTİ, turu başlatan istekte yazılır: kuyruk Redis'te ve içine bakıp "bu
 * iş hangi toplu tura ait" diye sormanın yolu yok. Parti yalnızca KİMLİKLERİ
 * ve başlangıç anını tutar; durum her sorulduğunda tur kayıtlarından yeniden
 * okunur. Böylece gösterge karar vermez, yalnızca olanı söyler — ayrı bir
 * sayaç tutsaydı, düşen bir iş sayacı sonsuza dek "sürüyor"da bırakırdı.
 */
class BulkRound
{
    /**
     * Kayıt durumlarının ekrandaki adları. Sıra, göstergedeki lejantın
     * sırasıdır. Ön yüz aynı anahtarları kullanır (bulk-progress.js).
     */
    public const ETIKETLER = [
        'success' => 'Başarılı',
        'running' => 'Sürüyor',
        'waiting' => 'Sırada',
        'unverified' => 'Doğrulanamadı',
        'failed' => 'Başarısız',
        'unstarted' => 'Başlamadı',
    ];

    private const PARTI = 'access:bulk-round:';

    private const SON = 'access:bulk-round-last:';

    /**
     * Parti ömrü. Sonuç ekranda bu süre boyunca kalır; kullanıcı kapatmazsa
     * kendiliğinden düşer.
     */
    private const OMUR = 7200;

    /**
     * Bir işin en uzun süresi (sn) — RotateCredentialPasswordJob::$timeout.
     *
     * İşçi turları SIRAYLA koşuyor; N kayıtlık bir parti en kötü N × bu
     * kadar sürer. Süre dolduğunda hâlâ başlamamış kayıt varsa işi düşmüş
     * sayarız: yoksa gösterge "sürüyor"da sonsuza dek beklerdi.
     */
    private const IS_SURESI = 120;

    /**
     * Yeni parti açar ve kullanıcının SON partisi yapar.
     *
     * @param  list<int>  $kimlikler
     */
    public static function baslat(User $kullanici, array $kimlikler): string
    {
        $parti = (string) Str::uuid();

        Cache::put(self::PARTI.$parti, [
            'kullanici' => $kullanici->getKey(),
            'kayitlar' => array_values(array_unique($kimlikler)),
            'baslangic' => now()->timestamp,
        ], self::OMUR);

        Cache::put(self::SON.$kullanici->getKey(), $parti, self::OMUR);

        return $parti;
    }

    /** Kullanıcının ekranda duran partisi. */
    public static function son(User $kullanici): ?string
    {
        $parti = Cache::get(self::SON.$kullanici->getKey());

        return is_string($parti) && Cache::has(self::PARTI.$parti) ? $parti : null;
    }

    /** Göstergeyi kapatır. Turlara dokunmaz; yalnızca ekrandan kalkar. */
    public static function kapat(User $kullanici, string $parti): void
    {
        if (Cache::get(self::SON.$kullanici->getKey()) === $parti) {
            Cache::forget(self::SON.$kullanici->getKey());
        }
    }

    /**
     * Partinin durumu. Parti yoksa ya da BAŞKASININSA null.
     *
     * Parti kimliği tahmin edilemez ama tek başına yetki sayılmaz: başka
     * birinin partisi sorulursa "yok" denir, varlığı bile söylenmez.
     *
     * @return array{
     *     running: bool, total: int, done: int, percent: int,
     *     success: int, failed: int, unverified: int, unstarted: int,
     *     pending: int, current: ?string, text: string,
     *     problems: list<array{name: string, status: string}>,
     *     records: list<array{name: string, status: string}>
     * }|null
     */
    public static function durum(User $kullanici, string $parti): ?array
    {
        $veri = Cache::get(self::PARTI.$parti);

        if (! is_array($veri) || $veri['kullanici'] !== $kullanici->getKey()) {
            return null;
        }

        $kimlikler = $veri['kayitlar'];
        $baslangic = $veri['baslangic'];

        /*
         * Her kaydın bu partiden SONRA açılmış son turu. Aynı kaydın önceki
         * turları sayılmaz: dünkü başarılı tur bugünkü partiyi "bitti"
         * göstermemeli. Kullanıcıya da bakılır — aynı anda başkasının elle
         * başlattığı tur bu partinin sonucu değildir.
         */
        $turlar = PasswordRotation::query()
            ->whereIn('credential_id', $kimlikler)
            ->where('user_id', $kullanici->getKey())
            ->where('created_at', '>=', date('Y-m-d H:i:s', $baslangic))
            ->latestFirst()
            ->get()
            ->unique('credential_id')
            ->keyBy('credential_id');

        $adlar = Credential::query()->withoutGlobalScopes()->whereIn('id', $kimlikler)->pluck('name', 'id');

        $sure = now()->timestamp - $baslangic;
        $zamanAsimi = $sure > count($kimlikler) * self::IS_SURESI + 60;

        $sayilar = ['success' => 0, 'failed' => 0, 'unverified' => 0, 'unstarted' => 0, 'pending' => 0];
        $suren = null;
        $sorunlu = [];

        /*
         * Kayıt başına durum — göstergedeki her parça bir makine. Sıra
         * partideki sıradır, yani işçinin işlediği sıra: parçalar soldan
         * sağa dolar.
         */
        $kayitlar = [];

        foreach ($kimlikler as $id) {
            $tur = $turlar->get($id);
            $ad = (string) ($adlar[$id] ?? "#{$id}");

            if ($tur === null) {
                /*
                 * Tur kaydı yok: iş ya sırada bekliyor ya da hiç başlamadan
                 * düştü. Süre dolmadıysa bekliyor sayılır.
                 */
                if ($zamanAsimi) {
                    $sayilar['unstarted']++;
                    $sorunlu[] = ['name' => $ad, 'status' => 'Başlamadı'];
                    $kayitlar[] = ['name' => $ad, 'status' => 'unstarted'];
                } else {
                    $sayilar['pending']++;
                    $kayitlar[] = ['name' => $ad, 'status' => 'waiting'];
                }

                continue;
            }

            match ($tur->status) {
                RotationStatus::Running => $suren ??= $ad,
                RotationStatus::Succeeded => $sayilar['success']++,
                RotationStatus::Unverified => $sayilar['unverified']++,
                default => $sayilar['failed']++,
            };

            $kayitlar[] = ['name' => $ad, 'status' => match (true) {
                $tur->status === RotationStatus::Running && $zamanAsimi => 'unstarted',
                $tur->status === RotationStatus::Running => 'running',
                $tur->status === RotationStatus::Succeeded => 'success',
                $tur->status === RotationStatus::Unverified => 'unverified',
                default => 'failed',
            }];

            if ($tur->status === RotationStatus::Running && $zamanAsimi) {
                /* Süre dolduğu hâlde "sürüyor" diyen tur: işçi ölmüş olabilir. */
                $sayilar['unstarted']++;
                $sorunlu[] = ['name' => $ad, 'status' => 'Yanıt yok'];
            } elseif (! in_array($tur->status, [RotationStatus::Running, RotationStatus::Succeeded], true)) {
                $sorunlu[] = ['name' => $ad, 'status' => $tur->status->label()];
            }
        }

        $toplam = count($kimlikler);
        $biten = $sayilar['success'] + $sayilar['failed'] + $sayilar['unverified'] + $sayilar['unstarted'];
        $calisiyor = $biten < $toplam;

        return [
            'running' => $calisiyor,
            'total' => $toplam,
            'done' => $biten,
            'percent' => $toplam === 0 ? 100 : (int) floor($biten / $toplam * 100),
            ...$sayilar,
            'current' => $calisiyor ? $suren : null,
            'text' => self::metin($calisiyor, $suren, $sayilar),
            'problems' => $sorunlu,
            'records' => $kayitlar,
        ];
    }

    /** @param  array<string, int>  $sayilar */
    private static function metin(bool $calisiyor, ?string $suren, array $sayilar): string
    {
        if ($calisiyor) {
            return $suren !== null ? "Güncelleniyor: {$suren}" : 'Sıradaki kayıt bekleniyor…';
        }

        $sorun = $sayilar['failed'] + $sayilar['unverified'] + $sayilar['unstarted'];

        return $sorun === 0 ? 'Tamamlandı, hepsi başarılı.' : "Tamamlandı, {$sorun} kayıtta sorun var.";
    }
}
