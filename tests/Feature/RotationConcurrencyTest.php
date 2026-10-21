<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OperatingSystem;
use App\Enums\RotationStatus;
use App\Jobs\RepairRotationJob;
use App\Jobs\RotateCredentialPasswordJob;
use App\Models\Credential;
use App\Models\User;
use App\Services\Rotation\PasswordRotator;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakePasswordDriver;
use Tests\TestCase;

/**
 * Aynı kayda İKİ TUR AYNI ANDA girmez.
 *
 * Tetikleyen dört yol var: tek kayıt düğmesi, toplu güncelleme, zamanlanmış
 * görev ve konsol. İkisi çakışsaydı ikisi de "önceki parola" olarak kasadaki
 * değeri okur, ikisi de cihazda parolayı değiştirir ve kasaya son yazan
 * kalırdı: cihazdaki parola ile kasadaki AYRI DÜŞERDİ. Bir parola kasası için
 * en kötü sonuç budur, üstelik fark ancak bir sonraki girişte anlaşılırdı.
 *
 * NOT: testler `array` önbelleğiyle koşar, yani burada sınanan şey KİLİT
 * MANTIĞIDIR. Üretimdeki depo (database) aynı davranır: kilitler cache_locks
 * tablosunda tutulur, ikinci kilit reddedilir, bırakılınca yeniden alınır.
 */
class RotationConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private FakePasswordDriver $device;

    protected function setUp(): void
    {
        parent::setUp();

        $this->device = new FakePasswordDriver(['root' => 'eski-parola']);

        config(['rotation.drivers' => ['ssh' => FakePasswordDriver::class]]);
        $this->app->instance(FakePasswordDriver::class, $this->device);
    }

    private function kayit(): Credential
    {
        return Credential::factory()->create([
            'operating_system' => OperatingSystem::Linux,
            'host' => '10.0.0.9',
            'username' => 'root',
            'password' => 'eski-parola',
            'rotation_enabled' => true,
        ]);
    }

    /**
     * Kilit BAŞKASINDAYKEN tur başlamaz ve MAKİNEYE DOKUNULMAZ.
     *
     * Kilidi elde tutmak, başka bir işçinin o kayıtla meşgul olmasını taklit
     * eder.
     */
    #[Test]
    public function suren_tur_varken_ikincisi_baslamaz(): void
    {
        $credential = $this->kayit();

        $lock = Cache::lock('erisim:dondurme:'.$credential->getKey(), 60);
        $this->assertTrue($lock->get(), 'Kilit alınamadı.');

        $oncesi = $this->device->accounts;

        $rotation = app(PasswordRotator::class)->rotate($credential);

        $this->assertSame(RotationStatus::Failed, $rotation->status);
        $this->assertStringContainsString('zaten sürüyor', (string) $rotation->message);

        /* En önemlisi: cihaza HİÇ dokunulmamış olmalı. */
        $this->assertSame($oncesi, $this->device->accounts);
        $this->assertSame('eski-parola', $credential->fresh()->password);

        $lock->release();
    }

    /** Kilit bırakıldıktan sonra normal tur yine çalışır. */
    #[Test]
    public function kilit_birakilinca_tur_yeniden_calisir(): void
    {
        $credential = $this->kayit();

        $lock = Cache::lock('erisim:dondurme:'.$credential->getKey(), 60);
        $lock->get();
        app(PasswordRotator::class)->rotate($credential);
        $lock->release();

        $rotation = app(PasswordRotator::class)->rotate($credential);

        $this->assertSame(RotationStatus::Succeeded, $rotation->status);
        $this->assertNotSame('eski-parola', $credential->fresh()->password);
    }

    /** Başarılı tur kilidi BIRAKIR: kayıt süresiz kilitli kalmamalı. */
    #[Test]
    public function tur_bitince_kilit_birakilir(): void
    {
        $credential = $this->kayit();

        app(PasswordRotator::class)->rotate($credential);

        $lock = Cache::lock('erisim:dondurme:'.$credential->getKey(), 60);

        $this->assertTrue($lock->get(), 'Tur bittiğinde kilit bırakılmalı.');

        $lock->release();
    }

    /** Düşen tur da kilidi bırakır; yoksa kayıt onarılana dek kilitli kalırdı. */
    #[Test]
    public function tur_duserse_de_kilit_birakilir(): void
    {
        $credential = $this->kayit();

        $this->device->failChangePassword = true;

        app(PasswordRotator::class)->rotate($credential);

        $lock = Cache::lock('erisim:dondurme:'.$credential->getKey(), 60);

        $this->assertTrue($lock->get(), 'Düşen tur da kilidi bırakmalı.');

        $lock->release();
    }

    /**
     * Farklı kayıtlar birbirini BEKLEMEZ.
     *
     * Kilit kayıt başına olmasaydı toplu güncelleme sıraya girer, yüz kayıt
     * tek tek işlenirdi.
     */
    #[Test]
    public function farkli_kayitlar_birbirini_beklemez(): void
    {
        $ilk = $this->kayit();
        $ikinci = $this->kayit();

        $lock = Cache::lock('erisim:dondurme:'.$ilk->getKey(), 60);
        $lock->get();

        $rotation = app(PasswordRotator::class)->rotate($ikinci);

        $this->assertSame(RotationStatus::Succeeded, $rotation->status);

        $lock->release();
    }

    /**
     * Kuyruk tarafı: aynı kayıt için ikinci iş SIRAYA GİRMEZ.
     *
     * Asıl koruma turdaki kilit; bu, gereksiz işi hiç almamak için.
     */
    #[Test]
    public function ayni_kayit_icin_ikinci_is_siraya_girmez(): void
    {
        $credential = $this->kayit();
        $actor = User::factory()->admin()->create();

        $job = new RotateCredentialPasswordJob($credential, $actor, 'gerekce');

        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertSame((string) $credential->getKey(), $job->uniqueId());

        /* Tekillik süresi işin zaman aşımından uzun olmalı. */
        $this->assertGreaterThan($job->timeout, $job->uniqueFor);
    }

    /**
     * Onarım da AYNI kilidi bekler.
     *
     * Onarım cihaza bağlanıp kurtarma hesabını siler. Süren bir turun
     * ortasında çalışsaydı, o turun az önce açtığı hesabı silebilir ya da
     * kasadaki parolayı yanlış değerle güncelleyebilirdi.
     */
    #[Test]
    public function suren_tur_varken_onarim_yapilmaz(): void
    {
        $credential = $this->kayit();

        /* Önce yarıda kalmış bir tur oluşturalım. */
        $this->device->failNewPasswordVerify = true;
        $rotation = app(PasswordRotator::class)->rotate($credential);

        $this->assertTrue($rotation->fresh()->recovery_active, 'Tur yarıda kalmalıydı.');

        $lock = Cache::lock('erisim:dondurme:'.$credential->getKey(), 60);
        $this->assertTrue($lock->get());

        $hesaplar = $this->device->accounts;

        app(PasswordRotator::class)->repair($rotation->fresh());

        /* Kurtarma hesabı DURUYOR: onarım ertelenmiş olmalı. */
        $this->assertSame($hesaplar, $this->device->accounts);
        $this->assertTrue($rotation->fresh()->recovery_active);

        $lock->release();

        /* Kilit bırakılınca onarım normal çalışır. */
        app(PasswordRotator::class)->repair($rotation->fresh());

        $this->assertFalse($rotation->fresh()->recovery_active);
    }

    /** Onarım işi de aynı tur için ikinci kez sıraya girmez. */
    #[Test]
    public function ayni_tur_icin_ikinci_onarim_isi_siraya_girmez(): void
    {
        $credential = $this->kayit();

        $this->device->failNewPasswordVerify = true;
        $rotation = app(PasswordRotator::class)->rotate($credential);

        $job = new RepairRotationJob($rotation);

        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertSame((string) $rotation->getKey(), $job->uniqueId());
        $this->assertGreaterThan($job->timeout, $job->uniqueFor);
    }
}
