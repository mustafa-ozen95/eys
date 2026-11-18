<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ajax;

use App\Enums\ActivityAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Remote\RemoteSessionRequest;
use App\Models\Credential;
use App\Services\ActivityRecorder;
use App\Services\OsUpdate\OsUpdateTargets;
use App\Services\Remote\RemoteSessionOpener;
use App\Services\Remote\RemoteSessionStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Konsol oturumunu açar, canlı tutar ve kapatır.
 */
class RemoteSessionController extends Controller
{
    public function __construct(
        private RemoteSessionOpener $opener,
        private RemoteSessionStore $sessions,
        private ActivityRecorder $activity,
        private OsUpdateTargets $targets,
    ) {}

    public function connect(RemoteSessionRequest $request): JsonResponse
    {
        $credential = $request->credential();

        // Bağlanma yetkisi görüntüleme yetkisinden AYRIDIR.
        Gate::authorize('connect', $credential);

        $session = $this->opener->open($credential, $request->user()->id);

        $this->activity->record(
            ActivityAction::ConsoleOpened,
            "Konsol açıldı: {$session['label']} ({$session['protocol']})",
            $credential,
            $request->string('reason')->value(),
        );

        return response()->json([
            'token' => $session['token'],
            'label' => $session['label'],
            'protocol' => $session['protocol'],
            // Oturum sarmalayıcı sayfası; iframe'i o kurar.
            'url' => route('remote.session', ['token' => $session['token']]),
        ]);
    }

    /**
     * OTURUM SAHİBİNDEN BAŞKASI CANLI TUTAMAZ.
     *
     * Tek kimlik jetonun kendisi olsaydı, yetkisi geri alınmış birinin açık
     * kalan penceresi ttyd sürecini süresiz canlı tutabilirdi.
     *
     * Yanıt 403 DEĞİL "alive: false": ön yüz oturumu bitmiş sayıp kapatır ve
     * jetonun var olup olmadığı da söylenmemiş olur.
     */
    public function keepAlive(Request $request): JsonResponse
    {
        $jeton = $request->string('token')->toString();
        $ayakta = $this->isOwner($request, $jeton) && $this->sessions->touch($jeton);

        /* Denetim izi de ses veriyor; zamanlayıcı bunu "hâlâ açık" sayar. */
        if ($ayakta) {
            $this->opener->izeDokun($jeton);
        }

        /*
         * Oturum sayfası da bu ucu yokluyor: makinede kurulum başlarsa
         * konsoldaki kişi uyarıyı açık pencerede görür.
         */
        $kayit = $ayakta ? Credential::query()->find($this->sessions->get($jeton)['credential_id'] ?? null) : null;

        return response()->json([
            'alive' => $ayakta,
            'notice' => $kayit === null ? null : $this->targets->kurulumUyarisi($kayit),
        ]);
    }

    /**
     * OTURUMU YALNIZCA SAHİBİ KAPATIR.
     *
     * Sahibi olmayan için oturum YOKTUR: 404 döner, "var ama senin değil"
     * denmez.
     */
    public function disconnect(Request $request): JsonResponse
    {
        $jeton = $request->string('token')->toString();

        abort_unless($this->isOwner($request, $jeton), 404);

        $session = $this->opener->close($jeton);

        /*
         * Kapatma da kayda geçer: bir oturumun ne zaman bittiği, açılışı
         * kadar önemlidir.
         */
        if ($session !== null) {
            $this->activity->record(
                ActivityAction::ConsoleClosed,
                "Konsol kapatıldı: {$session['label']} ({$session['protocol']})",
                $session['credential'],
            );
        }

        return response()->json(['closed' => true]);
    }

    /** Bu jeton isteği yapan kişinin oturumuna mı ait? */
    private function isOwner(Request $request, string $jeton): bool
    {
        $oturum = $this->sessions->get($jeton);

        return $oturum !== null && (int) ($oturum['user_id'] ?? 0) === (int) $request->user()->id;
    }
}
