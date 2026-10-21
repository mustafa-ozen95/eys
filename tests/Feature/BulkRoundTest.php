<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OperatingSystem;
use App\Enums\PermissionEffect;
use App\Enums\RotationStage;
use App\Enums\RotationStatus;
use App\Jobs\RotateCredentialPasswordJob;
use App\Models\Credential;
use App\Models\CredentialGroup;
use App\Models\User;
use App\Services\Rotation\BulkRound;
use App\Services\Rotation\RotationLauncher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Toplu turun TEK göstergesi.
 *
 * Toplu turda ilerleme yalnızca kayıtları tek tek açarak görülebiliyordu;
 * üstelik biten turlar beş dakika "sıraya alındı" görünüyordu. Bu sınamalar
 * göstergenin sayıları TUR KAYITLARINDAN okuduğunu, başkasının partisini
 * göstermediğini ve takılı izin artık kalmadığını kilitler.
 */
class BulkRoundTest extends TestCase
{
    use RefreshDatabase;

    private function hazirla(int $adet = 3): array
    {
        $user = User::factory()->create();
        $group = CredentialGroup::factory()->create();

        foreach (['view', 'rotate', 'field.password'] as $action) {
            $user->permissions()->create([
                'resource' => 'credential_group',
                'resource_id' => $group->getKey(),
                'action' => $action,
                'effect' => PermissionEffect::Allow,
            ]);
        }

        $kayitlar = collect(range(1, $adet))->map(fn (int $i) => Credential::factory()->for($group, 'group')->create([
            'name' => "Makine {$i}",
            'operating_system' => OperatingSystem::Linux,
            'host' => "10.0.0.{$i}",
            'username' => 'root',
            'password' => 'eski-parola',
            'rotation_enabled' => true,
        ]));

        return [$user, $kayitlar];
    }

    private function tur(Credential $c, User $u, RotationStatus $durum): void
    {
        $c->rotations()->create([
            'user_id' => $u->getKey(),
            'actor_name' => $u->name,
            'status' => $durum,
            'stage' => $durum === RotationStatus::Running ? RotationStage::Started : RotationStage::PasswordVerified,
        ]);
    }

    private function parti(User $user): string
    {
        return BulkRound::son($user) ?? $this->fail('Parti açılmadı.');
    }

    #[Test]
    public function toplu_tur_tek_parti_acar_ve_ilerlemeyi_tur_kayitlarindan_okur(): void
    {
        Queue::fake();
        [$user, $kayitlar] = $this->hazirla(3);

        $this->actingAs($user)->post(route('credentials.rotate.bulk'), [
            'kapsam' => 'secili',
            'kayitlar' => $kayitlar->pluck('uuid')->all(),
            'reason' => 'Toplu deneme',
        ])->assertRedirect();

        Queue::assertPushed(RotateCredentialPasswordJob::class, 3);
        $parti = $this->parti($user);
        $adres = route('credentials.rotate.bulk.progress', $parti);

        /* Hiçbiri başlamadı: üçü de sırada. */
        $this->actingAs($user)->getJson($adres)->assertOk()
            ->assertJson(['calisiyor' => true, 'toplam' => 3, 'biten' => 0, 'bekleyen' => 3, 'yuzde' => 0]);

        /* Biri bitti, biri sürüyor. */
        $this->tur($kayitlar[0], $user, RotationStatus::Succeeded);
        $this->tur($kayitlar[1], $user, RotationStatus::Running);

        $this->actingAs($user)->getJson($adres)->assertOk()
            ->assertJson(['calisiyor' => true, 'biten' => 1, 'basarili' => 1, 'bekleyen' => 1, 'suren' => 'Makine 2', 'yuzde' => 33])
            /* Her makine bir parça, partideki sırayla. */
            ->assertJsonPath('kayitlar', [
                ['ad' => 'Makine 1', 'durum' => 'basarili'],
                ['ad' => 'Makine 2', 'durum' => 'suruyor'],
                ['ad' => 'Makine 3', 'durum' => 'bekliyor'],
            ]);

        /* Hepsi bitti; biri doğrulanamadı ve ADIYLA bildirilir. */
        $kayitlar[1]->rotations()->update(['status' => RotationStatus::Succeeded]);
        $this->tur($kayitlar[2], $user, RotationStatus::Unverified);

        $this->actingAs($user)->getJson($adres)->assertOk()
            ->assertJson([
                'calisiyor' => false, 'biten' => 3, 'basarili' => 2, 'dogrulanamadi' => 1, 'yuzde' => 100,
                'sorunlu' => [['ad' => 'Makine 3', 'durum' => 'Doğrulanamadı']],
            ]);
    }

    /** Listenin başında TEK şerit çizilir; kayıtları tek tek açmak gerekmez. */
    #[Test]
    public function liste_sayfasi_seridi_cizer_ve_kapatilinca_kaldirir(): void
    {
        Queue::fake();
        [$user, $kayitlar] = $this->hazirla(2);

        $this->actingAs($user)->post(route('credentials.rotate.bulk'), [
            'kapsam' => 'secili',
            'kayitlar' => $kayitlar->pluck('uuid')->all(),
            'reason' => 'Toplu deneme',
        ]);
        $parti = $this->parti($user);

        $this->actingAs($user)->get(route('credentials.index'))->assertOk()
            ->assertSee('data-toplu-kok', false)
            ->assertSee('data-toplu-ilerleme-adres', false)
            ->assertSee('toplu__parca--bekliyor', false);

        /* Bittikten sonra şerit sonucu gösterir ve yoklamaz. */
        $kayitlar->each(fn ($c) => $this->tur($c, $user, RotationStatus::Succeeded));

        $this->actingAs($user)->get(route('credentials.index'))->assertOk()
            ->assertSee('Tamamlandı, hepsi başarılı.')
            ->assertDontSee('data-toplu-ilerleme-adres', false);

        $this->actingAs($user)->delete(route('credentials.rotate.bulk.dismiss', $parti))->assertRedirect();

        /* "Toplu parola güncelleme" metni onay penceresinde de geçiyor; şeridin kendi sınıfına bakılır. */
        $this->actingAs($user)->get(route('credentials.index'))->assertOk()
            ->assertDontSee('data-toplu-kok', false);
    }

    /** Parti kimliği yetki değildir: başkasının partisi "yok"tur. */
    #[Test]
    public function baskasinin_partisi_gorulemez(): void
    {
        Queue::fake();
        [$user, $kayitlar] = $this->hazirla(1);
        [$baskasi] = $this->hazirla(1);

        $this->actingAs($user)->post(route('credentials.rotate.bulk'), [
            'kapsam' => 'secili',
            'kayitlar' => $kayitlar->pluck('uuid')->all(),
            'reason' => 'Toplu deneme',
        ]);

        $this->actingAs($baskasi)
            ->getJson(route('credentials.rotate.bulk.progress', $this->parti($user)))
            ->assertNotFound();
    }

    /** Hiç başlamayan iş göstergeyi sonsuza dek "sürüyor"da bırakmaz. */
    #[Test]
    public function baslamayan_is_sure_dolunca_baslamadi_sayilir(): void
    {
        Queue::fake();
        [$user, $kayitlar] = $this->hazirla(2);

        $this->actingAs($user)->post(route('credentials.rotate.bulk'), [
            'kapsam' => 'secili',
            'kayitlar' => $kayitlar->pluck('uuid')->all(),
            'reason' => 'Toplu deneme',
        ]);
        $parti = $this->parti($user);
        $this->tur($kayitlar[0], $user, RotationStatus::Succeeded);

        $this->travel(10)->minutes();

        $this->actingAs($user)->getJson(route('credentials.rotate.bulk.progress', $parti))->assertOk()
            ->assertJson(['calisiyor' => false, 'basarili' => 1, 'baslamadi' => 1,
                'sorunlu' => [['ad' => 'Makine 2', 'durum' => 'Başlamadı']]]);
    }

    /**
     * "Sıraya alındı" izi tur başlayınca düşer.
     *
     * Toplu turda kayıtlar tek tek yoklanmadığı için iz beş dakika kalıyor,
     * biten tur kayıt panelinde "sıraya alındı" görünüyordu.
     */
    #[Test]
    public function biten_tur_kayit_panelinde_sirada_gorunmez(): void
    {
        Queue::fake();
        [$user, $kayitlar] = $this->hazirla(1);
        $kayit = $kayitlar[0];

        $this->actingAs($user)->post(route('credentials.rotate.bulk'), [
            'kapsam' => 'secili',
            'kayitlar' => [$kayit->uuid],
            'reason' => 'Toplu deneme',
        ]);

        $this->assertNotNull(RotationLauncher::pending($kayit));

        /* İşçi turu açtı ve bitirdi; kimse kaydın panelini yoklamadı. */
        $this->travel(2)->seconds();
        $this->tur($kayit, $user, RotationStatus::Succeeded);

        $this->actingAs($user)->get(route('credentials.index', ['kayit' => $kayit->uuid]))->assertOk()
            ->assertViewHas('selected', fn ($s) => $s['pendingRotation'] === false);
    }
}
