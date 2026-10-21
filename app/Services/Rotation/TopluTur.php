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
class TopluTur
{
    /**
     * Kayıt durumlarının ekrandaki adları. Sıra, göstergedeki lejantın
     * sırasıdır. Ön yüz aynı anahtarları kullanır (toplu-ilerleme.js).
     */
    public const ETIKETLER = [
        'basarili' => 'Başarılı',
        'suruyor' => 'Sürüyor',
        'bekliyor' => 'Sırada',
        'dogrulanamadi' => 'Doğrulanamadı',
        'basarisiz' => 'Başarısız',
        'baslamadi' => 'Başlamadı',
    ];

    private const PARTI = 'erisim:toplu-tur:';

    private const SON = 'erisim:toplu-tur-son:';

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
     *     calisiyor: bool, toplam: int, biten: int, yuzde: int,
     *     basarili: int, basarisiz: int, dogrulanamadi: int, baslamadi: int,
     *     bekleyen: int, suren: ?string, metin: string,
     *     sorunlu: list<array{ad: string, durum: string}>,
     *     kayitlar: list<array{ad: string, durum: string}>
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

        $sayilar = ['basarili' => 0, 'basarisiz' => 0, 'dogrulanamadi' => 0, 'baslamadi' => 0, 'bekleyen' => 0];
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
                    $sayilar['baslamadi']++;
                    $sorunlu[] = ['ad' => $ad, 'durum' => 'Başlamadı'];
                    $kayitlar[] = ['ad' => $ad, 'durum' => 'baslamadi'];
                } else {
                    $sayilar['bekleyen']++;
                    $kayitlar[] = ['ad' => $ad, 'durum' => 'bekliyor'];
                }

                continue;
            }

            match ($tur->status) {
                RotationStatus::Running => $suren ??= $ad,
                RotationStatus::Succeeded => $sayilar['basarili']++,
                RotationStatus::Unverified => $sayilar['dogrulanamadi']++,
                default => $sayilar['basarisiz']++,
            };

            $kayitlar[] = ['ad' => $ad, 'durum' => match (true) {
                $tur->status === RotationStatus::Running && $zamanAsimi => 'baslamadi',
                $tur->status === RotationStatus::Running => 'suruyor',
                $tur->status === RotationStatus::Succeeded => 'basarili',
                $tur->status === RotationStatus::Unverified => 'dogrulanamadi',
                default => 'basarisiz',
            }];

            if ($tur->status === RotationStatus::Running && $zamanAsimi) {
                /* Süre dolduğu hâlde "sürüyor" diyen tur: işçi ölmüş olabilir. */
                $sayilar['baslamadi']++;
                $sorunlu[] = ['ad' => $ad, 'durum' => 'Yanıt yok'];
            } elseif (! in_array($tur->status, [RotationStatus::Running, RotationStatus::Succeeded], true)) {
                $sorunlu[] = ['ad' => $ad, 'durum' => $tur->status->label()];
            }
        }

        $toplam = count($kimlikler);
        $biten = $sayilar['basarili'] + $sayilar['basarisiz'] + $sayilar['dogrulanamadi'] + $sayilar['baslamadi'];
        $calisiyor = $biten < $toplam;

        return [
            'calisiyor' => $calisiyor,
            'toplam' => $toplam,
            'biten' => $biten,
            'yuzde' => $toplam === 0 ? 100 : (int) floor($biten / $toplam * 100),
            ...$sayilar,
            'suren' => $calisiyor ? $suren : null,
            'metin' => self::metin($calisiyor, $suren, $sayilar),
            'sorunlu' => $sorunlu,
            'kayitlar' => $kayitlar,
        ];
    }

    /** @param  array<string, int>  $sayilar */
    private static function metin(bool $calisiyor, ?string $suren, array $sayilar): string
    {
        if ($calisiyor) {
            return $suren !== null ? "Güncelleniyor: {$suren}" : 'Sıradaki kayıt bekleniyor…';
        }

        $sorun = $sayilar['basarisiz'] + $sayilar['dogrulanamadi'] + $sayilar['baslamadi'];

        return $sorun === 0 ? 'Tamamlandı, hepsi başarılı.' : "Tamamlandı, {$sorun} kayıtta sorun var.";
    }
}
