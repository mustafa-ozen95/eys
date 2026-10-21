<?php

declare(strict_types=1);

namespace App\Services\Rotation\Drivers;

use App\Models\Credential;
use App\Services\Rotation\Contracts\PasswordDriver;
use App\Services\Rotation\RotationException;
use App\Services\Rotation\RotationSession;
use Illuminate\Support\Facades\Http;

/**
 * mailcow alan yöneticisi (domain admin) parolasını API ile değiştirir.
 *
 * ÖLÇÜLDÜ (mailcow OpenAPI şeması, data/web/api/openapi.yaml):
 *  - Kimlik doğrulama: `X-API-Key` başlığı. Kullanıcı adı/parola DEĞİL.
 *  - POST /api/v1/edit/domain-admin
 *      {"items":["kullanici"],"attr":{"password":"...","password2":"..."}}
 *  - GET  /api/v1/get/domain-admin/all      (hesap var mı)
 *  - GET  /api/v1/get/status/version        (anahtar çalışıyor mu)
 *  - Yanıt: [{"type":"success|danger|error", ...}] — HTTP 200 tek başına
 *    BAŞARI DEĞİLDİR, gövdedeki type'a bakılır.
 *
 * KURTARMA HESABI AÇMAZ. Değiştiren kimlik (API anahtarı) ile değiştirilen
 * parola ayrı şeyler; anahtar bozulmadığı için kapı hep açık kalır. Geçici
 * bir yönetici açmak, olmayan bir riske karşılık gerçek bir yetki bırakmak
 * olurdu.
 *
 * SÜPER YÖNETİCİ (mailcow "admin" hesabı) BU YOLLA DEĞİŞTİRİLEMEZ: API yalnızca
 * domain-admin uçlarını açıyor, süper yönetici için bir uç YOK. O hesap elle
 * değiştirilir.
 */
class MailcowPasswordDriver implements PasswordDriver
{
    public const KEY = 'mailcow';

    public function __construct(private int $timeout = 15) {}

    public function supportsRecovery(): bool
    {
        return false;
    }

    /**
     * mailcow'da kimlik SINAMA ucu yok; yeni parolanın çalıştığını
     * kanıtlayamayız. Giriş formuna POST atmak mümkün ama o belgelenmiş bir
     * arayüz değil ve sürüm değişince sessizce bozulur — tahminle
     * "doğrulandı" demektense doğrulamadığımızı söyleriz.
     */
    public function canVerify(): bool
    {
        return false;
    }

    public function unsupportedReason(Credential $credential): ?string
    {
        return match (true) {
            blank($credential->url) => 'Panel adresi (bağlantı adresi) tanımlı değil.',
            blank($credential->username) => 'Alan yöneticisi kullanıcı adı tanımlı değil.',
            blank($credential->rotation_password) => 'Güncelleme parolası alanına mailcow API anahtarı girilmeli.',
            default => null,
        };
    }

    /**
     * Oturum yok; API anahtarı taşınır.
     *
     * Anahtarın gerçekten çalıştığı BURADA sınanır: geçersiz bir anahtarla
     * yola çıkıp parola değiştirme adımında patlamak, neyin yanlış olduğunu
     * gizlerdi.
     */
    public function open(Credential $credential, string $username, string $password): RotationSession
    {
        $response = $this->client($credential, $password)->get('/api/v1/get/status/version');

        if ($response->failed()) {
            throw new RotationException('mailcow API anahtarı kabul edilmedi ('.$response->status().').');
        }

        return new RotationSession($credential, $username, $password, static fn () => null);
    }

    public function changePassword(RotationSession $session, string $username, string $newPassword): void
    {
        /** @var Credential $credential */
        $credential = $session->handle;

        $response = $this->client($credential, $session->password)->post('/api/v1/edit/domain-admin', [
            'items' => [$username],
            'attr' => [
                'password' => $newPassword,
                'password2' => $newPassword,
            ],
        ]);

        if ($response->failed()) {
            throw new RotationException('mailcow isteği reddedildi ('.$response->status().').');
        }

        /*
         * mailcow hatalarını da HTTP 200 ile döndürür; gövdedeki type
         * okunmazsa başarısız bir değişiklik başarılı sayılırdı.
         */
        $type = data_get($response->json(), '0.type', data_get($response->json(), 'type'));

        if ($type !== 'success') {
            throw new RotationException('mailcow parolayı kabul etmedi (yanıt: '.(is_string($type) ? $type : 'bilinmiyor').').');
        }
    }

    public function createRecovery(RotationSession $session, string $username, string $password): void
    {
        throw new RotationException('mailcow sürücüsü kurtarma hesabı açmaz.');
    }

    public function deleteRecovery(RotationSession $session, string $username): void
    {
        throw new RotationException('mailcow sürücüsü kurtarma hesabı açmaz.');
    }

    /** Kimlik sınanamaz; bkz. canVerify(). */
    public function verify(Credential $credential, string $username, string $password): bool
    {
        return false;
    }

    private function client(Credential $credential, string $apiKey)
    {
        return Http::withHeaders(['X-API-Key' => $apiKey])
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeout)
            ->baseUrl(rtrim((string) $credential->url, '/'));
    }
}
