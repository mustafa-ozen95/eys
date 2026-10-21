<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ActivityAction;
use App\Jobs\VerifyActivityChainJob;
use App\Models\Credential;
use App\Models\User;
use App\Services\Activity\ActivityChain;
use App\Services\ActivityRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Kayıt ekranları: etkinlik ve hatalar.
 *
 * İkisi tek bölümde, sekmeyle duruyor (menü altı başlıkla kalabalıklaşıyordu).
 * Zincir doğrulaması ise kuyrukta çalışır: bütün satırları yeniden özetlemek
 * büyük kayıtta dakikalar sürer ve tarayıcı onu bekleyemez.
 */
class LogScreenTest extends TestCase
{
    use RefreshDatabase;

    private function yonetici(): User
    {
        return User::factory()->admin()->create();
    }

    #[Test]
    public function hatalar_menude_ayri_bolum_degil_kayitlarin_sekmesidir(): void
    {
        $admin = $this->yonetici();

        $html = $this->actingAs($admin)->get(route('logs.index'))->assertOk()->getContent();

        /* Üst menüde "Hatalar" bağlantısı yok... */
        $this->assertDoesNotMatchRegularExpression(
            '/class="topbar__link"[^>]*>\s*Hatalar/',
            $html,
            'Hatalar üst menüde ayrı bir bölüm olmamalı.',
        );

        /* ...ama sekme şeridinde var ve ekran erişilebilir. */
        $this->assertMatchesRegularExpression('/class="tabs__link"[^>]*>\s*Hatalar/', $html);

        $this->actingAs($admin)->get(route('errors.index'))->assertOk()->assertSee('Etkinlik');
    }

    /** Hata ekranındayken üst menüde "Kayıtlar" seçili kalmalı. */
    #[Test]
    public function hata_ekraninda_kayitlar_bolumu_secili_kalir(): void
    {
        $html = $this->actingAs($this->yonetici())->get(route('errors.index'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/class="topbar__link"\s*\n?\s*aria-current="page"\s*>Kayıtlar/u',
            preg_replace('/\s+/', ' ', $html) ?? '',
            'Hata ekranı da "Kayıtlar" bölümünün içindedir.',
        );
    }

    /**
     * Kayıt süzgeci: bir kaydın geçmişi ADIYLA değil KİMLİĞİYLE bulunur.
     *
     * Ada göre arama "Portal" derken "Portal iLO"yu da getirir; detay ekranındaki
     * "Tümü" bağlantısı tam olarak o kaydı göstermeli.
     */
    #[Test]
    public function kayitlar_ekrani_tek_bir_kayda_suzulebilir(): void
    {
        $admin = $this->yonetici();

        $bu = Credential::factory()->create(['name' => 'Portal']);
        $oteki = Credential::factory()->create(['name' => 'Portal iLO']);

        $kaydet = fn (Credential $c, string $ozet) => app(ActivityRecorder::class)
            ->record(ActivityAction::Rotated, $ozet, $c, null, $admin);

        $kaydet($bu, 'Bu kaydin turu');
        $kaydet($oteki, 'Otekinin turu');

        $this->actingAs($admin)
            ->get(route('logs.index', ['kayit' => $bu->uuid]))
            ->assertOk()
            ->assertSee('Bu kaydin turu')
            ->assertDontSee('Otekinin turu')
            ->assertSee('Süzgeci kaldır');
    }

    /**
     * Çözülemeyen kimlik süzgeci SESSİZCE DÜŞÜRÜR.
     *
     * Kimlik, kullanıcının görebildiği kayıtlar arasında aranır; eşleşme
     * yoksa süzgeç uygulanmaz ve ekran boş görünmez. Göremediği bir kaydın
     * kimliğini adrese yazan biri de böylece o kaydın geçmişini süzemez.
     */
    #[Test]
    public function cozulemeyen_kayit_kimligi_suzgeci_dusurur(): void
    {
        $admin = $this->yonetici();
        $gizli = Credential::factory()->create(['name' => 'Gizli kayit']);

        app(ActivityRecorder::class)->record(ActivityAction::Rotated, 'Gizli turu', $gizli, null, $admin);

        $this->actingAs($admin)
            ->get(route('logs.index', ['kayit' => '01a00000-0000-7000-8000-000000000000']))
            ->assertOk()
            ->assertSee('Gizli turu')
            ->assertDontSee('Süzgeci kaldır');
    }

    /**
     * Doğrulama İSTEK İÇİNDE çalışmaz.
     *
     * Bütün satırlar yeniden özetlenir; yüz binlik bir kayıtta istek zaman
     * aşımına uğrar ve doğrulama hiç bitmezdi.
     */
    #[Test]
    public function zincir_dogrulamasi_kuyruga_alinir(): void
    {
        Queue::fake();

        $this->actingAs($this->yonetici())
            ->post(route('logs.verify'))
            ->assertRedirect()
            ->assertSessionHas('status', fn (string $m): bool => str_contains($m, 'sıraya alındı'));

        Queue::assertPushed(VerifyActivityChainJob::class, 1);
        $this->assertTrue(app(ActivityChain::class)->isRunning());
    }

    /** Süren doğrulama ikinci kez kuyruğa girmez. */
    #[Test]
    public function suren_dogrulama_tekrar_baslatilmaz(): void
    {
        Queue::fake();

        $chain = app(ActivityChain::class);
        $chain->markRunning();

        $this->actingAs($this->yonetici())
            ->post(route('logs.verify'))
            ->assertSessionHas('status', fn (string $m): bool => str_contains($m, 'zaten sürüyor'));

        Queue::assertNothingPushed();
    }

    #[Test]
    public function is_bitince_suruyor_isareti_kalkar_ve_sonuc_gorunur(): void
    {
        $admin = $this->yonetici();

        app(ActivityRecorder::class)->record(ActivityAction::LoggedIn, 'giris', null, null, $admin);

        $chain = app(ActivityChain::class);
        $chain->markRunning();

        (new VerifyActivityChainJob)->handle($chain);

        $this->assertFalse($chain->isRunning(), 'İş bitince ekran sonsuza dek "sürüyor" dememeli.');

        $this->actingAs($admin)
            ->get(route('logs.index'))
            ->assertOk()
            ->assertSee('Zincir bütün.')
            ->assertDontSee('Doğrulanıyor…');
    }

    /** İş kuyrukta düşse bile ekran takılı kalmamalı. */
    #[Test]
    public function is_duserse_suruyor_isareti_temizlenir(): void
    {
        $chain = app(ActivityChain::class);
        $chain->markRunning();

        (new VerifyActivityChainJob)->failed();

        $this->assertFalse($chain->isRunning());
    }
}
