<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Credential;
use App\Models\CredentialGroup;
use App\Models\Role;
use App\Models\User;
use App\Policies\CredentialPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Cihaz arayüzünü açmak için parolayı GÖRMEK gerekmez.
 *
 * İki yol farklı çalışıyor:
 *
 *   portal fırlatma  parolayı gizli alana koyup KULLANICININ TARAYICISINDAN
 *                    hedefe POST ediyor — orada görme yetkisi aranır.
 *   arayüz vekili    oturumu SUNUCUDA açıyor, çerezi panelde tutuyor; parola
 *                    tarayıcıya hiç gitmiyor.
 *
 * İkisi tek yetenekle sorulduğu sürece, vekilden açılabilecek bir arayüz
 * yalnızca parolayı görebilenlere görünüyordu. Oysa özelliğin amacı tam
 * tersi: parolayı bilmeden erişebilmek.
 *
 * Konsolda (connect) aynı karar zaten verilmişti; bu onun web arayüzü hâli.
 */
class ArayuzOturumYetkisiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * Vekilden açılabilen türler AYARDAN geliyor ve sınama ortamında
         * varsayılan kapalı. Sınanan şey yetki; hangi türlerin açık olduğu
         * ayrı bir karar, o yüzden burada açıkça kuruluyor.
         */
        config(['remote.arayuz.turler' => ['plesk', 'esxi', 'vami', 'ilo']]);
    }

    /** Plesk: vekilden açılabilen bir kayıt (login_up.php'den tanınır). */
    private function pleskKaydi(CredentialGroup $grup): Credential
    {
        return Credential::factory()->create([
            'credential_group_id' => $grup->getKey(),
            'name' => 'Plesk Panel',
            'access_type' => 'web',
            'operating_system' => 'application',
            'host' => 'ornek.test',
            'url' => 'https://ornek.test:8443',
            'login_url' => 'https://ornek.test:8443/login_up.php',
            'username' => 'admin',
            'password' => 'gizli',
        ]);
    }

    /** @param list<string> $aksiyonlar */
    private function kullanici(CredentialGroup $grup, array $aksiyonlar): User
    {
        $rol = Role::create([
            'slug' => 'sinama-'.substr(md5(implode(',', $aksiyonlar)), 0, 8),
            'label' => 'Sınama',
            'badge' => 'gray',
            'is_admin' => false,
            'template' => [CredentialPolicy::RESOURCE => [
                'all' => [],
                'groups' => [$grup->name => $aksiyonlar],
            ]],
        ]);

        return User::factory()->create(['role_id' => $rol->getKey()]);
    }

    #[Test]
    public function parolayi_goremeyen_arayuzu_acabilir(): void
    {
        $grup = CredentialGroup::factory()->create(['name' => 'Paneller']);
        $kayit = $this->pleskKaydi($grup);

        /* Parolayı görme yetkisi YOK; oturum açma yetkisi var. */
        $kullanici = $this->kullanici($grup, ['view', 'session']);

        $this->assertNotNull($kayit->arayuz_turu, 'Kayıt vekilden açılabilir olmalı.');
        $this->assertTrue($kullanici->can('arayuz', $kayit), 'Parolayı görmeden arayüz açılabilmeli.');

        /* Portal fırlatma AYRI ve hâlâ parola yetkisi istiyor. */
        $this->assertFalse($kullanici->can('session', $kayit));
    }

    #[Test]
    public function kaydi_goremeyen_arayuzu_acamaz(): void
    {
        $grup = CredentialGroup::factory()->create(['name' => 'Paneller']);
        $kayit = $this->pleskKaydi($grup);

        /* Oturum yetkisi var ama kaydı göremiyor. */
        $kullanici = $this->kullanici($grup, ['session']);

        $this->assertFalse($kullanici->can('arayuz', $kayit), 'Göremediğiniz cihazın arayüzü açılamaz.');
    }

    #[Test]
    public function oturum_yetkisi_olmayan_acamaz(): void
    {
        $grup = CredentialGroup::factory()->create(['name' => 'Paneller']);
        $kayit = $this->pleskKaydi($grup);

        $kullanici = $this->kullanici($grup, ['view', 'field.password']);

        $this->assertFalse($kullanici->can('arayuz', $kayit));
    }

    /** Uç de aynı yeteneği sorar: ekranı gizlemek yetmez. */
    #[Test]
    public function uc_de_ayni_yetenegi_sorar(): void
    {
        $grup = CredentialGroup::factory()->create(['name' => 'Paneller']);
        $kayit = $this->pleskKaydi($grup);

        $this->actingAs($this->kullanici($grup, ['view', 'field.password']))
            ->post(route('credentials.arayuz', $kayit), ['reason' => 'sınama'])
            ->assertForbidden();
    }

    /**
     * ROTA ARA KATMANI DA AYNI YETENEĞİ SORAR.
     *
     * İstek sınıfını değiştirmek yetmedi: denetleyicinin ara katmanı ve
     * vekil bekçisi hâlâ "session" soruyordu ve uç 403 dönüyordu. Aynı kural
     * üç yerde duruyordu; bu sınama ikisini birden kapsıyor.
     */
    #[Test]
    public function parolayi_goremeyen_ucu_gercekten_kullanabilir(): void
    {
        $grup = CredentialGroup::factory()->create(['name' => 'Paneller']);
        $kayit = $this->pleskKaydi($grup);
        $kullanici = $this->kullanici($grup, ['view', 'session']);

        /*
         * CİHAZA GİDİLMEZ: sınanan şey yetki kapısı, Plesk'in kendisi değil.
         * Sahte yanıt, girişin başarılı olduğunu söyleyen çerezi döndürür.
         */
        Http::fake([
            '*' => Http::response('', 303, ['Set-Cookie' => 'PLESKSESSID=sinama; path=/']),
        ]);

        /* 403 DÖNMEMELİ; yetki kapısı açık olmalı. */
        $this->actingAs($kullanici)
            ->post(route('credentials.arayuz', $kayit), ['reason' => 'sınama'])
            ->assertStatus(302);
    }
}
