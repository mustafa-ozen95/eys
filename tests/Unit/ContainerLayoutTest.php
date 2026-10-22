<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Kurulum Compose standardında: veritabanı ve redis ayrı servis, panel yalnız HTTP.
 *
 * Asıl kurallar: imajda MariaDB ve redis SUNUCUSU yok (istemci var: bekleme
 * ve taşıma için); nginx yalnız 80'i (ve cihaz arayüzü kökeni 9080'i) düz
 * HTTP dinler, TLS'i vekil bitirir; başlatıcı
 * veritabanını ortamdan alır ve gelene kadar bekler; compose'ta db ve redis
 * servisleri var, HTTP portu yayınlanmaz, sırlar dosyaya yazılmaz.
 */
class ContainerLayoutTest extends TestCase
{
    private const KOK = __DIR__.'/../..';

    private const AD = 'eys';

    private static function oku(string $yol): string
    {
        return (string) file_get_contents(self::KOK.'/'.$yol);
    }

    public function test_imajda_veritabani_ve_redis_sunucusu_yok(): void
    {
        $d = self::oku('docker/Dockerfile');

        $this->assertStringNotContainsString('mariadb-server', $d);
        $this->assertStringNotContainsString('redis-server', $d);
        $this->assertStringContainsString('mariadb-client', $d);
        $this->assertStringNotContainsString('/var/lib/mysql', $d);
        $this->assertMatchesRegularExpression('/^EXPOSE 80 9080$/m', $d);
        // Guacamole (Tomcat) yalnız nginx'e açık; aynı ağdaki db/redis'e değil.
        $this->assertStringContainsString('<Connector address="127.0.0.1" port="8090"', $d);
    }

    public function test_nginx_tls_dinlemez(): void
    {
        $n = self::oku('docker/rootfs/etc/nginx/conf.d/'.self::AD.'.conf');

        $this->assertStringContainsString('listen 80 default_server;', $n);
        // 9443 yorumlarda geçiyor (cihaz arayüzü adresi); dinleyici olarak yok.
        $this->assertDoesNotMatchRegularExpression('/^\s*listen\s+\S*443/m', $n);
        $this->assertStringContainsString('listen 9080;', $n);
        $this->assertStringNotContainsString('ssl_certificate', $n);
    }

    public function test_baslatici_veritabanini_ortamdan_alir_ve_bekler(): void
    {
        $b = self::oku('docker/rootfs/usr/local/bin/'.self::AD.'-entrypoint');

        $this->assertStringContainsString(': "${DB_HOST:=db}"', $b);
        $this->assertStringContainsString(': "${REDIS_HOST:=redis}"', $b);
        $this->assertStringContainsString('mariadb_bekle "${VT_BEKLE_CNF}"', $b);
        $this->assertStringContainsString('vt ortam-istemci', $b);
        foreach (['mariadbd', 'mariadb-install-db', 'redis-server', 'openssl req', '--protocol=socket'] as $yok) {
            $this->assertStringNotContainsString($yok, $b, $yok);
        }
    }

    public function test_baslatici_sirri_gunluge_basmaz(): void
    {
        $b = self::oku('docker/rootfs/usr/local/bin/'.self::AD.'-entrypoint');

        // Konteyner günlüğünü okuyan (docker logs, platformun günlük ekranı)
        // anahtarı ve ilk parolayı da okurdu; yalnız dosyanın yeri söylenir.
        $this->assertDoesNotMatchRegularExpression('/^\s*(uyari|bilgi|echo|printf)\b[^\n>]*\$\{?(APP_KEY|ADMIN_PASSWORD|PANEL_PASSWORD)\b[^\n>]*$/m', $b);
        $this->assertStringContainsString('cat /uygulama/storage/app/app-key.txt', $b);
    }

    public function test_compose_standart_duzende(): void
    {
        $c = self::oku('docker-compose.yml');

        foreach (["\n  panel:\n", "\n  db:\n", "\n  redis:\n", 'image: mariadb:', 'replicas: ${DB_REPLICAS:-1}', 'expose: ["80", "9080"]', 'internal: ${NETWORK_ISOLATED:-false}'] as $var) {
            $this->assertStringContainsString($var, $c, $var);
        }
        $this->assertStringNotContainsString('env_file', $c);
        $this->assertStringNotContainsString(':80"', $c, 'HTTP portu yayınlanmaz');
        $this->assertStringNotContainsString('external: true', $c);
        $this->assertDoesNotMatchRegularExpression('/^\s+- [A-Z_]*(PASSWORD|SIR|KEY|JETON)[A-Z_]*=/m', $c, 'sır compose dosyasına yazılmaz');
        $this->assertFileExists(self::KOK.'/.env.example');
        $this->assertFileDoesNotExist(self::KOK.'/docker/compose.yml');
    }

    public function test_arayuz_vekili_varsayilan_kapali_ve_port_araligi_tutarli(): void
    {
        $c = self::oku('docker-compose.yml');
        $caddy = self::oku('docker/interface-proxy.Caddyfile');
        $config = self::oku('config/remote.php');

        $this->assertStringContainsString('replicas: ${INTERFACE_PROXY_REPLICAS:-0}', $c);
        $this->assertStringContainsString('"9450-9479:9450-9479"', $c);
        $this->assertStringContainsString("env('INTERFACE_PORT_START', 9450)", $config);
        $this->assertStringContainsString("env('INTERFACE_PORT_END', 9479)", $config);
        // Caddy port aralığı kabul etmiyor; adresler tek tek yazılı, hiçbiri eksik olmasın.
        foreach (range(9450, 9479) as $port) {
            $this->assertStringContainsString(':'.$port, $caddy, (string) $port);
        }
        $this->assertStringNotContainsString(':9480', $caddy);
        $this->assertStringContainsString('reverse_proxy panel:9080', $caddy);
    }
}
