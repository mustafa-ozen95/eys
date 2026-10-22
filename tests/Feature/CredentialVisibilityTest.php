<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OperatingSystem;
use App\Enums\PermissionEffect;
use App\Models\Credential;
use App\Models\CredentialGroup;
use App\Models\User;
use App\Services\DeviceInterface\InterfaceType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Liste daraltması ve nesne erişimi (IDOR).
 *
 * "Hepsini getir sonra ekranda gizle" güvenlik değildir: yetkisi olmayan
 * kaydın adı da, adresi de, uuid'si de sayfaya HİÇ girmemeli.
 */
class CredentialVisibilityTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function yetkisiz_grubun_kaydi_listede_gorunmez(): void
    {
        $user = User::factory()->create();

        $verilen = CredentialGroup::factory()->create(['name' => 'Verilen grup']);
        $verilmeyen = CredentialGroup::factory()->create(['name' => 'Verilmeyen grup']);

        $this->yetkilendir($user, $verilen, ['view', 'field.host', 'field.username']);

        $gorunur = Credential::factory()->for($verilen, 'group')->create(['name' => 'Gorunur kayit']);
        $gizli = Credential::factory()->for($verilmeyen, 'group')->create(['name' => 'Gizli kayit']);

        $response = $this->actingAs($user)->get(route('credentials.index'));

        $response->assertOk()
            ->assertSee('Gorunur kayit')
            ->assertDontSee('Gizli kayit')
            ->assertDontSee('Verilmeyen grup')
            ->assertDontSee($gizli->uuid);

        $this->assertStringContainsString($gorunur->uuid, $response->getContent());
    }

    #[Test]
    public function yetkisiz_kaydin_duzenleme_ekrani_403_verir(): void
    {
        $user = User::factory()->create();

        /* Kullanıcının kendi grubu var: liste açılır, açılmayan tek şey pencere olsun. */
        $kendi = CredentialGroup::factory()->create();
        $this->yetkilendir($user, $kendi, ['view', 'update']);

        $gizli = Credential::factory()->create();

        /*
         * Düzenleme penceresi listenin üstünde açılır: yetkisi olmayan kaydın
         * penceresi HİÇ basılmaz, uuid'si de sayfaya girmez.
         */
        $this->actingAs($user)
            ->get(route('credentials.index', ['kayit' => $gizli->uuid, 'pencere' => 'duzenle']))
            ->assertOk()
            ->assertDontSee('id="record-edit"', false)
            ->assertDontSee($gizli->uuid);

        $this->actingAs($user)->put(route('credentials.update', $gizli), [])->assertForbidden();
        $this->actingAs($user)
            ->delete(route('credentials.destroy', $gizli), ['reason' => 'deneme'])
            ->assertForbidden();
    }

    #[Test]
    public function parola_hicbir_listede_ve_hicbir_formda_basilmaz(): void
    {
        $user = User::factory()->admin()->create();
        $credential = Credential::factory()->create(['password' => 'PAROLA-SIZDI-42']);

        $this->actingAs($user)
            ->get(route('credentials.index', ['kayit' => $credential->uuid]))
            ->assertOk()
            ->assertDontSee('PAROLA-SIZDI-42');

        $this->actingAs($user)
            ->get(route('credentials.index', ['kayit' => $credential->uuid, 'pencere' => 'duzenle']))
            ->assertOk()
            ->assertDontSee('PAROLA-SIZDI-42');
    }

    #[Test]
    public function yonetici_izin_verilmemis_gruplari_da_gorur(): void
    {
        $admin = User::factory()->admin()->create();
        $credential = Credential::factory()->create(['name' => 'Herhangi kayit']);

        $this->actingAs($admin)
            ->get(route('credentials.index'))
            ->assertOk()
            ->assertSee('Herhangi kayit');
    }

    #[Test]
    public function pasif_kullanici_yetkili_oldugu_grubu_da_goremez(): void
    {
        $user = User::factory()->passive()->create();
        $group = CredentialGroup::factory()->create();
        $this->yetkilendir($user, $group, ['view', 'field.password']);

        $credential = Credential::factory()->for($group, 'group')->create();

        /*
         * PASİF HESAP 403 DEĞİL, OTURUMDAN ATILMA görür.
         *
         * Yalnızca 403 dönseydi oturum açık kalır, erişimi kaldırılan kişi
         * panelde gezinmeye devam edip her sayfada "yasak" görürdü.
         * EnsureUserIsActive oturumu kapatıp girişe yollar — istenen şey
         * "göremesin" değil, "içeride olmasın".
         */
        foreach ([
            fn () => $this->get(route('credentials.index')),
            fn () => $this->put(route('credentials.update', $credential), []),
            fn () => $this->post(route('ajax.credentials.password', $credential)),
        ] as $istek) {
            $this->actingAs($user);
            $istek()->assertRedirect(route('login'));
            $this->assertGuest();
        }
    }

    #[Test]
    public function platform_suzgeci_butun_gruplara_yayilir_ama_yetkiyi_asmaz(): void
    {
        $user = User::factory()->create();

        $verilen = CredentialGroup::factory()->create(['name' => 'Verilen', 'position' => 1]);
        $ikinci = CredentialGroup::factory()->create(['name' => 'Ikinci', 'position' => 2]);
        $verilmeyen = CredentialGroup::factory()->create(['name' => 'Verilmeyen', 'position' => 3]);

        $this->yetkilendir($user, $verilen, ['view']);
        $this->yetkilendir($user, $ikinci, ['view']);

        /*
         * Aranan kayıt İKİNCİ gruptadır. Süzgeç yalnızca seçili grubun içinde
         * arasaydı bu kayıt hiç görünmezdi — düzeltmenin kilidi budur.
         */
        Credential::factory()->for($ikinci, 'group')->create([
            'name' => 'Ikinci gruptaki windows',
            'operating_system' => OperatingSystem::Windows,
        ]);

        Credential::factory()->for($verilen, 'group')->create([
            'name' => 'Verilen gruptaki linux',
            'operating_system' => OperatingSystem::Linux,
        ]);

        Credential::factory()->for($verilmeyen, 'group')->create([
            'name' => 'Yetkisiz windows',
            'operating_system' => OperatingSystem::Windows,
        ]);

        $this->actingAs($user)
            ->get(route('credentials.index', ['platform' => 'windows']))
            ->assertOk()
            ->assertSee('Ikinci gruptaki windows')
            ->assertDontSee('Verilen gruptaki linux')
            ->assertDontSee('Yetkisiz windows')
            // Liste tek gruba daralmadığında satır hangi gruptan geldiğini söylemeli.
            ->assertSee('Ikinci');
    }

    /**
     * Kullanıcıya bir grupta yetki verir.
     *
     * Testler bunu tek yerden çağırır: izin satırının nasıl yazıldığı
     * değişirse tek dosyada değişsin.
     *
     * @param  list<string>  $actions
     */
    private function yetkilendir(User $user, CredentialGroup $group, array $actions): void
    {
        foreach ($actions as $action) {
            $user->permissions()->create([
                'resource' => 'credential_group',
                'resource_id' => $group->getKey(),
                'action' => $action,
                'effect' => PermissionEffect::Allow,
            ]);
        }

        $user->unsetRelation('permissions');
    }

    /**
     * Listenin başındaki HEPSİ satırı.
     *
     * Varsayılan görünüm ilk grubu açar; kullanıcı "ne var ne yok" demek
     * istediğinde tek tek gruplara girmek zorunda kalmasın.
     */
    #[Test]
    public function hepsi_satiri_butun_gruplarin_kayitlarini_listeler(): void
    {
        $user = User::factory()->create();

        $ilk = CredentialGroup::factory()->create(['name' => 'Ilk grup', 'position' => 1]);
        $ikinci = CredentialGroup::factory()->create(['name' => 'Ikinci grup', 'position' => 2]);

        $this->yetkilendir($user, $ilk, ['view']);
        $this->yetkilendir($user, $ikinci, ['view']);

        Credential::factory()->for($ilk, 'group')->create(['name' => 'Ilk kayit']);
        Credential::factory()->for($ikinci, 'group')->create(['name' => 'Ikinci kayit']);

        /* Varsayılan: yalnızca ilk grup. */
        $this->actingAs($user)->get(route('credentials.index'))
            ->assertOk()
            ->assertSee('Ilk kayit')
            ->assertDontSee('Ikinci kayit');

        /* HEPSİ: ikisi de. */
        $this->actingAs($user)->get(route('credentials.index', ['grup' => 'hepsi']))
            ->assertOk()
            ->assertSee('Ilk kayit')
            ->assertSee('Ikinci kayit');
    }

    /**
     * Güncellemeden sonra KAYDIN KENDİ GRUBU açılır.
     *
     * Güncelleme ?kayit= ile döner, grup adreste yoktur. İlk grup açılsaydı
     * kayıt orada olmadığı için detay boş kalırdı.
     */
    #[Test]
    public function guncellemeden_sonra_kaydin_kendi_grubu_acilir(): void
    {
        $user = User::factory()->create();

        $ilk = CredentialGroup::factory()->create(['name' => 'Ilk grup', 'position' => 1]);
        $ikinci = CredentialGroup::factory()->create(['name' => 'Ikinci grup', 'position' => 2]);

        $this->yetkilendir($user, $ilk, ['view']);
        $this->yetkilendir($user, $ikinci, ['view', 'update', 'field.host']);

        $kayit = Credential::factory()->for($ikinci, 'group')->create([
            'name' => 'Ikinci kayit',
            'access_type' => 'rdp',
            'operating_system' => 'windows',
        ]);

        $adres = $this->actingAs($user)->put(route('credentials.update', $kayit), [
            'group' => $ikinci->uuid,
            'name' => 'Ikinci kayit yeni',
            'access_type' => 'rdp',
            'operating_system' => 'windows',
            'host' => '192.0.2.134',
            'is_active' => '1',
            'position' => 0,
        ])->assertSessionHasNoErrors()->assertRedirect()->headers->get('Location');

        $this->actingAs($user)->get($adres)
            ->assertOk()
            ->assertViewHas('selectedGroup', fn ($g) => $g?->is($ikinci))
            ->assertViewHas('selected', fn ($s) => $s !== null && $s['uuid'] === $kayit->uuid);
    }

    /**
     * Göremediği kaydın adresi grubunu SIZDIRMAZ.
     *
     * ?kayit= ile başkasının kaydı verilirse sayfa o kaydın grubuna geçmez;
     * geçseydi kaydın hangi grupta durduğu adres çubuğundan okunurdu.
     */
    #[Test]
    public function gorulmeyen_kaydin_grubu_acilmaz(): void
    {
        $user = User::factory()->create();

        $ilk = CredentialGroup::factory()->create(['name' => 'Ilk grup', 'position' => 1]);
        $gizliGrup = CredentialGroup::factory()->create(['name' => 'Gizli grup', 'position' => 2]);

        $this->yetkilendir($user, $ilk, ['view']);

        $gizli = Credential::factory()->for($gizliGrup, 'group')->create(['name' => 'Gizli kayit']);

        $this->actingAs($user)->get(route('credentials.index', ['kayit' => $gizli->uuid]))
            ->assertOk()
            ->assertViewHas('selectedGroup', fn ($g) => $g?->is($ilk))
            ->assertDontSee('Gizli kayit');
    }

    /**
     * Arama silinince başlatıldığı görünüme dönülür.
     *
     * "Hepsi"nde arama yapıp kutuyu boşaltan kullanıcı ilk gruba düşüyordu:
     * form grubu yalnızca bir grup seçiliyken taşıyordu.
     */
    #[Test]
    public function arama_formu_baslatildigi_gorunumu_tasir(): void
    {
        $user = User::factory()->create();
        $ilk = CredentialGroup::factory()->create(['name' => 'Ilk grup', 'position' => 1]);
        $ikinci = CredentialGroup::factory()->create(['name' => 'Ikinci grup', 'position' => 2]);
        $gizli = CredentialGroup::factory()->create(['name' => 'Gizli grup', 'position' => 3]);
        $this->yetkilendir($user, $ilk, ['view']);
        $this->yetkilendir($user, $ikinci, ['view']);

        $alan = fn (string $deger) => '<input type="hidden" name="grup" value="'.$deger.'">';

        /* Hepsi görünümünde ve Hepsi'nden başlayan aramada "hepsi" taşınır. */
        $this->actingAs($user)->get(route('credentials.index', ['grup' => 'hepsi']))->assertSee($alan('hepsi'), false);
        $this->actingAs($user)->get(route('credentials.index', ['grup' => 'hepsi', 'q' => '192.0']))->assertSee($alan('hepsi'), false);

        /* Gruptan başlayan arama o grubu taşır. */
        $this->actingAs($user)->get(route('credentials.index', ['grup' => $ikinci->uuid, 'q' => '192.0']))->assertSee($alan($ikinci->uuid), false);

        /* Göremediği grubun kimliği adresten geri basılmaz. */
        $this->actingAs($user)->get(route('credentials.index', ['grup' => $gizli->uuid, 'q' => 'x']))->assertDontSee($gizli->uuid, false);

        /* Boş arama "hepsi" ile gelirse Hepsi açılır, ilk grup değil. */
        $this->actingAs($user)->get(route('credentials.index', ['grup' => 'hepsi', 'q' => '']))
            ->assertViewHas('selectedGroup', null);
    }

    /**
     * Silindikten sonra bakılan yere dönülür.
     *
     * Yönlendirme grupsuz olursa ilk grup açılır.
     */
    #[Test]
    public function silindikten_sonra_bakilan_gorunume_donulur(): void
    {
        $admin = User::factory()->admin()->create();
        $ilk = CredentialGroup::factory()->create(['name' => 'Ilk grup', 'position' => 1]);
        $ikinci = CredentialGroup::factory()->create(['name' => 'Ikinci grup', 'position' => 2]);
        $sil = fn (array $nereden) => $this->actingAs($admin)
            ->from(route('credentials.index', $nereden))
            ->delete(route('credentials.destroy', $k = Credential::factory()->for($ikinci, 'group')->create()), ['reason' => 'sinama'])
            ->assertRedirect()
            ->headers->get('Location');

        /* Kayıttan silinince kaydın grubu açılır, ilk grup değil. */
        $this->assertStringContainsString('grup='.$ikinci->uuid, $sil(['kayit' => 'x']));

        /* "Hepsi"nden ve aramadan silinince görünüm korunur. */
        $this->assertStringContainsString('grup=hepsi', $sil(['grup' => 'hepsi', 'kayit' => 'x']));
        $adres = $sil(['grup' => 'hepsi', 'q' => '192.0', 'platform' => 'windows']);
        $this->assertStringContainsString('q=192.0', $adres);
        $this->assertStringContainsString('platform=windows', $adres);
        $this->assertStringNotContainsString('kayit=', $adres, 'Silinen kaydın kimliği adreste kalmaz.');
    }

    /**
     * HEPSİ yetkiyi DELMEZ.
     *
     * "Hepsi" kullanıcının hepsi demektir; göremediği bir grubun kaydı
     * burada da görünmez. Aksi hâlde tek bir bağlantı bütün daraltmayı
     * atlatan bir kapı olurdu.
     */
    #[Test]
    public function hepsi_satiri_yetkisiz_kaydi_getirmez(): void
    {
        $user = User::factory()->create();

        $verilen = CredentialGroup::factory()->create(['name' => 'Verilen grup']);
        $verilmeyen = CredentialGroup::factory()->create(['name' => 'Verilmeyen grup']);

        $this->yetkilendir($user, $verilen, ['view']);

        Credential::factory()->for($verilen, 'group')->create(['name' => 'Gorunur kayit']);
        Credential::factory()->for($verilmeyen, 'group')->create(['name' => 'Gizli kayit']);

        $this->actingAs($user)->get(route('credentials.index', ['grup' => 'hepsi']))
            ->assertOk()
            ->assertSee('Gorunur kayit')
            ->assertDontSee('Gizli kayit');
    }

    /**
     * vCenter appliance bağlantısı /ui/ ALTINDA DEĞİL.
     *
     * vSphere Client 443'te /ui/ altındadır ama appliance yönetim arayüzü
     * (5480) ayrı bir uygulamadır ve kökten açılır: 5480'deki /ui/ 404 verir
     * ve panel kullanıcıyı tam oraya gönderiyordu.
     */
    #[Test]
    public function appliance_yonetim_baglantisi_koke_gider(): void
    {
        $user = User::factory()->admin()->create();

        $grup = CredentialGroup::factory()->create();

        /*
         * KİMLİKSİZ kayıt: bağlantı gösterilir. Kimliği olan bir appliance
         * kaydında ekran artık bağlantı değil OTURUM AÇ düğmesi gösteriyor
         * (arayüz vekilleniyor, bkz. InterfaceSessionTest); buradaki soru ise
         * bağlantının hangi adrese gittiği.
         */
        $appliance = Credential::factory()->for($grup, 'group')->create([
            'name' => 'Appliance yonetimi',
            'operating_system' => OperatingSystem::Vcenter,
            'host' => '198.51.100.13',
            'port' => 5480,
            'url' => null,
            'password' => null,
        ]);

        $istemci = Credential::factory()->for($grup, 'group')->create([
            'name' => 'vSphere istemcisi',
            'operating_system' => OperatingSystem::Vcenter,
            'host' => '198.51.100.13',
            'port' => null,
            'url' => null,
        ]);

        $govde = $this->actingAs($user)
            ->get(route('credentials.index', ['kayit' => $appliance->uuid]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('https://198.51.100.13:5480/', $govde);
        $this->assertStringNotContainsString('https://198.51.100.13:5480/ui/', $govde);

        $this->actingAs($user)
            ->get(route('credentials.index', ['kayit' => $istemci->uuid]))
            ->assertOk()
            ->assertSee('https://198.51.100.13/ui/', escape: false);

        /*
         * Kimliği olan appliance kaydı 5480'den tanınır ve oturumu vekille
         * açılır. Port sabittir: vCenter appliance yönetimi her kurulumda
         * orada durur ve ayrımı yapan tek şey odur (erişim türü "panel"
         * olabiliyor).
         */
        $kimlikli = Credential::factory()->for($grup, 'group')->create([
            'name' => 'Appliance yonetimi (kimlikli)',
            'operating_system' => OperatingSystem::Vcenter,
            'host' => '198.51.100.13',
            'port' => 5480,
            'url' => null,
            'username' => 'root',
            'password' => 'VAMI-PAROLASI',
        ]);

        $this->assertSame(InterfaceType::Vami, $kimlikli->interface_type);
    }

    /**
     * Yazılı onayda ADRES istenir, ad değil.
     *
     * Kayıt adları uzun olabiliyor; tehlikeli işlemi onaylamak için onu
     * harfi harfine yazmak kullanıcıyı yıldırıyordu.
     */
    #[Test]
    public function yazili_onay_adresi_ister(): void
    {
        $user = User::factory()->admin()->create();

        $kayit = Credential::factory()->for(CredentialGroup::factory(), 'group')->create([
            'name' => 'vCenter Appliance Yonetimi 198.51.100.13:5480',
            'host' => '198.51.100.13',
        ]);

        $this->actingAs($user)
            ->get(route('credentials.index', ['kayit' => $kayit->uuid]))
            ->assertOk()
            ->assertSee('data-confirm-verify="198.51.100.13"', escape: false)
            ->assertDontSee('data-confirm-verify="vCenter Appliance Yonetimi 198.51.100.13:5480"', escape: false);
    }

    /**
     * Adresi göremeyen ondan yazması istenemez.
     *
     * Maskelenmiş alan onay kutusuna konsaydı kullanıcı "•••" yazmak
     * zorunda kalır, yani işlemi hiç yapamazdı.
     */
    #[Test]
    public function adres_yetkisi_yoksa_onay_ada_duser(): void
    {
        $user = User::factory()->create();
        $grup = CredentialGroup::factory()->create();

        $this->yetkilendir($user, $grup, ['view', 'delete']);

        $kayit = Credential::factory()->for($grup, 'group')->create([
            'name' => 'Adressiz kayit',
            'host' => '198.51.100.13',
        ]);

        $govde = $this->actingAs($user)
            ->get(route('credentials.index', ['kayit' => $kayit->uuid]))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('data-confirm-verify="198.51.100.13"', $govde);
        $this->assertStringContainsString('data-confirm-verify="Adressiz kayit"', $govde);
    }

    /**
     * HEPSİ'den bir kayda tıklamak KENDİ grubuna götürür.
     *
     * Satır bağlantısı grupsuz üretiliyordu; grupsuz istek varsayılan olarak
     * ilk gruba düşüyor ve hangi kayda basılırsa basılsın aynı gruba
     * gidiliyordu — üstelik kayıt orada olmadığı için seçili de gelmiyordu.
     */
    #[Test]
    public function hepsi_gorunumunde_satir_kendi_grubuna_baglanir(): void
    {
        $user = User::factory()->admin()->create();

        $ilk = CredentialGroup::factory()->create(['name' => 'Ilk grup', 'position' => 1]);
        $ikinci = CredentialGroup::factory()->create(['name' => 'Ikinci grup', 'position' => 2]);

        Credential::factory()->for($ilk, 'group')->create(['name' => 'Ilk kayit']);
        $uzak = Credential::factory()->for($ikinci, 'group')->create(['name' => 'Uzak kayit']);

        $govde = $this->actingAs($user)
            ->get(route('credentials.index', ['grup' => 'hepsi']))
            ->assertOk()
            ->getContent();

        /* İkinci gruptaki kaydın bağlantısı KENDİ grubunu taşımalı. */
        $this->assertStringContainsString(
            'grup='.$ikinci->uuid.'&amp;kayit='.$uzak->uuid,
            $govde,
        );

        /* Ve o bağlantı gerçekten o kaydı seçili getirmeli. */
        $this->actingAs($user)
            ->get(route('credentials.index', ['grup' => $ikinci->uuid, 'kayit' => $uzak->uuid]))
            ->assertOk()
            ->assertSee('Uzak kayit');
    }
}
