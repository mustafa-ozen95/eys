<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * API'ye gelen HER isteği adrese göre sınırlar — kimliği doğrulanmadan önce.
 *
 * Jeton başına sınır ("throttle:api") kimlik doğrulamasından SONRA çalışmak
 * zorunda: jeton ancak o zaman bilinir. Ama "auth:api" geçersiz jetonu 401 ile
 * bitirdiği için o sınır geçersiz jetonda hiç çalışmaz; ayrı bir sınır
 * olmasa geçersiz jetonla yapılan çağrı SINIRSIZ kalırdı.
 *
 * Rotaya "throttle:..." yazarak önüne geçilemiyor: Laravel ara katmanları
 * önceliğe göre sıralıyor ve Authenticate, ThrottleRequests'ten önce geliyor.
 * Bu yüzden sınır kendi sınıfında ve "api" grubunun başında duruyor —
 * öncelik listesinde olmadığı için yeri korunur.
 *
 * Sınır cömerttir: geçerli jetonlu istekler de bu kapıdan geçiyor ve aynı
 * adresten çalışan birden çok istemciyi engellememeli.
 */
class ThrottleApiClients
{
    private const KEY = 'api-guest:';

    public function __construct(private RateLimiter $limiter) {}

    public function handle(Request $request, Closure $next): Response
    {
        $limit = (int) config('api.guest_rate_limit');

        if ($limit <= 0) {
            return $next($request);
        }

        $key = self::KEY.sha1((string) $request->ip());

        if ($this->limiter->tooManyAttempts($key, $limit)) {
            return response()->json(
                ['message' => 'Çok fazla istek. Bir dakika sonra tekrar deneyin.'],
                Response::HTTP_TOO_MANY_REQUESTS,
                ['Retry-After' => $this->limiter->availableIn($key)],
            );
        }

        $this->limiter->hit($key);

        return $next($request);
    }
}
