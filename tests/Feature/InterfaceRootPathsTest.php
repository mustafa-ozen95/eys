<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\DeviceInterface\InterfaceType;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ARAYÜZÜN KÖKTEN İSTEDİĞİ YOLLAR İLE NGINX BİREBİR AYNI OLMALI.
 *
 * Vekil, gelen isteğin yoluna arayüzün TABAN dizinini ekliyor (vCenter'da
 * `/ui/`). Arayüzün cihazın KÖKÜNDEN istediği adresler için nginx'te ayrı
 * konumlar var; liste ile konumlar ayrışırsa istek yanlış yere gider ve hata
 * arayüzün kendi diliyle görünür — yani sebebi hiç akla gelmez.
 *
 * Örnek: vSphere Client'ın yeni sihirbazları ("New Virtual Machine" →
 * Customize hardware) verilerini API geçidinden çeker ve o adres cihazın
 * kökündedir (`/apigw`, `/cp/graphql`). Taban dizinine çevrilirse
 * (`/ui/apigw`) 401 döner ve ekranda yalnızca "Error Loading Data …
 * {"status":401}" görünür.
 *
 * Listedeki her yolun (ör. `/websso/`) nginx'te bir konumu olmalı.
 */
final class InterfaceRootPathsTest extends TestCase
{
    #[Test]
    public function her_kok_yolunun_nginxte_konumu_vardir(): void
    {
        $nginx = file_get_contents(base_path('docker/rootfs/etc/nginx/conf.d/eys.conf'));

        $eksik = [];

        foreach (InterfaceType::cases() as $tur) {
            foreach ($tur->kokYollari() as $yol) {
                if (! str_contains($nginx, 'location ^~ '.$yol.' {')) {
                    $eksik[] = $tur->value.' → '.$yol;
                }
            }
        }

        $this->assertSame([], $eksik, 'Kök yolunun nginx karşılığı yok: '.implode(', ', $eksik));
    }

    /** vSphere Client'ın API geçidi ve GraphQL ucu listede olmalı. */
    #[Test]
    public function vcenter_api_gecidi_listede(): void
    {
        $yollar = InterfaceType::Vcenter->kokYollari();

        $this->assertContains('/apigw', $yollar);
        $this->assertContains('/cp/', $yollar);
    }

    /**
     * VM KONSOLUNUN ROTASI CİHAZIN KÖKÜNDE.
     *
     * vCenter 8 konsolu kendi üzerinden geçiriyor: arayüz önce
     * /ui/webconsole-ticket'tan bir rota alıyor ("routeCreated"), sonra
     * konsolun canlı bağlantısını o rotaya kuruyor.
     *
     * Rota taban dizinine çevrilirse /ui/webconsole-ticket/<rota> olur ve
     * arayüzün bilet ucu fazladan yolu yok sayıp şunu döndürür:
     *
     *     {"ticketUrl":"null","routeCreated":false,"isError":true,
     *      "errorMessage":"An error occurred while retrieving the web console URL."}
     *
     * Konsol bunun üzerine bağlantıyı hiç denemez; ekranda yalnızca
     * "Couldn't establish a connection to the VM web console." yazar.
     */
    #[Test]
    public function konsol_rotasi_kok_yollarinda(): void
    {
        $this->assertContains('/webconsole-ticket/', InterfaceType::Vcenter->kokYollari());
    }
}
