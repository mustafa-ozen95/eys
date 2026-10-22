<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ARAYÜZ VEKİLİ WEBSOCKET YÜKSELTMESİNİ İLETİR.
 *
 * Sabit boş bir "Connection" başlığı sıradan istekte doğrudur — istemcinin
 * "keep-alive"i cihaza olduğu gibi geçmemeli — ama aynı sabit değer websocket
 * el sıkışmasını da siler: cihaz yükseltme görmeyince bağlantıyı kuramaz.
 *
 * vCenter'da:
 *
 *     GET /ws  (tarayıcı, oturumlu)   → 426 Upgrade Required
 *     GET /ui/ws (oturumsuz, doğrudan cihaza) → 401
 *
 * Yani istek doğru uca ulaşır; eksik olan iki başlıktır. Belirtisi hiç
 * "bağlantı hatası" gibi görünmez: vSphere'in görev listesi sessizce
 * tazelenmez, kullanıcı sayfayı elle yeniler.
 */
final class InterfaceWebsocketTest extends TestCase
{
    private function vekilAyari(): string
    {
        return file_get_contents(base_path('docker/rootfs/etc/nginx/snippets/interface-proxy.conf'));
    }

    /** Yükseltme başlığı tarayıcıdan cihaza olduğu gibi geçer. */
    #[Test]
    public function vekil_yukseltme_basligini_iletir(): void
    {
        $ayar = $this->vekilAyari();

        $this->assertStringContainsString(
            'proxy_set_header Upgrade $http_upgrade;',
            $ayar,
            'Vekil "Upgrade" başlığını iletmiyor: websocket açılamaz.',
        );

        $this->assertStringContainsString(
            'proxy_set_header Connection $interface_connection;',
            $ayar,
            '"Connection" değeri eşlemeden gelmeli; sabit değer yükseltmeyi siler.',
        );

        /* Eski sabit değer geri gelirse belirti sessizdir; burada yakalanmalı. */
        $this->assertStringNotContainsString('proxy_set_header Connection "";', $ayar);
    }

    /**
     * SIRADAN İSTEKTE BAŞLIK YİNE BOŞ KALIR.
     *
     * Eşlemenin varsayılanı dolu olsaydı her XHR isteği cihaza yükseltme
     * isteğiymiş gibi giderdi. Kural ters kurulmuş olmalı: yükseltme
     * İSTENDİĞİNDE dolu, istenmediğinde boş.
     *
     * DEĞER BÜYÜK HARFLE BAŞLAR — "Upgrade", "upgrade" değil. Başlık değeri
     * kurala göre harfe duyarsız ve nginx'in her örneğinde küçük harfle
     * yazılır; iLO 4'ün gömülü sunucusu ise harfe BAKIYOR (iki ayrı cihazda,
     * konsolun /wss/ircport ucu):
     *
     *     Connection: Upgrade             → 101
     *     Connection: upgrade             → 400
     *     Connection: UPGRADE             → 400
     *
     * Belirtisi konsolun "Loading..." ekranında asılı kalmasıydı: sayfa
     * açılıyor, bağlantı hiç kurulmuyordu.
     */
    #[Test]
    public function yukseltme_istenmeyen_istekte_baglanti_bos_gider(): void
    {
        $nginx = file_get_contents(base_path('docker/rootfs/etc/nginx/nginx.conf'));

        $this->assertMatchesRegularExpression(
            '/map \$http_upgrade \$interface_connection \{\s*default Upgrade;\s*""\s*"";\s*\}/',
            $nginx,
            'Eşleme yok, kuralı ters ya da değeri küçük harfli: iLO 4 küçük harfte 400 döndürüyor.',
        );
    }
}
