<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AccessType;
use App\Enums\OperatingSystem;
use App\Models\Credential;
use App\Models\CredentialGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Gerekçe YALNIZCA "kritik" işaretli kayıtlarda istenir.
 *
 * Her işlemde zorunlu olsaydı bir web sunucusunun konsolunu açan kişi
 * kutuya "test" yazıp geçerdi; alan dolar, denetim hiçbir şey öğrenmezdi.
 * Bu yüzden soru az sayıda kayıtta sorulur, orada da gerçekten
 * yanıtlansın diye.
 *
 * BURADA SINANAN ASIL ŞEY, kararın İKİ UÇTA DA AYNI OLMASI. Ekranda alanı
 * gizleyip sunucuda zorunlu bırakmak kullanıcıyı doldurmadığı bir alan
 * yüzünden reddederdi; tersi ise gerekçeyi tümüyle yok ederdi. Karar tek
 * yerde durur: App\Support\ReasonRule.
 */
class ReasonAskedOnCriticalRecordTest extends TestCase
{
    use RefreshDatabase;

    private function kayit(bool $kritik): Credential
    {
        return Credential::factory()->create([
            'credential_group_id' => CredentialGroup::factory()->create()->getKey(),
            'is_critical' => $kritik,
        ]);
    }

    #[Test]
    public function kritik_olmayan_kayit_gerekcesiz_silinir(): void
    {
        $kayit = $this->kayit(false);

        $this->actingAs(User::factory()->admin()->create())
            ->delete(route('credentials.destroy', $kayit))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('credentials', ['id' => $kayit->getKey()]);
    }

    #[Test]
    public function kritik_kayit_gerekcesiz_silinemez(): void
    {
        $kayit = $this->kayit(true);

        $this->actingAs(User::factory()->admin()->create())
            ->delete(route('credentials.destroy', $kayit))
            ->assertSessionHasErrors('reason');

        $this->assertDatabaseHas('credentials', ['id' => $kayit->getKey()]);
    }

    #[Test]
    public function kritik_kayit_gerekceyle_silinir(): void
    {
        $kayit = $this->kayit(true);

        $this->actingAs(User::factory()->admin()->create())
            ->delete(route('credentials.destroy', $kayit), ['reason' => 'Cihaz hizmetten çıktı'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('credentials', ['id' => $kayit->getKey()]);
    }

    /**
     * Gerekçesiz silmede denetim satırı BOŞ DİZE değil, NULL taşır.
     *
     * Boş dize "gerekçe verilmiş ama içi boş" gibi okunurdu; yokluk açıkça
     * yokluk olarak dursun (bkz. ActivityRecorder).
     */
    #[Test]
    public function gerekcesiz_silme_kayda_bos_dize_yazmaz(): void
    {
        $kayit = $this->kayit(false);

        $this->actingAs(User::factory()->admin()->create())
            ->delete(route('credentials.destroy', $kayit));

        $this->assertDatabaseHas('activity_logs', [
            'subject_id' => $kayit->getKey(),
            'reason' => null,
        ]);
    }

    /**
     * TOPLU İŞLEM İŞARETE BAKMAZ.
     *
     * Hangi kayda bakılacağı belli değil; üstelik kapsamı en geniş, geri
     * dönüşü en zor işlemler bunlar.
     */
    #[Test]
    public function kritik_olmayan_kayitlarda_bile_toplu_cikti_gerekce_ister(): void
    {
        $kayit = $this->kayit(false);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('credentials.export'), ['kapsam' => 'hepsi'])
            ->assertSessionHasErrors('reason');
    }

    /**
     * EKRAN İLE SUNUCU AYNI ŞEYİ SÖYLER.
     *
     * Silme penceresi kalır (yazılı onay ister) ama gerekçe alanı kritik
     * olmayan kayıtta gizlenir: data-confirm-reasonless.
     */
    #[Test]
    public function onay_penceresi_ayni_karari_uygular(): void
    {
        $yonetici = User::factory()->admin()->create();

        $sade = $this->kayit(false);
        $this->actingAs($yonetici)
            ->get(route('credentials.index', ['grup' => $sade->group->uuid, 'kayit' => $sade->uuid]))
            ->assertSee('data-confirm-reasonless', false);

        $kritik = $this->kayit(true);
        $this->actingAs($yonetici)
            ->get(route('credentials.index', ['grup' => $kritik->group->uuid, 'kayit' => $kritik->uuid]))
            ->assertDontSee('data-confirm-reasonless', false)
            ->assertSee('kritik işaretli');
    }

    /**
     * SORACAK BİR ŞEYİ OLMAYAN PENCERE HİÇ AÇILMAZ.
     *
     * Oturum açma düğmesinde yazılı onay yok; gerekçe de sorulmuyorsa geriye
     * yalnızca "Tamam"a basmak kalıyordu; böyle bir pencerenin açılmasına
     * gerek yok. Kritik kayıtta pencere geri gelir.
     */
    #[Test]
    public function kritik_olmayan_kayitta_oturum_dugmesi_pencere_acmaz(): void
    {
        $yonetici = User::factory()->admin()->create();
        $grup = CredentialGroup::factory()->create();

        $portal = fn (bool $kritik): Credential => Credential::factory()->create([
            'credential_group_id' => $grup->getKey(),
            'access_type' => AccessType::Membership,
            'operating_system' => OperatingSystem::Portal,
            'host' => null,
            'port' => null,
            'url' => 'https://portal.ornek.test',
            'login_url' => 'https://portal.ornek.test/giris',
            'login_username_field' => 'login_user',
            'login_password_field' => 'pass_user',
            'username' => 'portal-uye',
            'password' => 'PORTAL-PAROLASI',
            'is_critical' => $kritik,
        ]);

        $sade = $portal(false);
        $this->actingAs($yonetici)
            ->get(route('credentials.index', ['grup' => $grup->uuid, 'kayit' => $sade->uuid]))
            ->assertSee('Oturum aç')
            ->assertDontSee('data-confirm-title="Portal oturumu aç"', false);

        $kritik = $portal(true);
        $this->actingAs($yonetici)
            ->get(route('credentials.index', ['grup' => $grup->uuid, 'kayit' => $kritik->uuid]))
            ->assertSee('data-confirm-title="Portal oturumu aç"', false);
    }

    /** İşaret form üzerinden konulup kaldırılabilir. */
    #[Test]
    public function isaret_formdan_yonetilir(): void
    {
        $kayit = $this->kayit(false);
        $yonetici = User::factory()->admin()->create();

        $alanlar = [
            'group' => $kayit->group->uuid,
            'name' => $kayit->name,
            'access_type' => $kayit->access_type->value,
            'operating_system' => $kayit->operating_system->value,
        ];

        $this->actingAs($yonetici)
            ->put(route('credentials.update', $kayit), $alanlar + ['is_critical' => '1'])
            ->assertSessionHasNoErrors();

        $this->assertTrue($kayit->fresh()->is_critical, 'İşaret konulabilmeli.');

        $this->actingAs($yonetici)
            ->put(route('credentials.update', $kayit), $alanlar)
            ->assertSessionHasNoErrors();

        $this->assertFalse($kayit->fresh()->is_critical, 'İşaret kaldırılabilmeli.');
    }
}
