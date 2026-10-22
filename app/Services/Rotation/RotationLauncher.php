<?php

declare(strict_types=1);

namespace App\Services\Rotation;

use App\Jobs\RotateCredentialPasswordJob;
use App\Models\Credential;
use App\Models\PasswordRotation;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Döndürmeyi BAŞLATAN tek yer.
 *
 * Tek kayıt ve toplu döndürme aynı satıra giden iki yoldur; kural kopyalansaydı
 * biri değiştiğinde diğeri sessizce eski kalırdı. Üç soru her iki yolda da
 * BURADA sorulur:
 *
 *   1. Kullanıcının BU kayıtta döndürme yetkisi var mı? (toplu seçim yetki
 *      genişletmez — yirmi kaydı işaretlemek yirmi ayrı yetki demektir)
 *   2. Kayıt döndürülebilir durumda mı? (sürücü, açık/kapalı, eksik alan)
 *   3. İş kuyruğa alınır; cihaza bağlanmak tarayıcıyı bekletmez.
 *
 * Atlananlar SESSİZCE düşürülmez: nedenleriyle geri döner, ekran onları yazar.
 */
class RotationLauncher
{
    public function __construct(private DriverRegistry $registry) {}

    /**
     * @param  iterable<Credential>  $credentials
     * @return array{queued: list<string>, queuedIds: list<int>, skipped: array<string, string>}
     */
    public function launch(iterable $credentials, User $actor, string $reason, bool $toplu = false): array
    {
        $queued = [];
        $queuedIds = [];
        $skipped = [];

        foreach ($credentials as $credential) {
            $engel = $this->reasonToSkip($credential, $actor, $toplu);

            if ($engel !== null) {
                $skipped[$credential->name] = $engel;

                continue;
            }

            RotateCredentialPasswordJob::dispatch($credential, $actor, $reason);
            self::markPending($credential);
            $queued[] = $credential->name;
            $queuedIds[] = (int) $credential->getKey();
        }

        return ['queued' => $queued, 'queuedIds' => $queuedIds, 'skipped' => $skipped];
    }

    /**
     * "Sıraya alındı ama işçi henüz başlamadı" izi.
     *
     * Tur kaydı PasswordRotator içinde, yani İŞÇİ çalışmaya başlayınca
     * açılıyor. Düğmeye basılan an ile o an arasında veritabanında hiçbir iz
     * yok: ekran "sıraya alındı" diyor, panelde gösterilecek bir tur
     * bulunmuyor ve ilerleme çubuğu hiç çizilmiyordu. Kullanıcı için bu,
     * düğmenin işlemediği anlamına gelir.
     *
     * İz kısa ömürlüdür ve yalnızca ekran içindir; turun kendisi için hiçbir
     * karar buna dayanmaz.
     */
    private const BEKLEYEN = 'access:rotation-pending:';

    /** İzin ömrü: kuyruk tıkalıysa çubuk sonsuza dek dönmemeli. */
    private const BEKLEME_OMRU = 300;

    /** Kayıt için kuyrukta bekleyen bir tur var mı? */
    public static function pending(Credential $credential): ?int
    {
        $an = Cache::get(self::BEKLEYEN.$credential->getKey());

        return $an === null ? null : (int) $an;
    }

    /**
     * İş sırada ama işçi henüz BAŞLAMADI mı?
     *
     * İz tek başına yetmez: izden SONRA açılmış bir tur kaydı varsa iş
     * başlamış, belki bitmiştir. Bu karar önce yalnızca yoklama ucunda
     * veriliyordu; kayıt paneli ise yalnızca ize bakıyordu ve toplu turda —
     * kimse kayıtları tek tek yoklamadığı için — biten tur beş dakika boyunca
     * "sıraya alındı" görünüyordu. Karar artık tek yerde.
     */
    public static function waiting(Credential $credential, ?PasswordRotation $latest): bool
    {
        $an = self::pending($credential);

        return $an !== null && ($latest === null || $latest->created_at?->timestamp < $an);
    }

    /**
     * "Sıraya alındı" izini koyar — parola turu da TUR KAPATMA da kullanır.
     *
     * Tur kapatma da kuyruğa gidiyordu ama iz koymuyordu: sayfa iş bitmeden
     * dönüyor, yarıda kalmış tur kartı sayfa yenilenene kadar ekranda
     * kalıyordu. İzle birlikte kayıt panelinde
     * ilerleme çubuğu çıkar, yoklar ve iş bitince sayfa kendini tazeler.
     */
    public static function markPending(Credential $credential): void
    {
        Cache::put(self::BEKLEYEN.$credential->getKey(), now()->timestamp, self::BEKLEME_OMRU);
    }

    /** İzi siler — tur kaydı görünür olunca işi biter. */
    public static function forgetPending(Credential $credential): void
    {
        Cache::forget(self::BEKLEYEN.$credential->getKey());
    }

    /**
     * Bu kayıt neden döndürülemez? Döndürülebiliyorsa null.
     *
     * TOPLU TUR ile TEK KAYIT AYRI SORUDUR.
     *
     * "Otomatik güncellemeye açık" kutusu tek kaydın elle güncellenmesini
     * ENGELLEMEZ: kullanıcı o kaydı açmış, gerekçesini yazmış ve düğmeye
     * basmıştır; kutunun adı "otomatik" iken elle başlatılan turu kapatmak
     * yanlıştı: elle tur otomatik değildir.
     *
     * Toplu turda kutu GEÇERLİDİR: orada kayıtlar tek tek açılıp
     * bakılmıyor, bir seferde çalıştırılıyor — otomatik tura en yakın şey
     * odur ve "beni toplu turlara katma" demenin yolu bu kutudur.
     */
    public function reasonToSkip(Credential $credential, User $actor, bool $toplu = false): ?string
    {
        if (! $actor->can('rotate', $credential)) {
            return 'Bu kayıtta parola güncelleme yetkiniz yok.';
        }

        return $toplu
            ? $this->registry->autoBlockedReason($credential)
            : $this->registry->blockedReason($credential);
    }

    /**
     * Ekranın "işaretlenebilir mi" sorusu.
     *
     * Çalışmayacak bir kutuyu göstermek, kullanıcıyı sonucu baştan belli bir
     * işleme davet etmektir.
     */
    public function canLaunch(Credential $credential, User $actor, bool $toplu = false): bool
    {
        return $this->reasonToSkip($credential, $actor, $toplu) === null;
    }

    /** Bildirimde en fazla kaç kayıt adıyla sayılır. */
    private const SUMMARY_LIMIT = 3;

    /**
     * Atlananları KISA bir satırda özetler.
     *
     * Sınır olmadan onlarca kaydın adı ve nedeni tek bildirime giriyordu:
     * ekranı kaplayan, dört saniye sonra kaybolan ve kimsenin okuyamadığı
     * bir metin. Birkaç örnek yeter; tamamı zaten listede, satırların
     * üzerinde yazıyor.
     *
     * @param  array<string, string>  $skipped
     */
    public static function summarize(array $skipped): string
    {
        $ilkler = Collection::make($skipped)
            ->take(self::SUMMARY_LIMIT)
            ->map(fn (string $reason, string $name): string => "{$name} ({$reason})")
            ->implode(', ');

        $kalan = count($skipped) - self::SUMMARY_LIMIT;

        return $kalan > 0 ? "{$ilkler} ve {$kalan} kayıt daha" : $ilkler;
    }
}
