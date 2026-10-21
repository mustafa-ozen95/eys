<?php

declare(strict_types=1);

namespace App\Services\Remote;

use App\Enums\AccessType;
use Illuminate\Support\Facades\Cache;

/**
 * Açık uzak oturumların kaydı.
 *
 * Oturumlar kısa ömürlüdür ve sunucu yeniden başlatılınca anlamlarını yitirir;
 * bu yüzden veritabanında değil önbellekte tutulur.
 */
class RemoteSessionStore
{
    private function key(string $token): string
    {
        return config('remote.session.cache_prefix').$token;
    }

    private function portKey(int $port): string
    {
        return config('remote.session.cache_prefix')."port:{$port}";
    }

    /**
     * @param  array<string, mixed>  $session
     */
    public function put(string $token, array $session): void
    {
        Cache::put($this->key($token), $session, (int) config('remote.session.ttl'));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $token): ?array
    {
        return Cache::get($this->key($token));
    }

    public function touch(string $token): bool
    {
        $session = $this->get($token);

        if ($session === null) {
            return false;
        }

        $this->put($token, $session);

        if (isset($session['port'])) {
            Cache::put($this->portKey($session['port']), $token, (int) config('remote.session.ttl'));
        }

        /*
         * Kullanıcının listesi de tazelenir: yalnızca açılışta yazılsaydı
         * süresi (ttl) dolunca canlı tutulan oturum bile proxy'de sahipsiz
         * kalırdı.
         */
        $protocol = AccessType::tryFrom((string) ($session['protocol'] ?? ''));

        if ($protocol !== null && isset($session['user_id'])) {
            $this->rememberForUser((int) $session['user_id'], $protocol, $token);
        }

        return true;
    }

    public function forget(string $token): void
    {
        Cache::forget($this->key($token));
    }

    /**
     * Portu bu oturuma ayırır; port zaten kullanımdaysa false döner.
     */
    public function reservePort(int $port, string $token): bool
    {
        return Cache::add($this->portKey($port), $token, (int) config('remote.session.ttl'));
    }

    public function releasePort(int $port): void
    {
        Cache::forget($this->portKey($port));
    }

    /**
     * Bir porta ayrılmış oturum. Proxy bekçisi bunu kullanır.
     *
     * @return array<string, mixed>|null
     */
    public function forPort(int $port): ?array
    {
        $token = Cache::get($this->portKey($port));

        return is_string($token) ? $this->get($token) : null;
    }

    /**
     * Kullanıcının açık oturumlarından biri (en yenisi). Guacamole proxy'sinde
     * port yoktur; oturum kullanıcı ve protokol üzerinden bulunur.
     *
     * AYNI ANDA BİRDEN ÇOK OTURUM. Kullanıcı başına TEK jeton tutulsaydı
     * ikinci oturum birincinin yerine geçer, ikincisi kapanınca birincinin
     * proxy istekleri sahipsiz kalıp 403 alırdı.
     *
     * @return array<string, mixed>|null
     */
    public function forUser(int $userId, AccessType $protocol): ?array
    {
        foreach (array_reverse($this->userTokens($userId, $protocol)) as $token) {
            $session = $this->get($token);

            if ($session !== null) {
                return $session;
            }
        }

        return null;
    }

    public function rememberForUser(int $userId, AccessType $protocol, string $token): void
    {
        $tokens = array_values(array_filter(
            $this->userTokens($userId, $protocol),
            fn (string $eski): bool => $eski !== $token && $this->get($eski) !== null,
        ));
        $tokens[] = $token;

        Cache::put($this->userKey($userId, $protocol), $tokens, (int) config('remote.session.ttl'));
    }

    /**
     * Önbellekteki jetonlar, eskiden yeniye. Eski biçim tek jeton (dizgi)
     * idi; yayın anında açık oturum kopmasın diye o da okunur.
     *
     * @return list<string>
     */
    private function userTokens(int $userId, AccessType $protocol): array
    {
        $deger = Cache::get($this->userKey($userId, $protocol));

        if (is_string($deger)) {
            return [$deger];
        }

        return is_array($deger) ? array_values(array_filter($deger, 'is_string')) : [];
    }

    private function userKey(int $userId, AccessType $protocol): string
    {
        return config('remote.session.cache_prefix')."user:{$userId}:{$protocol->value}";
    }
}
