<?php

declare(strict_types=1);

namespace App\Services\Disa;

use App\Enums\ActivityAction;
use App\Models\Credential;
use App\Models\User;
use App\Services\ActivityRecorder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Kasadan toplu parola çıktısı.
 *
 * BU BİR İSTİSNADIR ve bedeli ödenir. Panelin kuralı parolayı ekranda
 * basmamaktır; tek tek gösterim zaten ayrı yetki isteyip kayda geçiyor
 * (CredentialPasswordController). Toplu çıktı aynı kuralların hepsini taşır,
 * üstüne bir tane daha ekler: dosya panelin dışına çıkıyor ve oradan sonrası
 * bizim elimizde değil.
 *
 *  - Kapı ayrı bir yetkidir ('export'); "parolayı görebilmek" tek başına
 *    yetmez, ikisi birden gerekir.
 *  - Her satır için yetki AYRI sorulur: göremediği kayıt dosyaya girmez ve
 *    kaç tanesinin elendiği kullanıcıya söylenir.
 *  - Gerekçe zorunludur ve kayda yazılır.
 *  - Çıktı başına TEK denetim satırı yazılır ("12 adet parola dosyaya
 *    çıkarıldı"); kayıt başına satır kayıtlar ekranını okunmaz kılardı.
 *    "Hangi parola dışarı çıktı" sorusunun
 *    cevabı kaybolmaz: dosyaya giren her kayıt o satırın ayrıntısına yazılır
 *    (parola_ciktisi_satirlari) ve kaydın kendi geçmişinde de görünür.
 */
final class ParolaCiktisi
{
    public function __construct(private ActivityRecorder $activity) {}

    /**
     * @param  Collection<int, Credential>  $kayitlar
     * @return array{icerik: string, ad: string, yazilan: int, yetkisiz: int, parolasiz: int}
     */
    public function uret(Collection $kayitlar, User $kullanici, string $gerekce, string $kapsam = 'Kasa'): array
    {
        /*
         * İKİ ELEME SEBEBİ AYRI SAYILIR. "Atlandı" demek yetmiyor: kullanıcı
         * yetkisinin mi yetmediğini yoksa o kayıtların kasada zaten parolasız
         * mı durduğunu bilmeli — ikisi bambaşka iki durum ve ikisi için
         * yapılacak şey de başka.
         */
        $yetkisiz = $kayitlar->reject(fn (Credential $c): bool => $kullanici->can('export', $c));
        $parolasiz = $kayitlar
            ->filter(fn (Credential $c): bool => $kullanici->can('export', $c))
            ->reject(fn (Credential $c): bool => filled($c->password));

        $yazilacak = $kayitlar->filter(
            fn (Credential $credential): bool => $kullanici->can('export', $credential)
                && filled($credential->password)
        );

        $satirlar = [];

        foreach ($yazilacak as $credential) {
            $satirlar[] = [
                (string) $credential->group?->name,
                (string) $credential->name,
                $credential->access_type?->label() ?? '',
                $credential->operating_system?->label() ?? '',
                (string) $credential->host,
                (string) $credential->username,
                (string) $credential->password,
                (string) $credential->url,
                $credential->updated_at?->format('d.m.Y H:i') ?? '',
            ];
        }

        /* Özet satır ve ayrıntısı BİRLİKTE yazılır: ayrıntısız bir çıktı satırı "ne gitti" sorusunu cevapsız bırakırdı. */
        if ($yazilacak->isNotEmpty()) {
            DB::transaction(function () use ($yazilacak, $kullanici, $gerekce, $kapsam): void {
                /* Gerekçe kendi sütununa yazılır; özet satırına sıkıştırılmaz. */
                $log = $this->activity->record(
                    ActivityAction::Exported,
                    "{$yazilacak->count()} adet parola dosyaya çıkarıldı ({$kapsam})",
                    reason: $gerekce,
                    actor: $kullanici,
                );

                $log->ciktiSatirlari()->createMany($yazilacak->map(fn (Credential $c): array => [
                    'credential_id' => $c->getKey(),
                    'grup' => (string) $c->group?->name,
                    'kayit' => (string) $c->name,
                ])->values()->all());
            });
        }

        return [
            'icerik' => CalismaKitabi::uret($kapsam, [
                'Grup', 'Kayıt', 'Erişim türü', 'Platform', 'Adres',
                'Kullanıcı', 'Parola', 'Bağlantı', 'Son güncelleme',
            ], $satirlar),
            'ad' => 'kasa-'.now()->format('Y-m-d-Hi').'.xlsx',
            'yazilan' => count($satirlar),
            'yetkisiz' => $yetkisiz->count(),
            'parolasiz' => $parolasiz->count(),
        ];
    }
}
