<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pasif hesabın AÇIK OTURUMU bir sonraki istekte biter.
 *
 * NEDEN VAR: durumu değiştirmek yalnızca "bir daha giremesin" demekti; o anda
 * açık olan oturum çalışmaya devam ediyordu. Erişimi kaldırılan biri
 * sekmesini kapatmadığı sürece kasada gezinmeye devam edebiliyordu.
 *
 * OTURUM SATIRLARINI SİLMEKLE BİRLİKTE çalışır (bkz. ClosesSessions), ikisi
 * aynı şeyin iki katmanıdır ve biri diğerini gereksiz kılmaz:
 *
 *  - Satır silme ANINDA etkilidir ama yalnızca veritabanı oturumunda
 *    mümkündür ve model olayına bağlıdır; ham bir SQL güncellemesi onu
 *    atlar.
 *  - Bu ara katman her istekte sorar: hangi sürücü kullanılırsa kullanılsın
 *    ve durum hangi yoldan değişirse değişsin, pasif hesap içeride kalamaz.
 *
 * Jeton uçları kapsam dışıdır: onlar oturum taşımıyor ve kendi yetki
 * denetiminden geçiyor.
 */
class EnsureUserIsActive
{
    /**
     * YÖNLENDİRME ANLAMAYAN uçlar.
     *
     * Konsol bekçisini nginx `auth_request` çağırıyor ve nginx yalnızca
     * 2xx/401/403 anlıyor — buradan dönen 302'yi "hata" sayıp 500 üretirdi.
     * O uç hesabın durumunu kendisi soruyor ve 403 dönüyor
     * (bkz. RemoteProxyGuardController).
     */
    private const ATLANAN = ['ajax.remote.guard'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (in_array($request->route()?->getName(), self::ATLANAN, true)) {
            return $next($request);
        }

        if ($user !== null && ! $user->isActive()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()
                ->route('login')
                ->withErrors(['email' => 'Hesabınız etkin değil. Lütfen yöneticinizle görüşün.']);
        }

        return $next($request);
    }
}
