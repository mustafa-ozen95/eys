<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OperatingSystem;
use App\Enums\RotationStage;
use App\Enums\RotationStatus;
use App\Models\Credential;
use App\Models\PasswordRotation;
use App\Models\User;
use App\Services\Rotation\RotationLauncher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakePasswordDriver;
use Tests\TestCase;

/**
 * Süren turun ekrandaki ilerlemesi.
 *
 * Tur kuyruğa alınıyor ve cihaza göre yarım dakikayı bulabiliyor; o süre
 * boyunca panel susmamalı. Çubuğun tek görevi DOĞRU söylemek:
 * ilerleme sunucudaki aşamadan gelir, tahmin edilmez.
 */
class RotationProgressTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['rotation.drivers' => ['ssh' => FakePasswordDriver::class]]);
        $this->app->instance(FakePasswordDriver::class, new FakePasswordDriver);
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

    private function tur(Credential $credential, array $ustune = []): PasswordRotation
    {
        return $credential->rotations()->create([
            'actor_name' => 'sınama',
            'status' => RotationStatus::Running,
            'stage' => RotationStage::Started,
            ...$ustune,
        ]);
    }

    #[Test]
    public function kurtarma_hesapli_sira_alti_adimdir(): void
    {
        $tur = $this->tur($this->kayit(), [
            'recovery_username' => 'eys-kurtarma-1',
            'stage' => RotationStage::PasswordChanged,
        ]);

        $this->assertSame(6, $tur->totalSteps());
        $this->assertSame(4, $tur->completedSteps());
        $this->assertSame(67, $tur->progress());
    }

    /**
     * Kurtarma hesabı açmayan sıra ÜÇ adımdır.
     *
     * Altı adımlık çubuk, üç adımda biten bir turu yarıda kalmış gösterirdi.
     */
    #[Test]
    public function kurtarmasiz_sira_uc_adimdir(): void
    {
        $tur = $this->tur($this->kayit(), ['stage' => RotationStage::PasswordChanged]);

        $this->assertSame(3, $tur->totalSteps());
        $this->assertSame(2, $tur->completedSteps());
        $this->assertSame(67, $tur->progress());
    }

    /**
     * Biten tur her zaman DOLUDUR — başarısız bitse bile.
     *
     * Yarım kalmış bir çubuk, hâlâ bir şey oluyormuş izlenimi verirdi; turun
     * sonucu rozette yazıyor.
     */
    #[Test]
    public function biten_tur_dolu_gosterilir(): void
    {
        $tur = $this->tur($this->kayit(), ['status' => RotationStatus::Failed]);

        $this->assertSame(100, $tur->progress());
    }

    #[Test]
    public function suren_tur_ucu_asamayi_bildirir(): void
    {
        $user = User::factory()->admin()->create();
        $credential = $this->kayit();

        $this->tur($credential, [
            'recovery_username' => 'eys-kurtarma-1',
            'stage' => RotationStage::RecoveryVerified,
        ]);

        $this->actingAs($user)
            ->getJson(route('credentials.rotate.progress', $credential))
            ->assertOk()
            ->assertJson([
                'running' => true,
                'step' => 3,
                'total' => 6,
                'percent' => 50,
                'stage' => 'recovery_verified',
            ]);
    }

    /**
     * Uç PAROLA SIZDIRMAZ.
     *
     * Denenen parola, kurtarma parolası ve önceki parola turun en hassas
     * alanları; ekranın ilerleme çubuğu için hiçbirine ihtiyacı yok.
     */
    #[Test]
    public function uc_parola_dondurmez(): void
    {
        $user = User::factory()->admin()->create();
        $credential = $this->kayit();

        $this->tur($credential, [
            'recovery_username' => 'eys-kurtarma-1',
            'recovery_password' => 'kurtarma-parolasi',
            'attempted_password' => 'denenen-parola',
            'previous_password' => 'eski-parola',
        ]);

        $govde = $this->actingAs($user)
            ->getJson(route('credentials.rotate.progress', $credential))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('kurtarma-parolasi', $govde);
        $this->assertStringNotContainsString('denenen-parola', $govde);
        $this->assertStringNotContainsString('eski-parola', $govde);
    }

    /**
     * SIRAYA ALINDI ama işçi henüz başlamadı.
     *
     * Tur kaydı işçi çalışınca açılıyor. Arada kalan saniyelerde ekranın
     * "bir şey olmuyor" demesi, kullanıcıyı düğmeye ikinci kez basmaya
     * iterdi.
     */
    #[Test]
    public function kuyrukta_bekleyen_tur_de_suruyor_sayilir(): void
    {
        Queue::fake();

        $user = User::factory()->admin()->create();
        $credential = $this->kayit();

        app(RotationLauncher::class)->launch([$credential], $user, 'sınama');

        $this->actingAs($user)
            ->getJson(route('credentials.rotate.progress', $credential))
            ->assertOk()
            ->assertJson(['running' => true, 'status' => 'queued']);
    }

    /**
     * Sıradaki turun adım sayısı SÜRÜCÜDEN gelir.
     *
     * Sabit "0/6" yazılıyordu; kurtarma hesabı açmayan sürücüde tur üç
     * adımdır ve işçi başlayınca gösterge "0/3"e düşüyordu.
     */
    #[Test]
    public function siradaki_tur_suruculun_adim_sayisini_gosterir(): void
    {
        Queue::fake();

        $user = User::factory()->admin()->create();
        $surucu = $this->app->make(FakePasswordDriver::class);

        foreach ([[false, 3], [true, 6]] as [$kurtarma, $adim]) {
            $surucu->supportsRecovery = $kurtarma;
            $credential = $this->kayit();

            app(RotationLauncher::class)->launch([$credential], $user, 'sınama');

            $this->actingAs($user)
                ->getJson(route('credentials.rotate.progress', $credential))
                ->assertOk()
                ->assertJson(['status' => 'queued', 'step' => 0, 'total' => $adim]);

            $this->actingAs($user)
                ->get(route('credentials.index', ['kayit' => $credential->uuid]))
                ->assertOk()
                ->assertSee('data-progress-step>0/'.$adim.'<', false);
        }
    }

    #[Test]
    public function dondurme_yetkisi_olmayan_asamayi_goremez(): void
    {
        $credential = $this->kayit();
        $this->tur($credential);

        $this->actingAs(User::factory()->create())
            ->getJson(route('credentials.rotate.progress', $credential))
            ->assertForbidden();
    }
}
