<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Credential;
use App\Services\Remote\RemoteSessionStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Açık bir uzak oturumu gösteren sarmalayıcı sayfa.
 *
 * Oturum jetonu önbellekte durur; süresi dolmuşsa ya da başkasına aitse
 * sayfa açılmaz. Yetki her istekte yeniden sorulur: aradan geçen sürede
 * kullanıcının yetkisi geri alınmış olabilir.
 */
class RemoteSessionPageController extends Controller
{
    public function __construct(private RemoteSessionStore $sessions) {}

    public function __invoke(Request $request, string $token): View
    {
        $session = $this->sessions->get($token);

        abort_if($session === null, Response::HTTP_NOT_FOUND, 'Oturum bulunamadı veya süresi doldu.');

        $credential = Credential::query()->find($session['credential_id']);

        abort_if($credential === null, Response::HTTP_NOT_FOUND);
        Gate::authorize('connect', $credential);

        return view('remote.session', [
            'session' => $session,
            'token' => $token,
        ]);
    }
}
