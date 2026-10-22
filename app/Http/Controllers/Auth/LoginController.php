<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\ActivityRecorder;
use App\Services\Identity\CenterClient;
use App\Services\Identity\SharedLogout;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Yetkilendirme: giriş akışı. Oluşturma uçları `guest`, çıkış ucu `auth`
 * ara katmanı altındadır; kayıt olma ekranı YOKTUR — hesabı yönetici açar.
 */
class LoginController extends Controller
{
    /**
     * Giriş ekranı.
     *
     * Merkezin açık olup olmadığı KARAR olarak buradan geçer; Blade config
     * okumaz. Soruyu `CenterClient` yanıtlar: ayar tek başına yetmiyor,
     * istemci bilgileri de gerekiyor.
     */
    public function create(CenterClient $merkez): View
    {
        return view('auth.login', [
            'merkezAcik' => $merkez->acikMi(),
            'merkezAd' => config('identity.name'),
        ]);
    }

    public function store(LoginRequest $request, ActivityRecorder $activity): RedirectResponse
    {
        $request->authenticate($activity);
        $request->session()->regenerate();

        return redirect()->intended(route('credentials.index'));
    }

    /**
     * ORTAK ÇIKIŞ: merkezden giren kişinin çıkışı merkeze bildirilir; merkez
     * kendi oturumunu ve öteki panelleri kapatır (bkz. SharedLogout). Bu
     * paneldeki öteki oturumları da (başka cihaz) aynı an kapatır.
     *
     * ZAMAN AŞIMI ORTAK DEĞİL: ekran kilidinin çıkışı (süre doldu) yalnız bu
     * oturumu kapatır; bir sekmede süresi dolan kişi öteki panellerden atılmaz.
     */
    public function destroy(Request $request, CenterClient $merkez): RedirectResponse
    {
        $bag = $request->user() !== null && ! SharedLogout::zamanAsimiMi($request) ? SharedLogout::bag($request->user()) : null;

        if ($bag !== null) {
            SharedLogout::isle($bag, SharedLogout::simdiMs());

            if ($merkez->acikMi()) {
                rescue(fn () => $merkez->oturumKapandi($bag->subject));
            }
        }

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
