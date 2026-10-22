<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ajax;

use App\Http\Controllers\Controller;
use App\Services\DeviceInterface\InterfaceSession;
use App\Services\DeviceInterface\InterfaceWatcher;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Vekilden geçen cihaz çağrılarının AYNASI.
 *
 * nginx her /sdk ve /rest/ isteğinin bir kopyasını buraya yollar (mirror). Kopya asıl
 * isteği BEKLETMEZ ve yanıtı yok sayılır: kayıt tutmak, kullanıcının
 * arayüzünü yavaşlatmamalı ve buradaki bir hata cihaz erişimini kesmemeli.
 *
 * Yetki burada yeniden sorulmaz — asıl istek zaten bekçiden geçti ve bu uç
 * nginx'in İÇ isteğidir, dışarıdan çağrılamaz.
 */
class InterfaceMonitorController extends Controller
{
    public function __construct(
        private InterfaceSession $oturumlar,
        private InterfaceWatcher $izleyici,
    ) {}

    public function __invoke(Request $request): Response
    {
        /*
         * ÇEREZSİZ İSTEĞE HİÇBİR ŞEY YAPILMAZ. Uç nginx'in iç isteği olsa da
         * Laravel bunu bilmez; dışarıdan çağrılırsa sahte kayıt yazdırmanın
         * yolu olmamalı. Kayıt yalnızca çerezin gösterdiği oturuma yazılır ve
         * o çerez HttpOnly, konak-özel ve kısa ömürlüdür.
         */
        $oturum = $this->session($request);

        if ($oturum !== null) {
            $this->izleyici->kaydet(
                $oturum,
                $request->getContent(),
                (string) $request->header('X-Original-Uri', ''),
                (string) $request->header('X-Original-Method', 'POST'),
            );
        }

        return response()->noContent();
    }

    /** @return array<string, mixed>|null */
    private function session(Request $request): ?array
    {
        $token = $request->cookie(InterfaceSession::COOKIE);
        $kayit = is_string($token) ? $this->oturumlar->bul($token) : null;

        if ($kayit === null) {
            return null;
        }

        $istenen = $this->oturumlar->basliktanKonak($request->header('X-Vsphere-Proxy'));

        if ($istenen !== null && strcasecmp($istenen, (string) $kayit['hedef']) !== 0) {
            return $this->oturumlar->konakIcin((int) $kayit['user_id'], $istenen);
        }

        return $kayit;
    }
}
