<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PermissionEffect;
use App\Models\ApiToken;
use App\Models\Credential;
use App\Models\CredentialGroup;
use App\Models\User;
use App\Models\UserPermission;
use App\Policies\CredentialPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Kasanın dışarıya açtığı arayüz.
 *
 * NEDEN VAR: aynı sunucunun parolası iki panelde birden duruyordu ve kasa
 * parolayı döndürünce diğerindeki kopya sessizce eskiyordu. Kopyanın kendi
 * kendini tazeleyebilmesi için kasa okunabilir olmalı.
 *
 * Testlerin kilitlediği şey "okunabiliyor mu" DEĞİL, kimin neyi okuyabildiği:
 * jeton sahibini aşamamalı, göremediği kayıt onun için var olmamalı ve sıralı
 * birincil anahtar hiçbir yerden dışarı çıkmamalı.
 */
class ApiTest extends TestCase
{
    use RefreshDatabase;

    private function kayit(string $grupAdi = 'Grup A'): Credential
    {
        return Credential::factory()
            ->for(CredentialGroup::factory()->state(['name' => $grupAdi]), 'group')
            ->create(['host' => '10.0.0.5', 'username' => 'root', 'password' => 'gizli-parola']);
    }

    /**
     * Belirli bir grupta, belirli aksiyonlara izinli kullanıcı.
     *
     * @param  list<string>  $aksiyonlar
     */
    private function kullanici(CredentialGroup $grup, array $aksiyonlar): User
    {
        $kullanici = User::factory()->create();

        foreach ($aksiyonlar as $aksiyon) {
            UserPermission::create([
                'user_id' => $kullanici->id,
                'resource' => CredentialPolicy::RESOURCE,
                'resource_id' => $grup->id,
                'action' => $aksiyon,
                'effect' => PermissionEffect::Allow,
            ]);
        }

        return $kullanici;
    }

    /**
     * @param  array<string, list<string>>  $yetkiler
     * @return array{0: ApiToken, 1: string}
     */
    private function jeton(User $sahip, array $yetkiler): array
    {
        $yeni = ApiToken::mint();

        $jeton = ApiToken::create([
            'name' => 'NYS',
            'user_id' => $sahip->id,
            'token_hash' => $yeni['hash'],
            'prefix' => $yeni['prefix'],
            'abilities' => $yetkiler,
        ]);

        return [$jeton, $yeni['plain']];
    }

    /* ── Kapı ── */

    #[Test]
    public function jetonsuz_istek_reddedilir(): void
    {
        $this->getJson('/api/v1/kayitlar')->assertUnauthorized();
    }

    #[Test]
    public function gecersiz_jeton_reddedilir(): void
    {
        $this->withHeader('Authorization', 'Bearer uydurma')
            ->getJson('/api/v1/kayitlar')
            ->assertUnauthorized();
    }

    /**
     * OTURUM ÇEREZİ API'ye GEÇMEZ.
     *
     * Geçseydi tarayıcıdan gelen bir istek CSRF korumasının dışına çıkardı.
     */
    #[Test]
    public function oturum_cerezi_api_kapisini_acmaz(): void
    {
        $kayit = $this->kayit();

        $this->actingAs($this->kullanici($kayit->group, ['view']))
            ->getJson('/api/v1/kayitlar')
            ->assertUnauthorized();
    }

    #[Test]
    public function iptal_edilmis_jeton_calismaz(): void
    {
        $kayit = $this->kayit();
        [$jeton, $duz] = $this->jeton($this->kullanici($kayit->group, ['view']), [
            CredentialPolicy::RESOURCE => ['view'],
        ]);

        $jeton->revoke();

        $this->withToken($duz)->getJson('/api/v1/kayitlar')->assertUnauthorized();
    }

    #[Test]
    public function suresi_dolmus_jeton_calismaz(): void
    {
        $kayit = $this->kayit();
        [$jeton, $duz] = $this->jeton($this->kullanici($kayit->group, ['view']), [
            CredentialPolicy::RESOURCE => ['view'],
        ]);

        $jeton->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->withToken($duz)->getJson('/api/v1/kayitlar')->assertUnauthorized();
    }

    /** Sahibi askıya alınmışsa jetonu da çalışmaz. */
    #[Test]
    public function pasif_sahibin_jetonu_calismaz(): void
    {
        $kayit = $this->kayit();
        $sahip = $this->kullanici($kayit->group, ['view']);

        [, $duz] = $this->jeton($sahip, [CredentialPolicy::RESOURCE => ['view']]);

        $sahip->forceFill(['status' => 'passive'])->save();

        $this->withToken($duz)->getJson('/api/v1/kayitlar')->assertUnauthorized();
    }

    /* ── Liste ── */

    #[Test]
    public function jeton_sahibinin_gorebildigi_kayitlari_listeler(): void
    {
        $gorunen = $this->kayit('Grup A');
        $gizli = $this->kayit('KASA');

        [, $duz] = $this->jeton($this->kullanici($gorunen->group, ['view']), [
            CredentialPolicy::RESOURCE => ['view'],
        ]);

        $this->withToken($duz)->getJson('/api/v1/kayitlar')
            ->assertOk()
            ->assertJsonPath('data.0.kimlik', $gorunen->uuid)
            ->assertJsonCount(1, 'data')
            ->assertJsonMissing(['kimlik' => $gizli->uuid]);
    }

    /**
     * PAROLA LİSTEDE DÖNMEZ.
     *
     * Dönseydi "hangi kayıtlar var" sorusunu soran her istemci parolayı da
     * almış olurdu.
     */
    /**
     * ALAN YETKİSİ KAYDIN DEĞİL GRUBUNUN.
     *
     * API, görünürlüğü kaydın KENDİ birincil anahtarıyla hesaplıyordu.
     * Sayılar çakıştığında — kaydın id'si, kullanıcının yetkili olduğu
     * grubun id'siyle aynı olduğunda — panelin gizlediği alan API'den
     * dönüyordu. Panelin üç çağrısı grup kimliğini geçiyor, API'ninki
     * geride kalmıştı. İki tablo da 1'den başladığı için çakışma
     * kurgusal değil, sıradan bir kurulumda kendiliğinden oluşuyor.
     */
    #[Test]
    public function alan_yetkisi_kaydin_kendi_kimligine_bakmaz(): void
    {
        /*
         * ÇAKIŞMA KURULUYOR, sayıya güvenilmiyor.
         *
         * "İkisi de 1 olur" varsayılmaz: göçlerden biri önceden bir grup
         * satırı yazar (kişisel alanın taşıyıcı grubu) ve kurgu sessizce
         * bozulurdu. Kaydın id'si, yetkili grubun id'sine EŞİT OLACAK
         * ŞEKİLDE ayarlanır.
         */
        $yetkiliGrup = CredentialGroup::factory()->create(['name' => 'YETKILI']);

        $kayit = Credential::factory()
            ->for(CredentialGroup::factory()->state(['name' => 'BASKA']), 'group')
            ->create(['host' => '10.0.0.5', 'username' => 'root', 'password' => 'gizli-parola']);

        if ($kayit->id !== $yetkiliGrup->id) {
            DB::table('credentials')
                ->where('id', $kayit->id)->update(['id' => $yetkiliGrup->id]);
            $kayit = Credential::findOrFail($yetkiliGrup->id);
        }

        $this->assertSame($yetkiliGrup->id, $kayit->id, 'Kurgu: kaydın id\'si yetkili grubun id\'siyle çakışmalı.');

        /*
         * Kullanıcı İKİ grubu da görebiliyor ama adres/kullanıcı alanlarını
         * yalnızca birinde açabiliyor. Alan izni 'view' iznine EKLENİR
         * (bkz. permissions.requires_view), o yüzden ikisi de gerekli.
         */
        $kullanici = $this->kullanici($yetkiliGrup, ['view', 'field.host', 'field.username']);

        UserPermission::create([
            'user_id' => $kullanici->id,
            'resource' => CredentialPolicy::RESOURCE,
            'resource_id' => $kayit->credential_group_id,
            'action' => 'view',
            'effect' => PermissionEffect::Allow,
        ]);

        /* Jeton alanları açabilir; kayıttaki GRUP yetkisi yine karar verir. */
        [, $duz] = $this->jeton($kullanici, [CredentialPolicy::RESOURCE => ['view', 'field.host', 'field.password']]);

        $yanit = $this->withToken($duz)
            ->getJson("/api/v1/kayitlar/{$kayit->uuid}")
            ->assertOk();

        /* Adres ve kullanıcı yetkisi BU grupta yok: alanlar gelmemeli. */
        $this->assertNull($yanit->json('data.adres'));
        $this->assertNull($yanit->json('data.kullanici'));
    }

    #[Test]
    public function listede_parola_donmez(): void
    {
        $kayit = $this->kayit();

        [, $duz] = $this->jeton($this->kullanici($kayit->group, ['view', 'field.password']), [
            CredentialPolicy::RESOURCE => ['view', 'field.password'],
        ]);

        $this->withToken($duz)->getJson('/api/v1/kayitlar')
            ->assertOk()
            ->assertDontSee('gizli-parola');
    }

    /**
     * SIRALI BİRİNCİL ANAHTAR DIŞARI ÇIKMAZ.
     *
     * Çıksaydı kaç kayıt olduğu ve bu kaydın kaçıncı sırada açıldığı
     * okunabilirdi; "1, 2, 3 diye deneyeyim" yolu da açılırdı.
     */
    #[Test]
    public function sirali_anahtar_yanitta_gecmez(): void
    {
        $kayit = $this->kayit();

        [, $duz] = $this->jeton($this->kullanici($kayit->group, ['view']), [
            CredentialPolicy::RESOURCE => ['view'],
        ]);

        $this->withToken($duz)->getJson('/api/v1/kayitlar')
            ->assertOk()
            ->assertJsonMissingPath('data.0.id')
            ->assertJsonPath('data.0.kimlik', $kayit->uuid);
    }

    /** Adres ve kullanıcı adıyla süzülür: çağıran kasadaki uuid'yi bilmiyor. */
    #[Test]
    public function adres_ve_kullanici_adiyla_suzulur(): void
    {
        $aranan = $this->kayit();
        $baska = Credential::factory()->for($aranan->group, 'group')
            ->create(['host' => '10.0.0.9', 'username' => 'root']);

        [, $duz] = $this->jeton($this->kullanici($aranan->group, ['view']), [
            CredentialPolicy::RESOURCE => ['view'],
        ]);

        $this->withToken($duz)->getJson('/api/v1/kayitlar?host=10.0.0.5&kullanici=root')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.kimlik', $aranan->uuid)
            ->assertJsonMissing(['kimlik' => $baska->uuid]);
    }

    /* ── IDOR ── */

    /**
     * GÖREMEDİĞİ KAYIT ONUN İÇİN YOKTUR.
     *
     * 403 dönseydi, o yanıt "bu uuid var" demenin başka bir yolu olurdu.
     */
    #[Test]
    public function gorulemeyen_kayit_404_doner(): void
    {
        $gorunen = $this->kayit('Grup A');
        $gizli = $this->kayit('KASA');

        [, $duz] = $this->jeton($this->kullanici($gorunen->group, ['view']), [
            CredentialPolicy::RESOURCE => ['view'],
        ]);

        $this->withToken($duz)->getJson("/api/v1/kayitlar/{$gizli->uuid}")->assertNotFound();
        $this->withToken($duz)->getJson("/api/v1/kayitlar/{$gizli->uuid}/parola")->assertNotFound();
    }

    /** Sıralı anahtarla adreslenemez: rota kalıbı uuid şart koşuyor. */
    #[Test]
    public function sirali_anahtarla_adreslenemez(): void
    {
        $kayit = $this->kayit();

        [, $duz] = $this->jeton($this->kullanici($kayit->group, ['view']), [
            CredentialPolicy::RESOURCE => ['view'],
        ]);

        $this->withToken($duz)->getJson("/api/v1/kayitlar/{$kayit->id}")->assertNotFound();
    }

    /* ── Parola ── */

    #[Test]
    public function parola_ayri_uctan_ve_ayri_yetkiyle_gelir(): void
    {
        $kayit = $this->kayit();

        [, $duz] = $this->jeton($this->kullanici($kayit->group, ['view', 'field.password']), [
            CredentialPolicy::RESOURCE => ['view', 'field.password'],
        ]);

        $this->withToken($duz)->getJson("/api/v1/kayitlar/{$kayit->uuid}/parola")
            ->assertOk()
            ->assertJsonPath('parola', 'gizli-parola')
            ->assertJsonPath('kullanici', 'root');
    }

    /**
     * "Görüntüle" yetkisi parolayı KAPSAMAZ.
     *
     * Burada 403 doğru: kaydı görebiliyor, yani varlığını zaten biliyor.
     */
    #[Test]
    public function goruntuleme_yetkisi_parolayi_acmaz(): void
    {
        $kayit = $this->kayit();

        [, $duz] = $this->jeton($this->kullanici($kayit->group, ['view']), [
            CredentialPolicy::RESOURCE => ['view', 'field.password'],
        ]);

        $this->withToken($duz)->getJson("/api/v1/kayitlar/{$kayit->uuid}/parola")->assertForbidden();
    }

    /**
     * JETON SAHİBİNİ AŞAMAZ.
     *
     * Sahibi parolayı görebiliyor ama jetonun listesinde o yetki yok:
     * jeton, sahibinin yetkisini kalıcı bir anahtara dönüştürmemeli.
     */
    #[Test]
    public function jeton_sahibinin_yetkisini_asamaz(): void
    {
        $kayit = $this->kayit();

        [, $duz] = $this->jeton($this->kullanici($kayit->group, ['view', 'field.password']), [
            CredentialPolicy::RESOURCE => ['view'],
        ]);

        $this->withToken($duz)->getJson("/api/v1/kayitlar/{$kayit->uuid}/parola")->assertForbidden();
    }

    /** Yöneticinin jetonu da listesini aşamaz; yoksa dar liste anlamsız olurdu. */
    #[Test]
    public function yoneticinin_jetonu_da_listesini_asamaz(): void
    {
        $kayit = $this->kayit();
        $yonetici = User::factory()->admin()->create();

        [, $duz] = $this->jeton($yonetici, [CredentialPolicy::RESOURCE => ['view']]);

        $this->withToken($duz)->getJson('/api/v1/kayitlar')->assertOk();
        $this->withToken($duz)->getJson("/api/v1/kayitlar/{$kayit->uuid}/parola")->assertForbidden();
    }

    /** Her parola okuması kayda geçer; kasanın en sessiz kapısı açık kalmamalı. */
    #[Test]
    public function parola_okumasi_denetime_yazilir(): void
    {
        $kayit = $this->kayit();

        [, $duz] = $this->jeton($this->kullanici($kayit->group, ['view', 'field.password']), [
            CredentialPolicy::RESOURCE => ['view', 'field.password'],
        ]);

        $this->withToken($duz)->getJson("/api/v1/kayitlar/{$kayit->uuid}/parola")->assertOk();

        $this->assertDatabaseHas('activity_logs', ['action' => 'revealed']);
    }

    /* ── Adres kısıtı ── */

    #[Test]
    public function izinli_adres_disindan_gelen_jeton_calismaz(): void
    {
        $kayit = $this->kayit();
        [$jeton, $duz] = $this->jeton($this->kullanici($kayit->group, ['view']), [
            CredentialPolicy::RESOURCE => ['view'],
        ]);

        $jeton->forceFill(['allowed_ips' => ['10.9.9.9']])->save();

        $this->withToken($duz)->getJson('/api/v1/kayitlar')->assertUnauthorized();
    }
}
