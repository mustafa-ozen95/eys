<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AccessType;
use App\Enums\OperatingSystem;
use App\Models\Credential;
use App\Models\Role;
use App\Models\User;
use App\Policies\CredentialPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Kayıt formunun koşullu alanları.
 *
 * Form her alanı her kayıtta gösteriyordu. Artık alanlar seçime ve kutulara
 * göre görünüyor; KARARI betik uyguluyor (kosullu-alan.js) ama kuralın
 * kendisi ve BAŞLANGIÇ durumu sunucudan geliyor. Bu sınamalar o sözleşmeyi
 * kilitler: kural işareti yerinde mi, dolu bölümün kutusu işaretli mi,
 * kısıt varken "Gelişmiş" açık mı.
 */
class KayitFormuKosulluAlanTest extends TestCase
{
    use RefreshDatabase;

    /** Düzenleme penceresinin HTML'i — sayfada ekleme formu da var. */
    private function duzenleme(Credential $kayit): string
    {
        $govde = $this->actingAs(User::factory()->admin()->create())
            ->get(route('credentials.index', ['kayit' => $kayit->uuid, 'pencere' => 'duzenle']))
            ->assertOk()
            ->getContent();

        $bas = strpos($govde, 'id="kayit-duzenle"');
        $this->assertNotFalse($bas, 'Düzenleme penceresi çizilmedi.');

        return substr($govde, $bas);
    }

    #[Test]
    public function alanlar_kurallariyla_cizilir(): void
    {
        $html = $this->duzenleme(Credential::factory()->create([
            'access_type' => AccessType::Rdp,
            'operating_system' => OperatingSystem::Windows,
        ]));

        $this->assertStringContainsString('data-kosullu-form', $html);
        $this->assertMatchesRegularExpression('/data-goster="access_type:rdp"[^>]*>\s*<label[^>]*for="rdp_security"/', $html);
        $this->assertMatchesRegularExpression('/data-goster="access_type:!ssh,rdp,vnc"[^>]*>\s*<label[^>]*for="url"/', $html);
        $this->assertStringContainsString('data-goster="access_type:panel,web"', $html);

        /* Parola güncelleme bölümü döndürülebilir platformlara ve web/panele açık. */
        $this->assertStringContainsString('data-goster="operating_system:linux,windows,esxi,vcenter,bmc|access_type:panel,web|rotation_driver:*"', $html);
    }

    /**
     * Önce platform, sonra erişim türü — ve tür listesi platforma bağlı.
     */
    #[Test]
    public function platform_erisim_turunden_once_gelir_ve_listeyi_belirler(): void
    {
        $html = $this->duzenleme(Credential::factory()->create([
            'access_type' => AccessType::Rdp,
            'operating_system' => OperatingSystem::Windows,
        ]));

        $this->assertLessThan(strpos($html, 'for="access_type"'), strpos($html, 'for="operating_system"'),
            'Platform erişim türünden önce gelmeli.');

        preg_match('/<select[^>]*name="access_type"[^>]*>/s', $html, $etiket);
        $this->assertStringContainsString('data-bagli="operating_system"', $etiket[0] ?? '', 'Erişim türü platforma bağlı olmalı.');
        preg_match('/data-bagli-secenekler="([^"]+)"/', $html, $m);
        $harita = json_decode(html_entity_decode($m[1] ?? ''), true);

        $this->assertSame('rdp', $harita['windows'][0] ?? null, 'Windows\'ta uzak masaüstü başta olmalı.');
        $this->assertSame(['ilo', 'web'], $harita['bmc'] ?? null);
        $this->assertCount(count(AccessType::cases()), $harita['unknown'] ?? [], '"Belirsiz" her türü kabul etmeli.');
    }

    /** Her platformun listesi dolu, tekrarsız ve yalnızca geçerli türlerden. */
    #[Test]
    public function platform_listeleri_tutarli(): void
    {
        foreach (OperatingSystem::cases() as $os) {
            $turler = array_map(fn (AccessType $t) => $t->value, $os->accessTypes());

            $this->assertNotEmpty($turler, $os->value);
            $this->assertSame($turler, array_values(array_unique($turler)), "{$os->value} listesinde tekrar var.");
        }
    }

    /** Dolu gelen bölümün kutusu işaretli gelir; boş gelenin kutusu boş. */
    #[Test]
    public function dolu_portal_ve_ayri_hesap_kutulari_isaretli_gelir(): void
    {
        $dolu = $this->duzenleme(Credential::factory()->create([
            'access_type' => AccessType::Web,
            'operating_system' => OperatingSystem::Application,
            'login_url' => 'https://ornek.test/giris',
            'login_username_field' => 'user',
            'login_password_field' => 'pass',
            'rotation_username' => 'yonetici',
        ]));

        $this->assertMatchesRegularExpression('/data-kosul="portal_acik"\s+checked/', $dolu);
        $this->assertMatchesRegularExpression('/data-kosul="ayri_hesap"\s+checked/', $dolu);

        $bos = $this->duzenleme(Credential::factory()->create([
            'access_type' => AccessType::Web,
            'operating_system' => OperatingSystem::Application,
        ]));

        $this->assertDoesNotMatchRegularExpression('/data-kosul="portal_acik"\s+checked/', $bos);
        $this->assertDoesNotMatchRegularExpression('/data-kosul="ayri_hesap"\s+checked/', $bos);
    }

    /** Bir kaydı bir role kapatan ayar katlanmış bölümde gözden kaçmamalı. */
    #[Test]
    public function rol_kisiti_varken_gelismis_acik_gelir(): void
    {
        $kayit = Credential::factory()->create();

        $this->assertMatchesRegularExpression('/<details class="kayit-formu__gelismis"\s*>/', $this->duzenleme($kayit));

        $rol = Role::create([
            'slug' => 'sinirli',
            'label' => 'Sınırlı',
            'badge' => 'gray',
            'is_admin' => false,
            'template' => [CredentialPolicy::RESOURCE => ['all' => [], 'groups' => []]],
        ]);
        $kayit->restrictedRoles()->attach($rol);

        $this->assertMatchesRegularExpression('/<details class="kayit-formu__gelismis"\s+open\s*>/', $this->duzenleme($kayit));
    }
}
