<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AccessType;
use App\Enums\OperatingSystem;
use App\Models\Credential;
use App\Services\Rotation\Drivers\RspamdPasswordDriver;
use App\Services\Rotation\PasswordRotator;
use App\Services\Rotation\RotationException;
use App\Services\Rotation\RotationSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Rspamd sürücüsünün sınanabilen kısmı.
 *
 * Canlı bir makineye BAĞLANILMAZ. Sınanan şey: eksik alanlar doğru
 * bildiriliyor mu, ve dosyaya yazılacak metin güvenli mi.
 */
class RspamdDriverTest extends TestCase
{
    use RefreshDatabase;

    private function kayit(array $ustune = []): Credential
    {
        return Credential::factory()->create([
            'operating_system' => OperatingSystem::Application,
            'platform' => 'Rspamd',
            'rotation_driver' => RspamdPasswordDriver::KEY,
            'host' => '127.0.0.1',
            'port' => 1,
            'username' => null,
            'rotation_username' => 'root',
            'rotation_password' => 'ssh-parolasi',
            ...$ustune,
        ]);
    }

    #[Test]
    public function ssh_kimligi_yoksa_nedeni_yazilir(): void
    {
        $driver = new RspamdPasswordDriver(timeout: 1);

        /*
         * Rspamd'ın hesabı yok; geri dönüş kanalı makinenin SSH erişimi.
         * O kimlik verilmeden tur başlatılamaz.
         */
        /*
         * Mesaj kimliğin SEÇİLMESİ gerektiğini söylüyor: aynı adresli SSH
         * kaydı artık kendiliğinden kullanılmıyor, yalnızca öneriliyor
         * (bkz. MakineHesabi).
         */
        $this->assertStringContainsString('Güncelleme hesabı seçilmemiş',
            (string) $driver->unsupportedReason($this->kayit(['rotation_username' => null])));

        $this->assertNull($driver->unsupportedReason($this->kayit()));
    }

    #[Test]
    public function yapilandirmaya_yazilamayacak_parola_reddedilir(): void
    {
        $driver = new RspamdPasswordDriver(timeout: 1);
        $oturum = new RotationSession(null, 'root', 'ssh', static fn () => null);

        /*
         * Parola çift tırnak içinde .inc dosyasına yazılıyor. Tırnak ya da
         * ters bölü içeren bir parola dosyayı bozar ve Rspamd açılmaz.
         */
        foreach (['tirnak"li', 'ters\\bolu', "satir\nsonu"] as $parola) {
            try {
                $driver->changePassword($oturum, 'yoksayilir', $parola);
                $this->fail("Reddedilmeliydi: {$parola}");
            } catch (RotationException $e) {
                $this->assertStringContainsString('karakter', $e->getMessage());
            }
        }
    }

    /**
     * KURTARMA HESABI AÇILMAZ — ama DOĞRULAMA YAPILIR.
     *
     * Rspamd'ın hesap kavramı yok; açılacak geçici bir hesap da yok. Geri
     * dönüş kanalı makinenin SSH erişimi ve o ayrı bir kayıt.
     *
     * Doğrulama ise VAR: Rspamd 4.1.0'da /auth başlıksız 401, doğru
     * parolayla 200 döner ve sürücü onu kullanır.
     */
    #[Test]
    public function kurtarma_hesabi_desteklemez_ama_dogrulama_yapar(): void
    {
        $driver = new RspamdPasswordDriver(timeout: 1);

        $this->assertFalse($driver->supportsRecovery());
        $this->assertTrue($driver->canVerify());
    }

    #[Test]
    public function gecersiz_mailcow_yolu_reddedilir(): void
    {
        config(['rotation.mailcow_path' => '/opt/mailcow; rm -rf /']);

        $driver = new RspamdPasswordDriver(timeout: 1);
        $oturum = new RotationSession(null, 'root', 'ssh', static fn () => null);

        $this->expectException(RotationException::class);

        /* Yol kabuk komutuna giriyor; doğrulanmasaydı komutu bölerdi. */
        $driver->changePassword($oturum, 'yoksayilir', 'gecerliParola123');
    }

    #[Test]
    public function ulasilamayan_makinede_baglanti_istisna_atar(): void
    {
        $driver = new RspamdPasswordDriver(timeout: 1);

        $this->expectException(RotationException::class);

        $driver->open($this->kayit(), 'root', 'ssh-parolasi');
    }

    /**
     * PAROLA MAILCOW'UN KENDİ DOSYASINA yazılır.
     *
     * `worker-controller.inc`'e yazmak yetmez: parola DEĞİŞMİŞ görünür ama
     * değişmez. Sebep o dosyanın kendi içinde:
     *
     *     .include(try=true; priority=10)
     *         "$CONFDIR/override.d/worker-controller-password.inc"
     *
     * mailcow denetim parolasını AYRI dosyada tutuyor ve priority=10 ile
     * dâhil ediyor; UCL'de yüksek öncelik kazanıyor, yani ana dosyaya yazılan
     * `enable_password` sessizce eziliyor.
     *
     * Rspamd 4.1.0'da (mailcow) ana dosyaya yazılıp servis yeniden
     * başlatıldıktan sonra HEM yeni HEM eski parola 401 döner — geçerli olan
     * hâlâ ayrı dosyadaki özettir. Doğru dosyaya yazılınca `/auth` 200 ve
     * "auth":"ok".
     */
    #[Test]
    public function parola_mailcowun_kendi_dosyasina_yazilir(): void
    {
        $this->assertSame(
            'data/conf/rspamd/override.d/worker-controller-password.inc',
            (new \ReflectionClass(RspamdPasswordDriver::class))->getConstant('CONFIG'),
        );
    }

    /**
     * HESABI OLMAYAN KAYITTA TUR DÜŞMEZ.
     *
     * Rspamd'ın hesap kavramı yok; kayıtta kullanıcı adı boş duruyor. Tur o
     * alanı doğrudan sürücüye veriyordu ve daha ilk adımda patlıyordu:
     *
     *     changePassword(): Argument #2 ($username) must be of type string,
     *     null given
     *
     * Sürücülerin imzası `string`; "hesap yok" bilgisini boş metin taşır.
     */
    #[Test]
    public function kullanici_adi_bos_kayitta_tur_baslar(): void
    {
        $kayit = Credential::factory()->create([
            'access_type' => AccessType::Panel,
            'host' => 'posta.ornek.test',
            'url' => 'https://posta.ornek.test/rspamd',
            'username' => null,
            'password' => 'eski-parola',
            'rotation_driver' => 'rspamd',
            'rotation_username' => 'root',
            'rotation_password' => 'ssh-parolasi',
        ]);

        $yansima = new \ReflectionMethod(PasswordRotator::class, 'hesapAdi');

        $this->assertSame('', $yansima->invoke(app(PasswordRotator::class), $kayit));
    }

    /**
     * YENİ PAROLA DOĞRULANIR — arayüzün kendi ucundan.
     *
     * Tur "doğrulanamadı" ile kapanıp kullanıcıya elle sınama bırakmaz;
     * uç var: Rspamd 4.1.0'da başlıksız /auth 401, doğru parola ile 200
     * "auth":"ok".
     *
     * Kimlik KULLANICI ADIYLA değil, yalnızca parolayla taşınır: Rspamd'ın
     * hesap kavramı yok.
     */
    #[Test]
    public function yeni_parola_denetim_ucundan_dogrulanir(): void
    {
        Http::fake([
            'posta.ornek.test/rspamd/auth' => Http::response(['auth' => 'ok'], 200),
        ]);

        $kayit = Credential::factory()->create([
            'access_type' => AccessType::Panel,
            'host' => 'posta.ornek.test',
            'url' => 'https://posta.ornek.test/rspamd',
            'username' => null,
            'password' => 'yeni-parola',
            'rotation_driver' => 'rspamd',
        ]);

        $surucu = new RspamdPasswordDriver;

        $this->assertTrue($surucu->canVerify());
        $this->assertTrue($surucu->verify($kayit, '', 'yeni-parola'));

        Http::assertSent(fn ($istek): bool => $istek->header('Password')[0] === 'yeni-parola');
    }

    /** Uç 401 derse parola geçmemiştir; tur bunu doğrulanmış saymaz. */
    #[Test]
    public function reddedilen_parola_dogrulanmaz(): void
    {
        /* Bekleme sınamayı da bekletmesin; ölçülen davranış deneme SAYISI. */
        config(['rotation.rspamd_verify_backoff' => 0]);

        Http::fake([
            'posta.ornek.test/rspamd/auth' => Http::response(['error' => 'Unauthorized'], 401),
        ]);

        $kayit = Credential::factory()->create([
            'access_type' => AccessType::Panel,
            'host' => 'posta.ornek.test',
            'url' => 'https://posta.ornek.test/rspamd',
            'username' => null,
            'password' => 'yanlis',
            'rotation_driver' => 'rspamd',
        ]);

        $this->assertFalse((new RspamdPasswordDriver)->verify($kayit, '', 'yanlis'));

        /*
         * SERVİS KALKARKEN VAZGEÇİLMEZ: 401 tek denemede kabul edilseydi,
         * yeniden başlatmanın ilk saniyelerine denk gelen her tur "parola
         * geçmedi" deyip geri almaya kalkardı.
         */
        Http::assertSentCount(6);
    }
}
