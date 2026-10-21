<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\ArayuzHavuzlariCommand;
use App\Enums\AccessType;
use App\Enums\PermissionEffect;
use App\Models\Credential;
use App\Models\CredentialGroup;
use App\Models\User;
use App\Services\Rotation\MakineHesabi;
use App\Support\Konak;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Sır, GİDECEĞİ YERE bağlıdır: adresi değiştiren sırrı da götürür.
 *
 * Parola yetkisi olmayan biri adres/bağlantı/portal giriş adresini değiştirip
 * saklı parolayı kendi dinleyicisine yönlendiremez; döndürme kimliği
 * (yazma-yalnız) ve kasadan seçilen kimlik, kaydı düzenleyen kişinin yazdığı
 * adrese gitmez; "güncelleme hesabı" olarak göremediği kayıt seçilemez; adres
 * alanı nginx yapılandırmasına doğrulanmadan girmez.
 */
class HedefBaglamaTest extends TestCase
{
    use RefreshDatabase;

    private function ver(User $user, CredentialGroup $group, array $actions): void
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

    /** @return array<string, mixed> */
    private function veri(Credential $k, array $ustune = []): array
    {
        return [
            'group' => $k->group->uuid,
            'name' => $k->name,
            'access_type' => $k->access_type->value,
            'operating_system' => $k->operating_system->value,
            'host' => $k->host,
            'port' => $k->port,
            'url' => $k->url,
            'login_url' => $k->login_url,
            'login_username_field' => $k->login_username_field,
            'login_password_field' => $k->login_password_field,
            'username' => $k->username,
            'is_active' => '1',
            'position' => 0,
            ...$ustune,
        ];
    }

    #[Test]
    public function parolayi_goremeyen_adresi_ve_portal_hedefini_degistiremez(): void
    {
        $user = User::factory()->create();
        $grup = CredentialGroup::factory()->create();
        $this->ver($user, $grup, ['view', 'update', 'field.host', 'field.port', 'field.url']);

        $k = Credential::factory()->for($grup, 'group')->create([
            'host' => '10.0.0.5', 'port' => 22, 'url' => 'https://10.0.0.5',
            'login_url' => 'https://portal.lab.test/giris',
            'login_username_field' => 'user', 'login_password_field' => 'pass',
            'password' => 'GIZLI',
        ]);

        $this->actingAs($user)->put(route('credentials.update', $k), $this->veri($k, [
            'name' => 'Yeni ad',
            'host' => '203.0.113.99', 'port' => 2222, 'url' => 'https://203.0.113.99',
            'login_url' => 'https://attacker.example/yakala',
            'login_username_field' => 'u', 'login_password_field' => 'p',
        ]))->assertSessionHasNoErrors();

        $k->refresh();
        $this->assertSame('Yeni ad', $k->name, 'Başka alanlar normal düzenlenir.');
        $this->assertSame('10.0.0.5', $k->host);
        $this->assertSame(22, $k->port);
        $this->assertSame('https://10.0.0.5', $k->url);
        $this->assertSame('https://portal.lab.test/giris', $k->login_url);
        $this->assertSame('user', $k->login_username_field);
        $this->assertSame('pass', $k->login_password_field);
    }

    #[Test]
    public function parola_yetkisi_olan_hedefi_degistirebilir(): void
    {
        $user = User::factory()->create();
        $grup = CredentialGroup::factory()->create();
        $this->ver($user, $grup, ['view', 'update', 'field.host', 'field.port', 'field.url', 'field.password']);
        $k = Credential::factory()->for($grup, 'group')->create(['host' => '10.0.0.5', 'login_url' => 'https://a.lab.test/x']);

        $this->actingAs($user)->put(route('credentials.update', $k), $this->veri($k, [
            'host' => '10.0.0.6', 'login_url' => 'https://b.lab.test/x',
        ]))->assertSessionHasNoErrors();

        $this->assertSame('10.0.0.6', $k->fresh()->host);
        $this->assertSame('https://b.lab.test/x', $k->fresh()->login_url);
    }

    #[Test]
    public function sirsiz_kayitta_hedef_serbesttir(): void
    {
        $user = User::factory()->create();
        $grup = CredentialGroup::factory()->create();
        $this->ver($user, $grup, ['view', 'update', 'field.host']);
        $k = Credential::factory()->for($grup, 'group')->withoutPassword()->create(['host' => '10.0.0.5']);

        $this->actingAs($user)->put(route('credentials.update', $k), $this->veri($k, ['host' => '10.0.0.9']))
            ->assertSessionHasNoErrors();

        $this->assertSame('10.0.0.9', $k->fresh()->host);
    }

    #[Test]
    public function form_kilitli_hedefi_salt_okunur_gosterir(): void
    {
        $user = User::factory()->create();
        $grup = CredentialGroup::factory()->create();
        $this->ver($user, $grup, ['view', 'update', 'field.host', 'field.port', 'field.url']);
        $k = Credential::factory()->for($grup, 'group')->create(['host' => '10.0.0.5']);

        $html = $this->actingAs($user)
            ->get(route('credentials.index', ['kayit' => $k->uuid, 'pencere' => 'duzenle']))
            ->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<input(?=[^>]*name="host")[^>]*\breadonly\b/s', $html);
        $this->assertMatchesRegularExpression('/<input(?=[^>]*name="port")[^>]*\breadonly\b/s', $html);
        $this->assertStringContainsString('value="10.0.0.5"', $html, 'Değer görünür, yalnızca değiştirilemez.');

        /* Parola yetkisi verilince kilit kalkar. */
        $yetkili = User::factory()->create();
        $this->ver($yetkili, $grup, ['view', 'update', 'field.host', 'field.port', 'field.url', 'field.password']);
        $html = $this->actingAs($yetkili)
            ->get(route('credentials.index', ['kayit' => $k->uuid, 'pencere' => 'duzenle']))
            ->getContent();
        $this->assertDoesNotMatchRegularExpression('/<input(?=[^>]*name="host")[^>]*\breadonly\b/s', $html);
    }

    #[Test]
    public function guncelleme_hesabi_olarak_gorulmeyen_kayit_secilemez(): void
    {
        $user = User::factory()->create();
        $grup = CredentialGroup::factory()->create();
        $baska = CredentialGroup::factory()->create();
        $this->ver($user, $grup, ['view', 'update', 'field.host', 'field.password']);
        $this->ver($user, $baska, ['view']); // görür ama parolayı göremez

        $k = Credential::factory()->for($grup, 'group')->create();
        $gorunmeyen = Credential::factory()->for(CredentialGroup::factory()->create(), 'group')->create();
        $gorunurAmaGizli = Credential::factory()->for($baska, 'group')->create();
        $sahip = User::factory()->create();
        $kisisel = Credential::factory()->for(CredentialGroup::factory()->create(['is_personal' => true]), 'group')->create(['owner_id' => $sahip->id]);
        $kendiKisisel = Credential::factory()->for(CredentialGroup::factory()->create(['is_personal' => true]), 'group')->create(['owner_id' => $user->id]);
        $acik = Credential::factory()->for($grup, 'group')->create(['name' => 'Acik SSH']);

        foreach ([$gorunmeyen, $gorunurAmaGizli, $kisisel, $kendiKisisel] as $hedef) {
            $this->actingAs($user)->put(route('credentials.update', $k), $this->veri($k, ['rotation_credential_id' => $hedef->id]))
                ->assertSessionHasErrors('rotation_credential_id');
            $this->assertNull($k->fresh()->rotation_credential_id);
        }

        $this->actingAs($user)->put(route('credentials.update', $k), $this->veri($k, ['rotation_credential_id' => $acik->id]))
            ->assertSessionHasNoErrors();
        $this->assertSame($acik->id, $k->fresh()->rotation_credential_id);
    }

    #[Test]
    public function yoneticinin_kurdugu_ve_kullanicinin_goremedigi_baglanti_korunur(): void
    {
        $user = User::factory()->create();
        $grup = CredentialGroup::factory()->create();
        $this->ver($user, $grup, ['view', 'update', 'field.host', 'field.password']);
        $gizli = Credential::factory()->for(CredentialGroup::factory()->create(), 'group')->create();
        $k = Credential::factory()->for($grup, 'group')->create(['rotation_credential_id' => $gizli->id, 'name' => 'A']);

        /* Form seçimi göstermez (boş gelir) ve başka bir alan düzenlenir: bağ kopmaz, hata çıkmaz. */
        $this->actingAs($user)->put(route('credentials.update', $k), $this->veri($k, ['name' => 'B', 'rotation_credential_id' => '']))
            ->assertSessionHasNoErrors();
        $this->assertSame($gizli->id, $k->fresh()->rotation_credential_id);
        $this->assertSame('B', $k->fresh()->name);

        /* Aynı değer geri gönderilse de reddedilmez. */
        $this->actingAs($user)->put(route('credentials.update', $k), $this->veri($k, ['name' => 'C', 'rotation_credential_id' => $gizli->id]))
            ->assertSessionHasNoErrors();
        $this->assertSame('C', $k->fresh()->name);

        /* Ama göremediği kimliği başka yere götüremez: adres değişimi reddedilir. */
        $this->actingAs($user)->put(route('credentials.update', $k), $this->veri($k, ['host' => '203.0.113.7', 'rotation_credential_id' => '']))
            ->assertSessionHasErrors('host');
        $this->assertNotSame('203.0.113.7', $k->fresh()->host);
    }

    #[Test]
    public function kisisel_kayit_kimlik_olarak_kullanilmaz(): void
    {
        $sahip = User::factory()->create();
        $kisisel = Credential::factory()->for(CredentialGroup::factory()->create(['is_personal' => true]), 'group')->create([
            'owner_id' => $sahip->id, 'username' => 'kisisel', 'password' => 'KISISEL-PW',
        ]);
        $k = Credential::factory()->for(CredentialGroup::factory()->create(), 'group')->create(['rotation_credential_id' => $kisisel->id]);

        $this->assertNull(app(MakineHesabi::class)->bul($k->fresh()));
        $this->assertNull(app(MakineHesabi::class)->port($k->fresh()));
        $this->assertNull(app(MakineHesabi::class)->kaynak($k->fresh()));
    }

    #[Test]
    public function gizli_kimlikli_kayitta_adres_ve_hesap_degisimi_kimligin_yeniden_girilmesini_ister(): void
    {
        $user = User::factory()->create();
        $grup = CredentialGroup::factory()->create();
        $this->ver($user, $grup, ['view', 'update', 'field.host', 'field.port', 'field.url', 'field.password', 'rotate']);
        $k = Credential::factory()->for($grup, 'group')->create([
            'host' => '10.0.0.5', 'username' => 'admin',
            'rotation_username' => 'rotadmin', 'rotation_password' => 'ROT-GIZLI',
        ]);

        /* Adres: reddedilir, değişmez. */
        $this->actingAs($user)->put(route('credentials.update', $k), $this->veri($k, ['host' => '203.0.113.9', 'rotation_username' => 'rotadmin']))
            ->assertSessionHasErrors('host');
        /* Hesap adı (root'a çevirme): reddedilir. */
        $this->actingAs($user)->put(route('credentials.update', $k), $this->veri($k, ['username' => 'root', 'rotation_username' => 'rotadmin']))
            ->assertSessionHasErrors('username');
        $this->assertSame('10.0.0.5', $k->fresh()->host);
        $this->assertSame('admin', $k->fresh()->username);

        /* Diğer alanlar sorunsuz. */
        $this->actingAs($user)->put(route('credentials.update', $k), $this->veri($k, ['name' => 'Yeni', 'rotation_username' => 'rotadmin']))
            ->assertSessionHasNoErrors();
        $this->assertSame('Yeni', $k->fresh()->name);

        /* Kimlik yeniden girilirse taşınabilir. */
        $this->actingAs($user)->put(route('credentials.update', $k), $this->veri($k, [
            'host' => '10.0.0.77', 'rotation_username' => 'rotadmin', 'rotation_password' => 'YENI-ROT',
        ]))->assertSessionHasNoErrors();
        $this->assertSame('10.0.0.77', $k->fresh()->host);
        $this->assertSame('YENI-ROT', $k->fresh()->rotation_password);

        /* Yönetici kimliği kendisi tanımlar: muaf. */
        $this->actingAs(User::factory()->admin()->create())
            ->put(route('credentials.update', $k), $this->veri($k, ['host' => '10.0.0.78', 'rotation_username' => 'rotadmin']))
            ->assertSessionHasNoErrors();
        $this->assertSame('10.0.0.78', $k->fresh()->host);
    }

    #[Test]
    public function oneri_gorulmeyen_kaydin_adini_sizdirmaz(): void
    {
        $user = User::factory()->create();
        $grup = CredentialGroup::factory()->create();
        $this->ver($user, $grup, ['view']);
        $k = Credential::factory()->for($grup, 'group')->create(['host' => '10.1.1.1']);
        $gizli = Credential::factory()->for(CredentialGroup::factory()->create(), 'group')->create([
            'host' => '10.1.1.1', 'access_type' => AccessType::Ssh, 'username' => 'root', 'name' => 'GIZLI-AD',
        ]);

        $this->actingAs($user);
        $this->assertNull(app(MakineHesabi::class)->oneri($k));

        $this->actingAs(User::factory()->admin()->create());
        $this->assertSame($gizli->id, app(MakineHesabi::class)->oneri($k)?->id);
    }

    #[Test]
    public function konak_dogrulamasi(): void
    {
        foreach (['10.0.0.5', 'ns1.example.com', 'esx_1.lab', '2001:db8::1', '[2001:db8::1]', 'host', 'a-b.c'] as $iyi) {
            $this->assertTrue(Konak::gecerliMi($iyi), $iyi);
        }

        foreach (['', 'a;b', 'a b', 'x.com;}server{', 'a"b', 'a{b', 'a$b', "a\nb", '-a.com', 'a..b', '[10.0.0.1]', 'http://x', 'a/b', str_repeat('a', 64).'.com'] as $kotu) {
            $this->assertFalse(Konak::gecerliMi($kotu), $kotu);
        }
    }

    #[Test]
    public function adres_alani_nginx_metni_icerdigi_icin_yazarken_dogrulanir(): void
    {
        $user = User::factory()->create();
        $grup = CredentialGroup::factory()->create();
        $this->ver($user, $grup, ['view', 'create', 'update', 'field.host', 'field.url', 'field.password']);

        $this->actingAs($user)->post(route('credentials.store'), [
            'group' => $grup->uuid, 'name' => 'X', 'access_type' => 'ssh', 'operating_system' => 'linux',
            'host' => "10.0.0.1:22;\n}\nserver { listen 1; }", 'is_active' => '1', 'position' => 0,
        ])->assertSessionHasErrors('host');

        $this->actingAs($user)->post(route('credentials.store'), [
            'group' => $grup->uuid, 'name' => 'X', 'access_type' => 'web', 'operating_system' => 'linux',
            'url' => 'https://a;b/', 'is_active' => '1', 'position' => 0,
        ])->assertSessionHasErrors('url');

        $this->assertSame(0, Credential::query()->where('name', 'X')->count());

        /* Doğrulama öncesi yazılmış bir kaydın BAŞKA alanı düzenlenebilir. */
        $eski = Credential::factory()->for($grup, 'group')->create(['host' => 'eski host;']);
        $this->actingAs($user)->put(route('credentials.update', $eski), $this->veri($eski, ['name' => 'Yeni ad', 'host' => 'eski host;']))
            ->assertSessionHasNoErrors();
        $this->assertSame('Yeni ad', $eski->fresh()->name);
    }

    #[Test]
    public function havuz_uretimi_gecersiz_adresi_atlar_digerlerini_yazar(): void
    {
        /*
         * Kayıtlar VEKİLLENEN bir türde olmalı: iLO işletim sisteminden
         * (bmc) tanınır ve tür açık olmalı. 'linux' yazılsaydı hiçbir kayıt
         * havuza girmez, sınama boş bir çıktıyı denetlerdi.
         */
        config(['remote.arayuz.turler' => ['ilo']]);

        $grup = CredentialGroup::factory()->create();
        Credential::factory()->for($grup, 'group')->create([
            'access_type' => AccessType::Ssh, 'operating_system' => 'bmc',
            'name' => 'iLO iyi', 'url' => 'https://192.0.2.202:2266', 'host' => '192.0.2.202', 'platform' => 'HPE iLO 4',
        ]);
        /* Doğrulamadan önce yazılmış kötü kayıtlar: hepsi için üretim sürmeli. */
        foreach (["192.0.2.9;\n}\nserver {", 'x.com;}server{', 'a b'] as $i => $kotu) {
            Credential::factory()->for($grup, 'group')->create([
                'access_type' => AccessType::Ssh, 'operating_system' => 'bmc',
                'name' => 'kotu'.$i, 'url' => 'https://'.str_replace([' ', "\n"], '', $kotu), 'host' => $kotu, 'platform' => 'HPE iLO 4',
            ]);
        }

        Artisan::call(ArayuzHavuzlariCommand::class === '' ? '' : 'erisim:arayuz-havuzlari');
        $metin = Artisan::output();

        $this->assertStringContainsString('    server 192.0.2.202:2266;', $metin);
        $this->assertStringNotContainsString('}server', $metin);
        $this->assertStringNotContainsString('server {', $metin);
        $this->assertDoesNotMatchRegularExpression('/server\s+[^;\n]*[{}"][^;\n]*;/', $metin);
        $this->assertStringContainsString('map $arayuz_hedef $arayuz_havuz', $metin);
        /* Metindeki her "server X;" satırı yalnızca konak:port taşır. */
        preg_match_all('/^    server (.+);$/m', $metin, $m);
        foreach ($m[1] as $hedef) {
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9_.\-\[\]:]+:\d{1,5}$/', $hedef);
        }
    }

    #[Test]
    public function adla_kayitli_hedef_havuza_girmez_istek_aninda_cozulur(): void
    {
        config(['remote.arayuz.turler' => ['ilo']]);

        $grup = CredentialGroup::factory()->create();
        foreach (['192.0.2.202', 'ilo.ornek.test'] as $konak) {
            Credential::factory()->for($grup, 'group')->create([
                'access_type' => AccessType::Ssh, 'operating_system' => 'bmc',
                'name' => $konak, 'url' => 'https://'.$konak.':2266', 'host' => $konak, 'platform' => 'HPE iLO 4',
            ]);
        }

        $resolv = (string) tempnam(sys_get_temp_dir(), 'resolv');
        file_put_contents($resolv, "# yorum\nnameserver 127.0.0.11\nnameserver fd00::53\nnameserver 1.2.3.4;}\noptions ndots:0\n");

        Artisan::call('erisim:arayuz-havuzlari', ['--resolv' => $resolv]);
        $metin = Artisan::output();

        /*
         * Adres havuzda; ad HİÇBİR sunucu grubunda yok. nginx grup içindeki adı
         * açılışta çözmeye çalışır ve çözemezse hiç açılmaz.
         */
        $this->assertStringContainsString('    server 192.0.2.202:2266;', $metin);
        $this->assertStringNotContainsString('server ilo.ornek.test', $metin);
        $this->assertStringNotContainsString('"ilo.ornek.test:2266"', $metin);
        $this->assertStringContainsString('# Havuzsuz, istek anında çözülür: ilo.ornek.test:2266', $metin);

        /* Ad istek anında, kapsayıcının çözücüsüyle çözülür; geçersiz satır nginx metnine girmez. */
        $this->assertStringContainsString("\nresolver 127.0.0.11 [fd00::53] valid=30s ipv6=off;\n", $metin);
        $this->assertStringNotContainsString('1.2.3.4', $metin);

        /* Çözücü yoksa satır yazılmaz; eşleme yine durur, nginx yine açılır. */
        file_put_contents($resolv, "options ndots:0\n");
        Artisan::call('erisim:arayuz-havuzlari', ['--resolv' => $resolv]);
        $cozucusuz = Artisan::output();
        unlink($resolv);

        $this->assertDoesNotMatchRegularExpression('/^resolver /m', $cozucusuz);
        $this->assertStringContainsString('map $arayuz_hedef $arayuz_havuz', $cozucusuz);
    }
}
