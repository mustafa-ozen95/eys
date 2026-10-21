<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AccessType;
use App\Enums\OperatingSystem;
use App\Models\Credential;
use App\Models\CredentialGroup;
use App\Services\Rotation\Drivers\WindowsWinRmPasswordDriver;
use App\Services\Rotation\Drivers\WindowsWinRmRecoveryPasswordDriver;
use App\Services\Rotation\RotationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * WinRM taşıması.
 *
 * Gerçek bir Windows'a bağlanmadan sınanan şey SÖZLEŞME: yardımcıya ne
 * gönderiyoruz, yanıtını nasıl okuyoruz ve parola nereden geçiyor. Asıl
 * davranış bir Windows sunucusunda doğrulandı (bkz. WindowsWinRmPasswordDriver
 * docblock); bu testler o davranışın bir daha bozulmamasını sağlar.
 */
class WinRmDriverTest extends TestCase
{
    use RefreshDatabase;

    private string $capture;

    protected function setUp(): void
    {
        parent::setUp();

        $this->capture = tempnam(sys_get_temp_dir(), 'winrm-istek-');
    }

    protected function tearDown(): void
    {
        @unlink($this->capture);

        parent::tearDown();
    }

    /**
     * Yardımcının yerine geçen kabuk betiği: isteği dosyaya yazar, hazır
     * yanıtı basar. Böylece taşıma sözleşmesi ağa çıkmadan sınanır.
     */
    private function sunucu(string $yanit): void
    {
        $script = tempnam(sys_get_temp_dir(), 'winrm-stub-');

        file_put_contents($script, "#!/bin/sh\ncat > '{$this->capture}'\ncat <<'JSON'\n{$yanit}\nJSON\n");
        chmod($script, 0700);

        config([
            'rotation.winrm.python' => '/bin/sh',
            'rotation.winrm.runner' => $script,
            'rotation.winrm.openssl_conf' => '/dev/null',
            'rotation.winrm.timeout' => 5,
        ]);
    }

    /** @return array<string, mixed> */
    private function istek(): array
    {
        return json_decode((string) file_get_contents($this->capture), true, 512, JSON_THROW_ON_ERROR);
    }

    private function kayit(): Credential
    {
        return Credential::factory()->for(CredentialGroup::factory(), 'group')->create([
            'access_type' => AccessType::Rdp,
            'operating_system' => OperatingSystem::Windows,
            'host' => '10.0.0.7',
            'port' => null,
            'username' => 'administrator',
            'password' => 'eski-parola',
        ]);
    }

    #[Test]
    public function oturum_acarken_kimlik_once_sinanir(): void
    {
        $this->sunucu('{"ok": true, "status": 0, "stdout": "", "stderr": ""}');

        (new WindowsWinRmPasswordDriver)->open($this->kayit(), 'administrator', 'gizli');

        $istek = $this->istek();

        $this->assertSame('verify', $istek['action']);
        $this->assertSame('10.0.0.7', $istek['host']);
        $this->assertSame(5985, $istek['port'], 'Port verilmemişse WinRM varsayılanı kullanılmalı.');
    }

    #[Test]
    public function kimlik_reddedilirse_dogrulama_false_doner(): void
    {
        $this->sunucu('{"ok": false, "error": "InvalidCredentialsError: 401"}');

        $this->assertFalse((new WindowsWinRmPasswordDriver)->verify($this->kayit(), 'administrator', 'yanlis'));
    }

    /**
     * Parola KOMUT SATIRINA girmez; KABUĞUN ORTAM DEĞİŞKENİNDEN geçer.
     *
     * Önce standart girdiden gönderiliyordu ama pywinrm 0.3.0'da
     * `send_command_input` YOK: parola isteyen her WinRM turu "AttributeError"
     * ile düşüyordu. WS-Man kabuğu açılırken ortam değişkeni verilebiliyor ve
     * o değişken komut satırında görünmüyor.
     */
    #[Test]
    public function parola_komut_satirina_yazilmaz_ortam_degiskeninden_gecer(): void
    {
        $this->sunucu('{"ok": true, "status": 0, "stdout": "", "stderr": ""}');

        $driver = new WindowsWinRmPasswordDriver;
        $session = $driver->open($this->kayit(), 'administrator', 'oturum-parolasi');

        $driver->changePassword($session, 'administrator', 'YENI-PAROLA-42');

        $istek = $this->istek();
        $argumanlar = implode(' ', $istek['arguments']);

        $this->assertStringNotContainsString('YENI-PAROLA-42', $argumanlar);
        $this->assertSame(['EYS_GIZLI' => 'YENI-PAROLA-42'], $istek['env']);

        /* Betik base64/UTF-16LE gelir; içinde parola değil, onu okuyan satır olmalı. */
        $betik = mb_convert_encoding(base64_decode((string) end($istek['arguments'])), 'UTF-8', 'UTF-16LE');

        $this->assertStringContainsString('$env:EYS_GIZLI', $betik);
        $this->assertStringContainsString("Set-LocalUser -Name 'administrator'", $betik);
        $this->assertStringNotContainsString('YENI-PAROLA-42', $betik);
    }

    /** Yönetici grubu ADIYLA değil, yerelleştirmeden etkilenmeyen kimliğiyle bulunur. */
    #[Test]
    public function kurtarma_hesabi_yonetici_grubuna_sid_ile_eklenir(): void
    {
        $this->sunucu('{"ok": true, "status": 0, "stdout": "", "stderr": ""}');

        $driver = new WindowsWinRmPasswordDriver;
        $session = $driver->open($this->kayit(), 'administrator', 'oturum');

        $driver->createRecovery($session, 'eys-kurtarma-abc123', 'gecici-parola');

        $betik = mb_convert_encoding(
            base64_decode((string) end($this->istek()['arguments'])),
            'UTF-8',
            'UTF-16LE',
        );

        $this->assertStringContainsString("New-LocalUser -Name 'eys-kurtarma-abc123'", $betik);
        $this->assertStringContainsString("Get-LocalGroup -SID 'S-1-5-32-544'", $betik);
        $this->assertStringNotContainsString('Administrators', $betik);
    }

    #[Test]
    public function komut_sifirdan_farkli_donerse_tur_durur(): void
    {
        $this->sunucu('{"ok": true, "status": 1, "stdout": "", "stderr": ""}');

        $driver = new WindowsWinRmPasswordDriver;
        $session = $driver->open($this->kayit(), 'administrator', 'oturum');

        $this->expectException(RotationException::class);

        $driver->deleteRecovery($session, 'eys-kurtarma-abc123');
    }

    /** Ham Python istisnası kullanıcıya gösterilecek bir metin değildir. */
    #[Test]
    public function yardimcinin_hatasi_insanca_bir_cumleye_cevrilir(): void
    {
        $this->sunucu('{"ok": false, "error": "ConnectionError: Max retries exceeded with url: /wsman"}');

        try {
            (new WindowsWinRmPasswordDriver)->open($this->kayit(), 'administrator', 'oturum');
            $this->fail('Bağlantı hatası istisna atmalıydı.');
        } catch (RotationException $e) {
            $this->assertStringContainsString('Makineye ulaşılamadı', $e->getMessage());
            $this->assertStringNotContainsString('Max retries', $e->getMessage());
        }
    }

    #[Test]
    public function eksik_alanlar_adiyla_bildirilir(): void
    {
        $driver = new WindowsWinRmPasswordDriver;

        $credential = $this->kayit();
        $credential->forceFill(['host' => null])->save();
        $this->assertSame('Adres tanımlı değil.', $driver->unsupportedReason($credential));

        $credential->forceFill(['host' => '10.0.0.7', 'username' => null])->save();
        $this->assertSame('Kullanıcı adı tanımlı değil.', $driver->unsupportedReason($credential));
    }

    /**
     * Geçici hesaplı sıra AYRI BİR SÜRÜCÜDÜR.
     *
     * Yeteneği makine belirliyor: LocalAccountTokenFilterPolicy=1 olan
     * makinede geçici hesap uzaktan giriş yapabiliyor, ayarsız makinede
     * yapamıyor. Karar global bir anahtarda değil, kaydın sürücü alanında
     * durmalı ki iki makine aynı anda doğru çalışsın.
     */
    #[Test]
    public function kurtarmali_surucu_ayni_isi_gecici_hesapla_yapar(): void
    {
        $duz = new WindowsWinRmPasswordDriver;
        $kurtarmali = new WindowsWinRmRecoveryPasswordDriver;

        $this->assertFalse($duz->supportsRecovery());
        $this->assertTrue($kurtarmali->supportsRecovery());

        /* Komutlar aynı: yalnızca sıra değişir, yapılan iş değil. */
        $this->sunucu('{"ok": true, "status": 0, "stdout": "", "stderr": ""}');
        $session = $kurtarmali->open($this->kayit(), 'administrator', 'gizli');
        $kurtarmali->changePassword($session, 'administrator', 'YENI-PAROLA-42');

        $betik = mb_convert_encoding(base64_decode((string) end($this->istek()['arguments'])), 'UTF-8', 'UTF-16LE');

        $this->assertStringContainsString('$env:EYS_GIZLI', $betik);
        $this->assertStringContainsString("Set-LocalUser -Name 'administrator'", $betik);
    }

    /**
     * Kurtarma hesabı bu yolda varsayılan olarak KAPALI.
     *
     * Windows, yerleşik Administrator dışındaki yerel hesaplara uzaktan
     * yönetici jetonu vermiyor; açık bırakılsaydı her tur ikinci adımda
     * boşuna iptal olurdu.
     */
    #[Test]
    public function kurtarma_hesabi_varsayilan_olarak_kapalidir(): void
    {
        $this->assertFalse((new WindowsWinRmPasswordDriver)->supportsRecovery());

        config(['rotation.winrm.recovery' => true]);

        $this->assertTrue((new WindowsWinRmPasswordDriver)->supportsRecovery());
    }
}
