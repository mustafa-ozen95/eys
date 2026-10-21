<?php

declare(strict_types=1);

namespace App\Services\Api;

use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * "Authorization: Bearer ..." başlığındaki jetonu kullanıcıya çevirir.
 *
 * Oturum çerezi KABUL EDİLMEZ: API ayrı bir kapıdır ve tarayıcıdan gelen bir
 * isteğin çerezle API'ye geçmesi, CSRF korumasının dışına çıkmak demektir.
 *
 * Jeton düz metin saklanmadığı için arama özet üzerinden yapılır. Karşılaştırma
 * veritabanı eşleşmesiyle olur; özet zaten sabit uzunlukta ve tahmin edilebilir
 * bir sır taşımaz.
 *
 * Reddedilen istek SESSİZ kalmaz: sebebi (süresi dolmuş, iptal edilmiş, adres
 * izinli değil) yanıtta ayırt edilmez ama jetonun son kullanım kaydı da
 * güncellenmez — kayda geçen yalnızca başarılı kullanımdır.
 */
class ApiTokenGuard
{
    public function __construct(private ApiTokenContext $context) {}

    public function __invoke(Request $request): ?User
    {
        $plain = $request->bearerToken();

        if ($plain === null || $plain === '') {
            return null;
        }

        $token = ApiToken::query()
            ->with('user')
            ->where('token_hash', hash('sha256', $plain))
            ->first();

        if ($token === null || ! $token->isUsable() || ! $token->allowsIp($request->ip())) {
            return null;
        }

        $user = $token->user;

        // Kapatılmış ya da askıya alınmış hesabın jetonu da çalışmaz.
        if ($user === null || ! $user->isActive()) {
            return null;
        }

        $token->forceFill([
            'last_used_at' => now(),
            'last_used_ip' => $request->ip(),
        ])->save();

        $this->context->set($token);

        return $user;
    }
}
