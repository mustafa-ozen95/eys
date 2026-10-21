<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * GUACD'NİN YAZILABİLİR BİR HOME'U OLMALI.
 *
 * FreeRDP, sunucu sertifikalarını `$HOME/.config/freerdp` altında tutar ve bu
 * dizini AÇAMAZSA bağlantıyı TLS/NLA pazarlığı hiç başlamadan keser. guacd
 * root değil "eys" olarak koşuyor; supervisor'a `environment` verilmezse
 * süreç root'un HOME'unu miras alıyor, `/root/.config`e yazamıyor ve hata
 * ekrana şöyle yansıyordu:
 *
 *     RDP server closed/refused connection: Security negotiation failed
 *     (wrong security type?)
 *
 * Mesaj güvenlik kipini işaret ettiği için sorun parola ya da NLA sanılır.
 * Aynı makineye aynı kimlikle doğrudan `xfreerdp` (root olarak) BAŞARILI
 * oluyordu; tek fark HOME'du. `HOME=/var/lib/guacamole` verildikten sonra
 * guacd hem `any` hem `nla` kipinde bağlandı.
 *
 * Ayrıca RDP kanal eklentileri (guac-common-svc, guacsnd, guacdr) guac'ın
 * prefix'ine değil FreeRDP'nin kendi dizinine kurulur; imaja o dizin de
 * kopyalanmazsa aynı hata mesajıyla düşer.
 */
final class GuacdEnvironmentTest extends TestCase
{
    #[Test]
    public function guacd_yazilabilir_bir_home_ile_baslatilir(): void
    {
        $betik = file_get_contents(base_path('docker/rootfs/usr/local/bin/eys-entrypoint'));

        $this->assertMatchesRegularExpression(
            '/\[program:guacd\](?:(?!\[program:).)*environment\s*=\s*[^\n]*HOME="[^"]+"/s',
            $betik,
            'guacd supervisor bloğunda HOME tanımlı değil: FreeRDP sertifika deposunu açamaz.'
        );

        $this->assertStringContainsString(
            'chown -R eys:eys /var/lib/guacamole',
            $betik,
            'HOME dizini eys kullanıcısına verilmemiş: dizin açılamaz.'
        );
    }

    #[Test]
    public function rdp_kanal_eklentileri_imaja_kopyalanir(): void
    {
        $this->assertStringContainsString(
            'COPY --from=guacd-derleme /usr/lib/x86_64-linux-gnu/freerdp2',
            file_get_contents(base_path('docker/Dockerfile')),
            'FreeRDP eklenti dizini imaja kopyalanmıyor: rdpdr/rdpsnd kanalları yüklenemez.'
        );
    }

    /**
     * EKLENTİ YOLU AYAR DOSYASINDA DURUR, YALNIZCA ÖNBELLEKTE DEĞİL.
     *
     * guacd protokol eklentilerini (libguac-client-rdp.so) dlopen ile,
     * dinamik bağlayıcının arama yolundan açar. `ldconfig <dizin>` o dizini
     * SADECE ld.so.cache'e yazar, ardında bir ayar dosyası bırakmaz: kabın
     * içinde ldconfig'i tetikleyen herhangi bir işlem (bir apt kurulumu
     * yeter) önbelleği bu dizin olmadan yeniden üretir ve guacd o andan
     * sonra her RDP bağlantısında
     *
     *     WARNING: Support for protocol "rdp" is not installed
     *
     * deyip düşer. Tarayıcıdaki karşılığı ise "sunucu yanıt vermiyor" —
     * yani ağ arızası gibi görünen, RDP'yle ilgisi kurulamayan bir hata.
     * Doğrulama: ayar dosyası yazıldıktan sonra apt kurulumu ldconfig'i
     * yeniden çalıştırdığında yol yerinde kalır.
     */
    #[Test]
    public function eklenti_yolu_kalici_olarak_tanimlanir(): void
    {
        $dockerfile = file_get_contents(base_path('docker/Dockerfile'));

        $this->assertStringContainsString(
            '/etc/ld.so.conf.d/guacamole.conf',
            $dockerfile,
            'Eklenti dizini ld.so ayarına yazılmıyor: ldconfig yeniden koşunca guacd RDP desteğini kaybeder.'
        );

        $this->assertDoesNotMatchRegularExpression(
            '/ldconfig\s+\/opt\/guacamole\/lib/',
            $dockerfile,
            'Dizin ldconfig\'e argüman olarak veriliyor: bu yalnızca önbelleğe yazar, kalıcı değildir.'
        );
    }

    /**
     * WEBP KAPALI DERLENİR.
     *
     * guacd, tarayıcı destekliyorsa fotoğrafik karoları WebP'ye çeviriyor.
     * WebP daha iyi sıkıştırır ama KODLAMASI çok pahalı: ekranı sürekli
     * değişen bir makinede guacd bir çekirdeği dolduruyor ve güncellemeler
     * geride kalıyor — kullanıcı tarafında "akmıyor, kasıyor". WebP
     * kapalıyken görüntü belirgin biçimde daha akıcı ve guacd tavana dayanmıyor.
     *
     * Kütüphaneyi kurmamak tek başına yeterdi; seçenek yine de AÇIKÇA
     * yazılıyor ki başka bir paket libwebp'i bağımlılık olarak geri
     * getirdiğinde webp sessizce geri gelmesin.
     */
    #[Test]
    public function guacd_webp_olmadan_derlenir(): void
    {
        $dockerfile = file_get_contents(base_path('docker/Dockerfile'));

        $this->assertStringContainsString(
            '--without-webp',
            $dockerfile,
            'guacd webp ile derleniyor: kodlama maliyeti oturumu yavaşlatır.'
        );

        $this->assertStringNotContainsString(
            'libwebp-dev',
            $dockerfile,
            'libwebp-dev kurulu: configure onu bulup webp desteğini geri açar.'
        );
    }
}
