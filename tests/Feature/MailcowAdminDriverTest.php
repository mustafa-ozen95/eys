<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OperatingSystem;
use App\Models\Credential;
use App\Services\Rotation\Drivers\MailcowAdminPasswordDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

/**
 * mailcow süper yönetici sürücüsü.
 *
 * Canlı bir mailcow'a BAĞLANILMAZ. Sınanabilen ve asıl kritik olan kısım
 * ÖZET BİÇİMİ: mailcow'un kendi hash_password() formülüyle birebir aynı
 * olmalı, yoksa yazdığımız parola ile giriş yapılamaz.
 */
class MailcowAdminDriverTest extends TestCase
{
    use RefreshDatabase;

    private function kayit(array $ustune = []): Credential
    {
        return Credential::factory()->create([
            'operating_system' => OperatingSystem::Application,
            'platform' => 'Mailcow',
            'rotation_driver' => MailcowAdminPasswordDriver::KEY,
            'host' => '127.0.0.1',
            'port' => 1,
            'username' => 'admin',
            'rotation_username' => 'root',
            'rotation_password' => 'ssh-parolasi',
            ...$ustune,
        ]);
    }

    private function cagir(string $method, mixed ...$args): mixed
    {
        $m = new ReflectionMethod(MailcowAdminPasswordDriver::class, $method);

        return $m->invoke(new MailcowAdminPasswordDriver, ...$args);
    }

    #[Test]
    public function uretilen_ozet_mailcowun_kendi_formuluyle_ayni(): void
    {
        $parola = 'DenemeParolasi123';
        $ozet = $this->cagir('ssha256', $parola);

        $this->assertStringStartsWith('{SSHA256}', $ozet);

        /*
         * mailcow, functions.inc.php içinde şunu yapıyor:
         *   salt = bin2hex(random_bytes(8))                 // 16 karakter
         *   {SSHA256} + base64( sha256(parola . salt) . salt )
         * Aynı formülle çözüp doğruluyoruz: tuz özetin SONUNDA taşınıyor.
         */
        $ham = base64_decode(substr($ozet, strlen('{SSHA256}')), true);

        $this->assertNotFalse($ham);
        $this->assertSame(48, strlen($ham), '32 bayt özet + 16 karakter tuz');

        $tuz = substr($ham, 32);
        $this->assertSame(16, strlen($tuz));
        $this->assertSame(hash('sha256', $parola.$tuz, true).$tuz, $ham);
    }

    #[Test]
    public function ozet_her_seferinde_farkli_tuz_kullanir(): void
    {
        /* Aynı tuz, iki hesabın aynı parolayı kullandığını ele verirdi. */
        $this->assertNotSame(
            $this->cagir('ssha256', 'ayni-parola'),
            $this->cagir('ssha256', 'ayni-parola'),
        );
    }

    #[Test]
    public function saklanan_ozet_dogru_parolayi_taniyor_yanlisi_reddediyor(): void
    {
        $ozet = $this->cagir('ssha256', 'dogru-parola');

        $this->assertTrue($this->cagir('matches', 'dogru-parola', $ozet));
        $this->assertFalse($this->cagir('matches', 'yanlis-parola', $ozet));
    }

    #[Test]
    public function baska_sema_ile_saklanmis_ozet_yerelde_dogrulanmaz(): void
    {
        /* Dürüst davranış: sınayamadığımıza "doğru" demeyiz. */
        $this->assertFalse($this->cagir('matches', 'parola', '{BLF-CRYPT}$2y$10$abcdefghij'));
        $this->assertFalse($this->cagir('matches', 'parola', 'ozetsiz-duz-metin'));
    }

    #[Test]
    public function ssh_kimligi_yoksa_nedeni_yazilir(): void
    {
        $driver = new MailcowAdminPasswordDriver(timeout: 1);

        /*
         * Mesaj kimliğin SEÇİLMESİ gerektiğini söylüyor: aynı adresli SSH
         * kaydı artık kendiliğinden kullanılmıyor, yalnızca öneriliyor
         * (bkz. MakineHesabi).
         */
        $this->assertStringContainsString('Güncelleme hesabı seçilmemiş',
            (string) $driver->unsupportedReason($this->kayit(['rotation_username' => null])));
        $this->assertSame('Yönetici kullanıcı adı tanımlı değil.',
            $driver->unsupportedReason($this->kayit(['username' => null])));
        $this->assertNull($driver->unsupportedReason($this->kayit()));
    }

    #[Test]
    public function kurtarma_hesabi_desteklemez_ama_dogrulayabilir(): void
    {
        $driver = new MailcowAdminPasswordDriver;

        $this->assertFalse($driver->supportsRecovery());
        $this->assertTrue($driver->canVerify());
    }

    #[Test]
    public function ulasilamayan_makinede_dogrulama_false_doner(): void
    {
        $this->assertFalse(
            (new MailcowAdminPasswordDriver(timeout: 1))->verify($this->kayit(), 'admin', 'herhangi'),
        );
    }
}
