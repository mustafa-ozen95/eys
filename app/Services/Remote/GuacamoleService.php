<?php

declare(strict_types=1);

namespace App\Services\Remote;

use App\Enums\AccessType;
use App\Exceptions\RemoteSessionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Str;

/**
 * Guacamole üzerinden RDP ya da VNC oturumu açar.
 *
 * Guacamole'un json-auth eklentisi, imzalanmış ve şifrelenmiş bir JSON
 * yükünü kabul eder: önce yükün HMAC-SHA256 imzası başa eklenir, sonra
 * tamamı AES-128-CBC ile şifrelenip base64'lenir. Eklentinin beklediği biçim
 * budur; sıfır IV kullanımı bizim tercihimiz değil, protokolün gereği.
 *
 * Bağlantı parametreleri config/remote.php'de; koda gömülü değer yoktur.
 */
class GuacamoleService
{
    private const CIPHER = 'aes-128-cbc';

    private const SESSION_TTL_MINUTES = 10;

    public function __construct(private Factory $http) {}

    /**
     * @return array{path: string, token: string}
     */
    public function open(RemoteTarget $target): array
    {
        $secret = (string) config('remote.guacamole.secret');

        if ($secret === '') {
            throw new RemoteSessionException('Guacamole gizli anahtarı tanımlı değil.');
        }

        $connectionName = Str::slug("{$target->label}-".Str::random(8));
        $payload = $this->payload($target, $connectionName);

        $response = $this->http
            ->asForm()
            ->timeout((int) config('remote.guacamole.timeout', 10))
            ->throw()
            ->post(rtrim((string) config('remote.guacamole.url'), '/').'/api/tokens', [
                'data' => $this->encrypt($payload, $secret),
            ])
            ->json();

        $token = $response['authToken'] ?? null;
        $dataSource = $response['dataSource'] ?? 'json';

        if (! is_string($token) || $token === '') {
            throw new RemoteSessionException('Guacamole oturum anahtarı alınamadı.');
        }

        return [
            'path' => $this->clientPath($connectionName, $dataSource, $token),
            'token' => $token,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(RemoteTarget $target, string $connectionName): array
    {
        return [
            'username' => (string) config('remote.guacamole.username'),
            'expires' => (int) (now()->addMinutes(self::SESSION_TTL_MINUTES)->timestamp * 1000),
            'connections' => [
                $connectionName => [
                    'protocol' => $target->protocol === AccessType::Vnc ? 'vnc' : 'rdp',
                    'parameters' => $target->protocol === AccessType::Vnc
                        ? $this->vncParametreleri($target)
                        : $this->rdpParametreleri($target),
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rdpParametreleri(RemoteTarget $target): array
    {
        $rdp = config('remote.guacamole.rdp');

        return [
            'hostname' => $target->host,
            'port' => (string) $target->port,
            'username' => $target->username,
            'password' => $target->password,
            /*
             * KİP KAYDIN KENDİSİNDEN gelebilir.
             *
             * Makineler aynı şeyi konuşmuyor: bazıları yalnızca
             * NLA kabul ediyor, bazılarının sertifikası SHA-1
             * olduğu için TLS hiç kurulamıyor ve yalnızca eski
             * RDP güvenliği kalıyor (bkz. göç 2026_09_15_240000).
             * Boşsa config'teki varsayılan geçerli.
             */
            'security' => $target->subject->rdp_security ?: $rdp['security'],
            'ignore-cert' => $rdp['ignore_cert'],
            'resize-method' => $rdp['resize_method'],
            'enable-drive' => $rdp['enable_drive'],
            'drive-path' => $rdp['drive_path'],
            'server-layout' => $rdp['server_layout'],
        ];
    }

    /**
     * VNC — EKRANDAKİ OTURUMA bağlanır, yeni oturum açmaz.
     *
     * Kullanıcı adı GÖNDERİLMEZ: RFB'nin klasik kimlik doğrulaması yalnızca
     * parola sorar. Kayıtta bir kullanıcı adı yazsa bile sunucu onu görmez;
     * göndermek, orada bir hesap varmış izlenimi bırakırdı.
     *
     * @return array<string, mixed>
     */
    private function vncParametreleri(RemoteTarget $target): array
    {
        $vnc = config('remote.guacamole.vnc');

        return array_filter([
            'hostname' => $target->host,
            'port' => (string) $target->port,
            'password' => $target->password,
            'encodings' => $vnc['encodings'],
            'color-depth' => $vnc['color_depth'],
            'cursor' => $vnc['cursor'],
            'force-lossless' => $vnc['force_lossless'],
        ], fn ($deger): bool => filled($deger));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function encrypt(array $payload, string $secret): string
    {
        $key = hex2bin($secret);

        if ($key === false || strlen($key) !== 16) {
            throw new RemoteSessionException('Guacamole anahtarı 16 baytlık onaltılık bir değer olmalı.');
        }

        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $signature = hash_hmac('sha256', $json, $key, binary: true);

        $encrypted = openssl_encrypt(
            $signature.$json,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            str_repeat("\0", 16),
        );

        if ($encrypted === false) {
            throw new RemoteSessionException('Guacamole yükü şifrelenemedi.');
        }

        return base64_encode($encrypted);
    }

    /**
     * Guacamole istemci adresi: bağlantı kimliği base64'lenmiş üçlüdür.
     */
    private function clientPath(string $connectionName, string $dataSource, string $token): string
    {
        $identifier = base64_encode("{$connectionName}\0c\0{$dataSource}");
        $proxy = rtrim((string) config('remote.guacamole.proxy_path'), '/');

        return "{$proxy}/#/client/{$identifier}?token={$token}";
    }
}
