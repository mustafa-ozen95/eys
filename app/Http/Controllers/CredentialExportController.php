<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Credential\PasswordExportRequest;
use App\Services\Export\PasswordOutput;
use App\Support\RequestLimit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Seçilen kayıtların parolalarını Excel dosyası olarak verir.
 *
 * Kuralların tamamı ve gerekçesi PasswordOutput'nde. Burada duran tek karar
 * şudur: dosya TARAYICIYA İNER, sunucuda bir yere yazılmaz. Geçici bir
 * dizinde bırakılan çıktı, kimsenin bakmadığı bir yerde duran açık parola
 * listesi olurdu.
 *
 * Adres POST'tur: GET olsaydı tarayıcı geçmişine, sunucu erişim kaydına ve
 * bağlantı ön yüklemesine takılırdı.
 */
class CredentialExportController extends Controller implements HasMiddleware
{
    /** @return array<int, Middleware> */
    public static function middleware(): array
    {
        return [
            new Middleware('can:exportAny,App\Models\Credential'),
            ...RequestLimit::icin('export'),
        ];
    }

    public function __invoke(PasswordExportRequest $request, PasswordOutput $ciktisi): Response|RedirectResponse
    {
        $sonuc = $ciktisi->uret(
            $request->credentials(),
            $request->user(),
            $request->string('reason')->value(),
            $request->kapsamAdi(),
        );

        if ($sonuc['yazilan'] === 0) {
            return back()->with('error', 'Çıktıya alınacak parola yok: '.$this->filtered($sonuc).'.');
        }

        /*
         * Elenenler SESSİZ GEÇMEZ. Kullanıcı listedeki her kaydın dosyada
         * olduğunu sanmamalı; sayı ve SEBEBİ söylenir, hangileri olduğu
         * denetim kaydında zaten satır satır duruyor.
         */
        $ek = $this->filtered($sonuc);
        $ek = $ek === '' ? '' : '; '.$ek;

        return response($sonuc['icerik'])
            ->header('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->header('Content-Disposition', 'attachment; filename="'.$sonuc['ad'].'"')
            ->header('X-Eys-Digest', $sonuc['yazilan'].' kayıt yazıldı'.$ek)
            /* Açık parola listesi hiçbir ara sunucuda ve tarayıcıda durmamalı. */
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, private')
            ->header('Pragma', 'no-cache');
    }

    /**
     * Elenenleri SEBEBİYLE anlatır.
     *
     * "Atlandı" demek yetmiyor: yetkinin yetmemesi ile kaydın kasada zaten
     * parolasız durması bambaşka iki durum ve kullanıcının yapacağı şey de
     * ikisinde başka.
     *
     * @param  array{yazilan: int, yetkisiz: int, parolasiz: int}  $sonuc
     */
    private function filtered(array $sonuc): string
    {
        $parcalar = [];

        if ($sonuc['parolasiz'] > 0) {
            $parcalar[] = $sonuc['parolasiz'].' kaydın kasada parolası yok';
        }

        if ($sonuc['yetkisiz'] > 0) {
            $parcalar[] = $sonuc['yetkisiz'].' kaydın parolasını görme yetkiniz yok';
        }

        return implode(', ', $parcalar);
    }
}
