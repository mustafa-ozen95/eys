<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ActivityAction;
use App\Http\Requests\Credential\PortalSessionRequest;
use App\Models\Credential;
use App\Services\ActivityRecorder;
use App\Services\Portal\PortalLogin;
use App\Support\RequestLimit;
use Illuminate\Http\Response;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Portal oturumu açar.
 *
 * PAROLA KULLANICIYA GÖSTERİLMEZ. Bunun yerine sunucu, alanları doldurulmuş
 * bir giriş formu taşıyan geçici bir sayfa üretir; sayfa açılır açılmaz kendini
 * hedef siteye gönderir ve oradan ayrılır. Parola, kullanıcının panosuna ya da
 * ekranına hiç uğramadan doğrudan sitenin giriş alanına gider.
 *
 * NEDEN BÖYLE: tarayıcı, başka bir kökendeki sayfanın alanlarını JS ile
 * doldurmaya İZİN VERMEZ (aynı köken politikası). Ama çapraz kökene FORM
 * GÖNDERİMİNE izin verir. Tek yol budur.
 *
 * NEREDE ÇALIŞMAZ — açıkça: giriş formunda CSRF jetonu isteyen ya da iki
 * adımlı doğrulama kullanan sitelerde çalışmaz. O sitelerde kayıt elle
 * kullanılır; bu yüzden özellik kayıt başına açılıyor.
 *
 * Yalnızca POST: adres çubuğuna yazılamaz, tarayıcı geçmişine ve sunucu
 * erişim kaydına düşmez.
 */
class PortalSessionController extends Controller implements HasMiddleware
{
    public function __construct(private ActivityRecorder $activity) {}

    /** @return array<int, Middleware> */
    public static function middleware(): array
    {
        return [
            new Middleware('can:session,credential'),
            ...RequestLimit::icin('portal'),
        ];
    }

    public function __invoke(PortalSessionRequest $request, Credential $credential): Response
    {
        abort_unless($credential->can_open_portal_session, 404);

        /*
         * Form ÖNCE kurulur, kayda sonra geçilir: vCenter'ın kimlik sağlayıcı
         * adresi ancak burada alınıyor ve cihaz kapalıysa alınamıyor.
         * Sıra ters olsaydı hiç açılmamış bir oturum kayda düşerdi.
         */
        $login = PortalLogin::for($credential);

        abort_if(
            $login === null,
            503,
            "{$credential->name}: cihazın giriş adresi alınamadı. Cihaz kapalı ya da erişilemiyor olabilir.",
        );

        $this->activity->record(
            ActivityAction::PortalSession,
            "{$credential->group->name} · {$credential->name} · portal oturumu açıldı ({$credential->username})",
            $credential,
            $request->string('reason')->value(),
        );

        return response()
            ->view('credentials.portal-session', [
                'credential' => $credential,
                'login' => $login,
            ])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, private')
            ->header('Pragma', 'no-cache')

            /*
             * Hedef siteye HANGİ sayfadan gelindiği sızmasın: panelin adresi
             * ve kayıt kimliği üçüncü tarafın kaydına düşmemeli.
             */
            ->header('Referrer-Policy', 'no-referrer');
    }
}
