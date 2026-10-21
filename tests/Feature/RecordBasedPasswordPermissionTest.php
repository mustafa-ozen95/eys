<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AccessType;
use App\Models\Credential;
use App\Models\CredentialGroup;
use App\Models\Role;
use App\Models\User;
use App\Policies\CredentialPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * KAYIT BAZINDA EK PAROLA İZNİ — kısıtın aynası.
 *
 * Yetki grup düzeyinde. Kısıt, grubun verdiğini tek bir kayıtta geri alıyor;
 * bu ise grubun VERMEDİĞİNİ tek bir kayıtta veriyor.
 *
 * Örnek durum: bir rol bir grupta parola göremiyor ve görmemeli — grupta
 * yönetim kartı (iLO) kayıtları var. Ama aynı gruptaki izleme hesabını
 * kullanması gerekiyor.
 *
 * SINANAN ASIL ŞEY, İZNİN DAR KALMASI: izin verilen kayıtta parola açılıyor,
 * AYNI GRUPTAKİ öteki kayıtlarda açılmıyor.
 */
class RecordBasedPasswordPermissionTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Credential, 2: Credential, 3: Role} */
    private function sahne(): array
    {
        $grup = CredentialGroup::factory()->create(['name' => 'Grup A']);

        $rol = Role::create([
            'slug' => 'sinirli-rol',
            'label' => 'Sınırlı rol',
            'badge' => 'gray',
            'is_admin' => false,
            /* Parola yetkisi YOK: grup yalnızca görmeyi ve oturum açmayı veriyor. */
            'template' => [CredentialPolicy::RESOURCE => [
                'all' => [],
                'groups' => ['Grup A' => ['view', 'field.host', 'field.url', 'session']],
            ]],
        ]);

        $kullanici = User::factory()->create(['role_id' => $rol->getKey()]);

        /*
         * SINAMA VERİSİ UYDURMADIR ve öyle kalmalı.
         *
         * Sınamanın gerçek bir sırra ihtiyacı yok: sorduğu şey "izin verilen
         * kayıtta parola açılıyor mu", parolanın ne olduğu değil. Test
         * verisine gerçek kullanıcı adı ya da parola yazılmaz; değerler
         * açıkça sahtedir.
         */
        $izinli = Credential::factory()->create([
            'credential_group_id' => $grup->getKey(), 'name' => 'İzleme',
            'username' => 'sinama-kullanicisi', 'password' => 'SINAMA-PAROLASI',
        ]);

        $oteki = Credential::factory()->create([
            'credential_group_id' => $grup->getKey(), 'name' => 'Sunucu iLO',
            'username' => 'Administrator', 'password' => 'ILO-PAROLASI',
        ]);

        $izinli->grantedRoles()->attach($rol, ['action' => 'field.password']);

        return [$kullanici, $izinli, $oteki, $rol];
    }

    #[Test]
    public function izin_verilen_kayitta_parola_acilir_otekinde_acilmaz(): void
    {
        [$kullanici, $izinli, $oteki] = $this->sahne();

        $this->assertTrue($kullanici->can('reveal', $izinli), 'İzin verilen kayıtta parola açılmalı.');
        $this->assertFalse($kullanici->can('reveal', $oteki), 'Aynı gruptaki öteki kayıtta AÇILMAMALI.');
    }

    /** Ekran maskesi de aynı yanıtı vermeli; yoksa uç açar, liste "•••" gösterir. */
    #[Test]
    public function alan_maskesi_ayni_karari_verir(): void
    {
        [$kullanici, $izinli, $oteki] = $this->sahne();

        $this->assertTrue($izinli->visibleFieldsFor($kullanici)['password']);
        $this->assertTrue($izinli->visibleFieldsFor($kullanici)['username'], 'Kullanıcı adı da aynı yetkiye bağlı.');

        $this->assertFalse($oteki->visibleFieldsFor($kullanici)['password']);
        $this->assertFalse($oteki->visibleFieldsFor($kullanici)['username']);
    }

    /** Parolayı açan uç da izni tanımalı: politika ile uç ayrışmasın. */
    #[Test]
    public function parola_ucu_izinli_kayitta_calisir(): void
    {
        [$kullanici, $izinli, $oteki] = $this->sahne();

        $this->actingAs($kullanici)
            ->post(route('ajax.credentials.password', $izinli))
            ->assertOk()
            ->assertJson(['username' => 'sinama-kullanicisi', 'password' => 'SINAMA-PAROLASI']);

        $this->actingAs($kullanici)
            ->post(route('ajax.credentials.password', $oteki))
            ->assertForbidden();
    }

    /**
     * KISIT İZNİN ÖNÜNDE GELİR.
     *
     * Aynı kayıtta ikisi de varsa kayıt kapalıdır. Yasak, izinden güçlüdür;
     * tersi olsaydı bir kısıt sessizce delinebilirdi.
     */
    #[Test]
    public function kisit_izni_yener(): void
    {
        [$kullanici, $izinli, , $rol] = $this->sahne();

        $izinli->restrictedRoles()->attach($rol);

        $this->assertFalse($kullanici->can('reveal', $izinli->fresh()));
        $this->assertFalse($kullanici->can('view', $izinli->fresh()));
    }

    /** İzin kaldırılınca parola yeniden kapanır. */
    #[Test]
    public function izin_kaldirilinca_kapanir(): void
    {
        [$kullanici, $izinli, , $rol] = $this->sahne();

        $izinli->grantedRoles()->detach($rol);

        $this->assertFalse($kullanici->can('reveal', $izinli->fresh()));
    }

    /**
     * OTURUM YETKİSİ OLMAYAN DA ARAYÜZÜ AÇABİLİR.
     *
     * Bir adresi yeni sekmede açmak parola kullanmaz; düğme oturum
     * bölümünün İÇİNDE durduğu sürece sürücüsü olmayan sıradan bir web
     * arayüzünde (ör. bir izleme paneli) hiç görünmüyordu.
     */
    #[Test]
    public function oturum_yetkisi_olmayan_da_arayuzu_acabilir(): void
    {
        [$kullanici, , $oteki] = $this->sahne();

        /* Ek izni OLMAYAN kayıt: parola kapalı, dolayısıyla oturum da kapalı. */
        $oteki->update(['url' => 'http://198.51.100.62', 'login_url' => null]);

        $this->assertFalse($kullanici->can('session', $oteki->fresh()), 'Parola kapalıyken oturum da kapalı olmalı.');

        $this->actingAs($kullanici)
            ->get(route('credentials.index', ['grup' => $oteki->group->uuid, 'kayit' => $oteki->uuid]))
            ->assertOk()
            ->assertSee('Arayüzü aç')
            ->assertSee('http://198.51.100.62');
    }

    /**
     * KONSOL İZNİ PAROLAYI AÇMAZ.
     *
     * "Bağlansın ama parolayı görmesin": konsol zaten parolayı göstermeden
     * bağlıyor. İki iznin birbirine sızmadığı sınanıyor — sızsaydı,
     * bağlanabilsin diye verilen bir izin sessizce parolayı da açardı.
     */
    #[Test]
    public function konsol_izni_parolayi_acmaz(): void
    {
        [$kullanici, , $oteki, $rol] = $this->sahne();

        $oteki->update(['access_type' => AccessType::Rdp, 'host' => '192.0.2.15']);
        $oteki->grantedRoles()->attach($rol, ['action' => 'connect']);

        $taze = $oteki->fresh();

        $this->assertTrue($kullanici->can('connect', $taze), 'Konsol açılabilmeli.');
        $this->assertFalse($kullanici->can('reveal', $taze), 'Parola AÇILMAMALI.');
        $this->assertFalse($taze->visibleFieldsFor($kullanici)['password']);
    }

    /**
     * FORMDAN YAZILABİLİR — ama yalnızca yönetici yazabilir.
     *
     * Grubun vermediği bir parolayı tek bir kayıtta açmak, yetki dağıtmakla
     * aynı ağırlıkta bir iştir.
     */
    #[Test]
    public function izni_yalnizca_yonetici_yazar(): void
    {
        [$kullanici, $izinli, $oteki, $rol] = $this->sahne();

        $alanlar = [
            'group' => $oteki->group->uuid,
            'name' => $oteki->name,
            'access_type' => $oteki->access_type->value,
            'operating_system' => $oteki->operating_system->value,
            'granted_roles' => [$rol->uuid],
        ];

        /* Yönetici olmayan gönderirse alan hiç okunmaz. */
        $this->actingAs(User::factory()->admin()->create())
            ->put(route('credentials.update', $oteki), $alanlar)
            ->assertSessionHasNoErrors();

        $this->assertTrue($kullanici->can('reveal', $oteki->fresh()), 'Yönetici izni yazabilmeli.');

        /* Boş gönderim izni kaldırır. */
        $this->actingAs(User::factory()->admin()->create())
            ->put(route('credentials.update', $oteki), [...$alanlar, 'granted_roles' => []])
            ->assertSessionHasNoErrors();

        $this->assertFalse($kullanici->can('reveal', $oteki->fresh()));
    }

    /**
     * Tarayıcı işaretsiz kutuyu HİÇ göndermez: tiklerin hepsi kaldırılınca
     * alan istekte yok, yalnızca formun işareti var. İzin kalkmalı
     * (yoksa "güncellendi" denip tik kalır).
     */
    #[Test]
    public function tikler_kaldirilinca_alan_gelmese_de_izin_kalkar(): void
    {
        [$kullanici, $izinli, $oteki, $rol] = $this->sahne();
        $alanlar = [
            'group' => $oteki->group->uuid,
            'name' => $oteki->name,
            'access_type' => $oteki->access_type->value,
            'operating_system' => $oteki->operating_system->value,
        ];
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->put(route('credentials.update', $oteki), [...$alanlar, 'rol_kutulari' => '1', 'granted_roles' => [$rol->uuid], 'restricted_roles' => [$rol->uuid]])
            ->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('credential_role_restrictions')->where('credential_id', $oteki->id)->count());

        $this->actingAs($admin)->put(route('credentials.update', $oteki), [...$alanlar, 'rol_kutulari' => '1'])
            ->assertSessionHasNoErrors();

        $this->assertFalse($kullanici->can('reveal', $oteki->fresh()), 'ek parola izni kalkmalı');
        $this->assertSame(0, DB::table('credential_role_restrictions')->where('credential_id', $oteki->id)->count(), 'kısıt da kalkmalı');

        /* İşaret yoksa (başka ekrandan kısmi güncelleme) dokunulmaz. */
        $this->actingAs($admin)->put(route('credentials.update', $oteki), [...$alanlar, 'rol_kutulari' => '1', 'granted_roles' => [$rol->uuid]]);
        $this->actingAs($admin)->put(route('credentials.update', $oteki), $alanlar)->assertSessionHasNoErrors();
        $this->assertTrue($kullanici->can('reveal', $oteki->fresh()));
    }
}
