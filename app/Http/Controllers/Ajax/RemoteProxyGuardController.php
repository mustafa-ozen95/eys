<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ajax;

use App\Enums\AccessType;
use App\Http\Controllers\Controller;
use App\Models\Credential;
use App\Services\Remote\RemoteSessionStore;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Uzak oturum proxy'lerinin bekçisi.
 *
 * Yetkilendirme: kontrolü kendisi yapar. "auth" ara katmanının dışındadır
 * çünkü nginx auth_request yönlendirmeyi (302) anlamaz; kimliksiz isteğe
 * doğrudan 401 döner.
 *
 * nginx, /ssh-proxy/ ve /rdp-proxy/ altındaki her isteği önce buraya sorar
 * (auth_request). Bunsuz ttyd portları kimlik doğrulaması olmadan
 * erişilebilirdi: açık bir oturum sırasında port aralığını tarayan biri
 * terminale düşebilirdi.
 *
 * Yetki her istekte yeniden sorulur; oturum sahibi değişmez ve aradan geçen
 * sürede kullanıcının bağlanma yetkisi geri alınmış olabilir.
 *
 * HESABIN ETKİN OLDUĞU DA burada sorulur. "auth" katmanının dışında olduğumuz
 * için EnsureUserIsActive bu yola hiç uğramıyor: pasifleştirilmiş bir kullanıcı
 * panelde her sayfada oturumdan atılırken, AÇIK DURAN terminali çalışmaya devam
 * ediyordu. Hesabı kapatmak anında etkili olmalı.
 */
class RemoteProxyGuardController extends Controller
{
    /**
     * Guacamole vekilinden verilen türler.
     *
     * /rdp-proxy yolu adını RDP'den alıyor ama altından GUACAMOLE ile açılan
     * her oturum geçiyor — VNC de. Burada yalnızca RDP sorulduğu sürece VNC
     * oturumu sahibine bile 403 görünürdü: oturum kullanıcının üstünde
     * duruyor, ama başka bir anahtarda.
     */
    private const VEKILDEN_VERILENLER = [AccessType::Rdp, AccessType::Vnc];

    public function __construct(private RemoteSessionStore $sessions) {}

    public function __invoke(Request $request): Response
    {
        // nginx auth_request yalnızca 2xx/401/403 anlar; başka her yanıtı
        // 500'e çevirir. Bu yüzden bu uç login'e yönlendirmez, 401 döner.
        if ($request->user() === null) {
            return response()->noContent(Response::HTTP_UNAUTHORIZED);
        }

        if (! $request->user()->isActive()) {
            return response()->noContent(Response::HTTP_FORBIDDEN);
        }

        $port = $this->requestedPort($request);

        $session = $port === null
            ? $this->vekilOturumu($request->user()->id)
            : $this->sessions->forPort($port);

        if ($session === null || $session['user_id'] !== $request->user()->id) {
            return response()->noContent(Response::HTTP_FORBIDDEN);
        }

        $credential = Credential::query()->find($session['credential_id']);

        if ($credential === null || $request->user()->cannot('connect', $credential)) {
            return response()->noContent(Response::HTTP_FORBIDDEN);
        }

        return response()->noContent();
    }

    /**
     * Kullanıcının vekilden verilen açık oturumu; hangi türse.
     *
     * @return array<string, mixed>|null
     */
    private function vekilOturumu(int $userId): ?array
    {
        foreach (self::VEKILDEN_VERILENLER as $tur) {
            $oturum = $this->sessions->forUser($userId, $tur);

            if ($oturum !== null) {
                return $oturum;
            }
        }

        return null;
    }

    /**
     * Hangi ttyd portunun sorulduğu.
     *
     * nginx alt isteğinde sorgu parametresi güvenilir biçimde taşınmıyor;
     * bunun yerine istemcinin özgün adresi X-Original-Uri başlığıyla
     * geliyor ve port oradan okunuyor. RDP proxy'sinde port yoktur.
     */
    private function requestedPort(Request $request): ?int
    {
        if ($request->filled('port')) {
            return $request->integer('port');
        }

        $original = $request->header('X-Original-Uri', '');

        return preg_match('#^/ssh-proxy/(\d+)#', $original, $eslesme) === 1
            ? (int) $eslesme[1]
            : null;
    }
}
