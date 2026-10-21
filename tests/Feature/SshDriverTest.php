<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OperatingSystem;
use App\Models\Credential;
use App\Services\Rotation\Drivers\SshPasswordDriver;
use App\Services\Rotation\Drivers\WindowsPasswordDriver;
use App\Services\Rotation\RotationException;
use App\Services\Rotation\RotationSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeSshHandle;
use Tests\TestCase;

/**
 * Gerçek SSH sürücüsünün sınanabilen kısmı.
 *
 * Canlı bir makineye BAĞLANILMAZ; bağlantı hatası senaryosu servis olmayan
 * bir adresle sınanır (bkz. .ai/rules/work.md). Buradaki soru "parola
 * değişiyor mu" değil — o ancak gerçek bir makinede ölçülür. Buradaki soru:
 * sürücü, eksik veriyi ve ulaşılamayan adresi doğru mu bildiriyor.
 */
class SshDriverTest extends TestCase
{
    use RefreshDatabase;

    private function kayit(array $ustune = []): Credential
    {
        return Credential::factory()->create([
            'operating_system' => OperatingSystem::Linux,
            'host' => '127.0.0.1',
            'port' => 1,
            'username' => 'root',
            'password' => 'parola',
            ...$ustune,
        ]);
    }

    #[Test]
    public function eksik_alanlari_adiyla_bildirir(): void
    {
        $driver = new SshPasswordDriver(timeout: 1);

        $this->assertSame('Adres tanımlı değil.', $driver->unsupportedReason($this->kayit(['host' => null])));
        $this->assertSame('Kullanıcı adı tanımlı değil.', $driver->unsupportedReason($this->kayit(['username' => null])));
        $this->assertSame('Kayıtlı parola yok; güncelleme mevcut parolayla başlar.', $driver->unsupportedReason($this->kayit(['password' => null])));
        $this->assertNull($driver->unsupportedReason($this->kayit()));
    }

    #[Test]
    public function ulasilamayan_adreste_istisna_atar(): void
    {
        $driver = new SshPasswordDriver(timeout: 1);

        $this->expectException(RotationException::class);

        /* 127.0.0.1:1 — dinleyen bir servis yok. */
        $driver->open($this->kayit(), 'root', 'parola');
    }

    #[Test]
    public function kabuga_gonderilemeyecek_hesap_adi_reddedilir(): void
    {
        $driver = new SshPasswordDriver(timeout: 1);
        $oturum = new RotationSession(null, 'root', 'parola', static fn () => null);

        $this->expectException(RotationException::class);

        /*
         * Hesap adı kabuk komutuna giriyor. Doğrulanmasaydı "; rm -rf /"
         * gibi bir ad komutu bölerdi.
         */
        $driver->deleteRecovery($oturum, 'kotu; rm -rf /');
    }

    #[Test]
    public function ulasilamayan_adreste_dogrulama_false_doner(): void
    {
        $driver = new SshPasswordDriver(timeout: 1);

        /*
         * Doğrulama İSTİSNA ATMAZ, false döner: rotator bu yanıta bakıp geri
         * alma kararı veriyor. İstisna atsaydı geri alma hiç çalışmazdı.
         */
        $this->assertFalse($driver->verify($this->kayit(), 'root', 'herhangi'));
    }

    /**
     * Ayardaki zaman aşımı sürücüye ULAŞMALI.
     *
     * Sabit bir süre yazılmaz; config/rotation.php'deki değer sürücüye
     * ulaşır. `chpasswd` kısa bir sabiti aşarsa sürücü "değiştiremedim" der
     * ama parola değişmiş olur — tur yarıda kalır ve onarım gerekir. Ayarın
     * işe yaradığını bu test kilitler.
     */
    #[Test]
    public function zaman_asimi_ayardan_okunur(): void
    {
        config(['rotation.ssh_timeout' => 41]);

        foreach ([new SshPasswordDriver, new WindowsPasswordDriver] as $driver) {
            $alan = new \ReflectionProperty($driver, 'timeout');

            $this->assertSame(41, $alan->getValue($driver), $driver::class.' ayarı kullanmalı.');
        }

        /* Açıkça verilen değer ayarı ezer: testler ve özel durumlar için. */
        $elle = new SshPasswordDriver(7);
        $this->assertSame(7, (new \ReflectionProperty($elle, 'timeout'))->getValue($elle));
    }

    /**
     * SUDO YOLUNDA PAROLA VE KOMUT GİRDİSİ AYRI AŞAMADA YAZILIR.
     *
     * AlmaLinux 9 / Ubuntu 26.04'te `sudo -S` parolayı okuduktan sonra uçbirim girdi tamponunu temizliyor
     * ve arkasından gelen komut girdisi siliniyor; `chpasswd` girdiyi
     * sonsuza kadar bekliyor. Tur "komut tamamlanmadı" diyerek kapanıyordu
     * ama bazı makinelerde parola DEĞİŞMİŞ oluyordu — kasa ile makine
     * ayrışıyordu ve onarım gerekiyordu.
     *
     * Bu test o sırayı kilitler: önce yükseltme (yalnızca parola), kapı
     * işareti beklenir, komut girdisi ondan sonra yazılır.
     */
    #[Test]
    public function sudo_yolunda_parola_ve_girdi_ayri_asamada_yazilir(): void
    {
        $handle = new FakeSshHandle;
        $session = new RotationSession($handle, 'eys-kurtarma-abc123', 'yukseltme-parolasi', fn () => null);

        (new SshPasswordDriver(timeout: 1))->changePassword($session, 'root', 'YENI-PAROLA-42');

        /* Yükseltme ve asıl komut ayrı ayrı, kapı işaretiyle ayrılmış olmalı. */
        $this->assertStringContainsString("sudo -S -p 'EYS-SUDO-PAROLA:' -v", $handle->command);
        $this->assertStringContainsString('sudo -n sh -c', $handle->command);
        $this->assertMatchesRegularExpression('/NDSGT[0-9a-f]+/', $handle->command, 'Kapı işareti olmalı.');

        /*
         * Girdi tüketen komut BORUYLA beslenir: sudo-rs (Ubuntu 26.04)
         * çocuğuna dosya-sonunu iletmiyor ve chpasswd sonsuza kadar
         * bekliyordu. Kabuk tek satır okur, boru kapanır, komut biter.
         */
        $this->assertStringContainsString('read -r eys_satir', $handle->command);
        $this->assertStringContainsString('| chpasswd', $handle->command);

        /* Yazma sırası: önce yükseltme parolası, sonra komut girdisi. */
        $this->assertSame("yukseltme-parolasi\n", $handle->writes[0]);
        $this->assertSame("root:YENI-PAROLA-42\n", $handle->writes[1]);

        /*
         * Sıra işarete dayanır, zamanlamaya değil:
         *   1. istem mi kapı mı okunur  → parola ancak istem görülünce yazılır
         *   2. kapı okunur              → komut girdisi ancak ondan sonra
         *   3. bitiş işareti okunur
         */
        $this->assertStringContainsString('|NDSGT', $handle->reads[0], 'İlk okuma "istem mi kapı mı" olmalı.');
        $this->assertMatchesRegularExpression('/NDSGT/', $handle->reads[1]);
        $this->assertMatchesRegularExpression('/NDSRC/', $handle->reads[2]);
    }

    /**
     * PAROLA, İSTEM GÖRÜLMEDEN YAZILMAZ.
     *
     * AlmaLinux 9.4 / sudo 1.9.5'te sudo parolayı okumadan
     * hemen önce uçbirim girdi tamponunu temizliyor. Erken yazılan parola
     * siliniyor, sudo "Sorry, try again" diyor ve asıl komut girdiyi sonsuza
     * kadar bekliyor; tur 60 saniyede "komut tamamlanmadı" ile düşüyor ve
     * makinede kurtarma hesabı bırakıyordu.
     *
     * Yarış YENİ AÇILAN kurtarma hesaplarında kaçınılmazdı: hesap sudo'yu ilk
     * kez kullandığı için sudo önce uzun "lecture" metnini basıyor. Aynı
     * makinede daha önce sudo kullanmış bir hesapla sorun hiç görünmüyordu.
     */
    #[Test]
    public function yukseltme_parolasi_istem_gorulmeden_yazilmaz(): void
    {
        $handle = new FakeSshHandle;
        $session = new RotationSession($handle, 'eys-kurtarma-abc123', 'yukseltme-parolasi', fn () => null);

        (new SshPasswordDriver(timeout: 1))->changePassword($session, 'root', 'YENI-PAROLA-42');

        /* Boş istem kullanılamaz: görülemeyen bir istem beklenemez. */
        $this->assertStringNotContainsString('-p ""', $handle->command);

        /* İlk okuma istemi sorar; parola ancak ondan SONRA yazılır. */
        $this->assertStringContainsString('EYS', $handle->reads[0]);
        $this->assertSame("yukseltme-parolasi\n", $handle->writes[0]);
    }

    /**
     * PAROLA SORMAYAN makinede istem beklenerek vakit kaybedilmez.
     *
     * NOPASSWD tanımlı bir hesapta sudo hiç sormaz; istemi bekleyen bir
     * sürücü orada zaman aşımına düşerdi.
     */
    #[Test]
    public function parolasiz_sudo_makinesinde_beklenmez(): void
    {
        $handle = new FakeSshHandle;
        $handle->sudoParolaSorar = false;

        $session = new RotationSession($handle, 'eys-kurtarma-abc123', 'yukseltme-parolasi', fn () => null);

        (new SshPasswordDriver(timeout: 1))->changePassword($session, 'root', 'YENI-PAROLA-42');

        /* Tek yazım: yalnızca komut girdisi. Parola hiç yazılmaz. */
        $this->assertSame(["root:YENI-PAROLA-42\n", "\x04"], $handle->writes);
    }

    /** Root oturumunda yükseltme yoktur: tek aşama, tek yazım. */
    #[Test]
    public function root_oturumunda_yukseltme_yapilmaz(): void
    {
        $handle = new FakeSshHandle;
        $session = new RotationSession($handle, 'root', 'root-parolasi', fn () => null);

        (new SshPasswordDriver(timeout: 1))->changePassword($session, 'root', 'YENI-PAROLA-42');

        $this->assertStringNotContainsString('sudo', $handle->command);
        $this->assertStringContainsString('| chpasswd', $handle->command);
        $this->assertSame(["root:YENI-PAROLA-42\n", "\x04"], $handle->writes);
    }

    /** Parola HİÇBİR durumda komut satırına yazılmaz. */
    #[Test]
    public function parola_komut_satirina_sizmaz(): void
    {
        foreach (['root', 'eys-kurtarma-abc123'] as $kim) {
            $handle = new FakeSshHandle;
            $session = new RotationSession($handle, $kim, 'yukseltme-parolasi', fn () => null);

            (new SshPasswordDriver(timeout: 1))->changePassword($session, 'root', 'GIZLI-PAROLA-42');

            $this->assertStringNotContainsString('GIZLI-PAROLA-42', $handle->command, $kim.' için sızdı.');
            $this->assertStringNotContainsString('yukseltme-parolasi', $handle->command, $kim.' için sızdı.');
        }
    }

    /**
     * Kopmuş bağlantıda ANLAŞILIR hata verilir.
     *
     * phpseclib kopmuş oturumda exec() çağrılınca kendi içinde "Undefined
     * array key 1" PHP uyarısı üretiyor; kayıtta bunu gören insan hatayı
     * kütüphanede arıyor. Oysa neden basit ve söylenebilir.
     */
    #[Test]
    public function baglanti_koptuysa_komut_denenmez(): void
    {
        $handle = new FakeSshHandle;
        $handle->connected = false;

        $session = new RotationSession($handle, 'root', 'parola', fn () => null);

        $this->expectExceptionMessage('bağlantı koptu');

        (new SshPasswordDriver)->changePassword($session, 'root', 'yeni-parola');
    }

    /**
     * ZİNCİRLİ KOMUTUN TAMAMI sudo ALTINDA.
     *
     * Root olmayan hesapla kurtarma hesabı açılırken `usermod` sudo'nun
     * dışında kalıyordu: hesap açılıyor, gruba eklenemiyor, tur "kod 1"
     * ile düşüyordu.
     */
    #[Test]
    public function root_olmayan_hesapta_zincirin_tamami_yukseltilir(): void
    {
        $handle = new FakeSshHandle;
        $session = new RotationSession($handle, 'operator', 'yukseltme-parolasi', fn () => null);

        (new SshPasswordDriver(timeout: 1))->createRecovery($session, 'eys-kurtarma-abc123', 'GECICI-PAROLA-1');

        $ilk = $handle->commands[0] ?? '';
        $this->assertStringContainsString(
            "sudo -n sh -c 'useradd -m -s /bin/sh eys-kurtarma-abc123 && (usermod -aG sudo eys-kurtarma-abc123 || usermod -aG wheel eys-kurtarma-abc123)'",
            $ilk,
            'useradd ve usermod aynı sudo kabuğunda çalışmalı.',
        );
        $this->assertStringNotContainsString('sudo -n useradd', $ilk);
    }

    /** Root yolunda sudo hiç eklenmez; zincir olduğu gibi çalışır. */
    #[Test]
    public function root_hesabinda_sudo_eklenmez(): void
    {
        $handle = new FakeSshHandle;
        $session = new RotationSession($handle, 'root', 'kok-parola', fn () => null);

        (new SshPasswordDriver(timeout: 1))->deleteRecovery($session, 'eys-kurtarma-abc123');

        $this->assertStringNotContainsString('sudo', $handle->commands[0] ?? '');
        $this->assertStringContainsString('userdel -r -f eys-kurtarma-abc123', $handle->commands[0] ?? '');
    }
}
