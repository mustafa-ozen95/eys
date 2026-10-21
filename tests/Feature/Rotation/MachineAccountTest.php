<?php

declare(strict_types=1);

namespace Tests\Feature\Rotation;

use App\Enums\AccessType;
use App\Models\Credential;
use App\Models\User;
use App\Services\Rotation\Drivers\PleskPasswordDriver;
use App\Services\Rotation\MachineAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * MAKİNENİN SSH KİMLİĞİ KASADAN SEÇİLİR.
 *
 * Plesk'te panelin `admin` parolasını değiştiren şey makinenin SSH
 * kimliğidir. Bu kimlik Plesk kaydına ayrıca giriliyordu — oysa aynı
 * makinenin SSH kaydı kasada çoğu zaman zaten duruyor. Aynı parolayı kasada
 * iki yerde tutmak iki sorun demekti: SSH kaydı döndüğünde kopya sessizce
 * eskiyor ve tur "bağlanamadı" diyor; üstelik bir sır ne kadar çok yerde
 * durursa o kadar çok yerden sızıyor. Bu yüzden SSH kimliği mevcut kayıttan
 * çekilir.
 *
 * AYNI ADRESLİ SSH KAYDI ÖNCE KENDİLİĞİNDEN KULLANILIYORDU; artık yalnızca
 * öneriliyor: hesap seçilmemişse "parolayı güncelle" gizlenir. Uzaktaki bir makinede parola değiştiren
 * bir işin hangi kimlikle bağlanacağı, ekrana bakan kişinin göremediği bir
 * kuraldan çıkmamalı.
 */
class MachineAccountTest extends TestCase
{
    use RefreshDatabase;

    private function kayit(array $alanlar = []): Credential
    {
        return Credential::factory()->create(array_merge([
            'host' => '192.0.2.60',
            'access_type' => AccessType::Panel,
            'username' => 'admin',
            'password' => 'panel-parolasi',
        ], $alanlar));
    }

    /**
     * SEÇİLEN KAYIT KULLANILIR ve kopya tutulmaz.
     *
     * Parola her turda o kayıttan okunur; SSH parolası döndüğünde burada
     * eskiyen bir kopya kalmaz.
     */
    #[Test]
    public function secilen_kayit_kullanilir_ve_guncel_kalir(): void
    {
        $ssh = $this->kayit([
            'access_type' => AccessType::Ssh,
            'username' => 'root',
            'password' => 'ilk-parola',
        ]);

        $plesk = $this->kayit([
            'rotation_username' => 'eski-kopya',
            'rotation_password' => 'eski-kopya-parolasi',
            'rotation_credential_id' => $ssh->id,
        ]);

        $this->assertSame(['root', 'ilk-parola'], app(MachineAccount::class)->bul($plesk));

        /* SSH kaydının parolası döndü: bağ olduğu için kendiliğinden güncel. */
        $ssh->forceFill(['password' => 'yeni-parola'])->save();

        $this->assertSame(['root', 'yeni-parola'], app(MachineAccount::class)->bul($plesk->fresh()));
    }

    /** ELLE VERİLEN de kimliktir: kasada karşılığı olmayan hesaplar için. */
    #[Test]
    public function kaydin_kendi_alani_da_kimliktir(): void
    {
        $plesk = $this->kayit([
            'rotation_username' => 'ozel',
            'rotation_password' => 'ozel-parola',
        ]);

        $this->assertSame(['ozel', 'ozel-parola'], app(MachineAccount::class)->bul($plesk));
    }

    /**
     * SEÇİLMEDİYSE KİMLİK YOK.
     *
     * Aynı adreste bir SSH kaydı dursa bile kendiliğinden kullanılmaz;
     * hangi kimlikle bağlanılacağı ekranda görünmeli.
     */
    #[Test]
    public function secilmediyse_ayni_adresli_kayit_kendiliginden_kullanilmaz(): void
    {
        $plesk = $this->kayit();

        $this->kayit([
            'access_type' => AccessType::Ssh,
            'username' => 'root',
            'password' => 'ssh-parolasi',
        ]);

        $this->assertNull(app(MachineAccount::class)->bul($plesk));
    }

    /** ...ama ÖNERİLİR: kullanıcı neyi seçeceğini ekrandan bilsin. */
    #[Test]
    public function ayni_adresli_kayit_onerilir(): void
    {
        /* Öneriyi bakanın görebildiği kayıtlar arasından seçer: bakan yoksa öneri de yok. */
        $this->actingAs(User::factory()->admin()->create());

        $plesk = $this->kayit();

        $ssh = $this->kayit([
            'access_type' => AccessType::Ssh,
            'username' => 'root',
            'password' => 'ssh-parolasi',
        ]);

        $this->assertSame($ssh->id, app(MachineAccount::class)->oneri($plesk)?->id);
    }

    /** BAŞKA ADRESİN kaydı önerilmez. */
    #[Test]
    public function baska_adresin_ssh_kaydi_onerilmez(): void
    {
        $plesk = $this->kayit(['host' => '192.0.2.60']);

        $this->kayit([
            'host' => '192.0.2.61',
            'access_type' => AccessType::Ssh,
            'username' => 'root',
            'password' => 'baska-makine',
        ]);

        $this->assertNull(app(MachineAccount::class)->oneri($plesk));
    }

    /** PASİF KAYIT ÖNERİLMEZ: "artık bunu kullanmayın" demektir. */
    #[Test]
    public function pasif_ssh_kaydi_onerilmez(): void
    {
        $plesk = $this->kayit();

        $this->kayit([
            'access_type' => AccessType::Ssh,
            'username' => 'root',
            'password' => 'ssh-parolasi',
            'is_active' => false,
        ]);

        $this->assertNull(app(MachineAccount::class)->oneri($plesk));
    }

    /**
     * SÜRÜCÜ "SEÇİLMEDİ" DİYOR ve ne seçileceğini de söylüyor.
     *
     * Gerekçe ekranda olduğu gibi yazılıyor; kullanıcı "neden bu düğme yok"
     * sorusunu kaynağa bakmadan çözebilmeli.
     */
    #[Test]
    public function surucu_secilmemis_kimligi_sebep_sayar(): void
    {
        /* Öneriyi bakanın görebildiği kayıtlar arasından seçer: bakan yoksa öneri de yok. */
        $this->actingAs(User::factory()->admin()->create());

        $plesk = $this->kayit(['username' => 'admin']);

        $ssh = $this->kayit([
            'access_type' => AccessType::Ssh,
            'name' => 'srv-a SSH',
            'username' => 'root',
            'password' => 'ssh-parolasi',
        ]);

        $surucu = new PleskPasswordDriver;

        $sebep = $surucu->unsupportedReason($plesk->fresh());

        $this->assertNotNull($sebep);
        $this->assertStringContainsString('seçilmemiş', (string) $sebep);
        $this->assertStringContainsString('#'.$ssh->id, (string) $sebep);

        /* Seçildiğinde sebep kalmaz. */
        $plesk->forceFill(['rotation_credential_id' => $ssh->id])->save();

        $this->assertNull($surucu->unsupportedReason($plesk->fresh()));
    }

    /**
     * PAROLASI BOŞ KİMLİK KABUL EDİLMEZ.
     *
     * Boş parolayla SSH'a gitmek, turu makinenin karşısında düşürmekti.
     */
    #[Test]
    public function parolasi_bos_kimlik_sebep_sayar(): void
    {
        $plesk = $this->kayit([
            'username' => 'admin',
            'rotation_username' => 'root',
            'rotation_password' => '',
        ]);

        $sebep = (new PleskPasswordDriver)->unsupportedReason($plesk);

        $this->assertNotNull($sebep);
        $this->assertStringContainsString('parolası boş', (string) $sebep);
    }

    /**
     * PORT DA SEÇİLEN KAYITTAN GELİR.
     *
     * Kimliği bir kayıttan alıp portu varsayımdan almak, o kaydın yarısını
     * kullanmak olurdu. Örnek: makinenin SSH'ı 2222'de, sürücünün varsayılanı
     * 22; port kayıttan alınmazsa tur "Connection refused" ile düşer.
     */
    #[Test]
    public function port_secilen_kayittan_gelir(): void
    {
        $ssh = $this->kayit([
            'access_type' => AccessType::Ssh,
            'username' => 'root',
            'password' => 'ssh-parolasi',
            'port' => 2222,
        ]);

        $plesk = $this->kayit(['port' => 8443, 'rotation_credential_id' => $ssh->id]);

        $this->assertSame(2222, app(MachineAccount::class)->port($plesk));

        /* Seçim yoksa port da yok; sürücünün varsayılanı kalır. */
        $this->assertNull(app(MachineAccount::class)->port($this->kayit()));
    }

    /** Sürücü de o portu kullanır: kural tek yerde kalmasın diye sınanıyor. */
    #[Test]
    public function surucu_secilen_kaydin_portuna_baglanir(): void
    {
        $ssh = $this->kayit([
            'access_type' => AccessType::Ssh,
            'username' => 'root',
            'password' => 'ssh-parolasi',
            'port' => 2222,
        ]);

        $plesk = $this->kayit(['port' => 8443, 'rotation_credential_id' => $ssh->id]);

        $surucu = new PleskPasswordDriver;

        $yansima = new \ReflectionMethod($surucu, 'connectPort');

        $this->assertSame(2222, $yansima->invoke($surucu, $plesk));
    }

    /** Kimliğin nereden geldiği söylenebilmeli: ekranda ve kayıtta yazacak. */
    #[Test]
    public function kimligin_kaynagi_soylenir(): void
    {
        $ssh = $this->kayit([
            'access_type' => AccessType::Ssh,
            'username' => 'root',
            'password' => 'ssh-parolasi',
        ]);

        $plesk = $this->kayit(['rotation_credential_id' => $ssh->id]);

        $this->assertStringContainsString('#'.$ssh->id, (string) app(MachineAccount::class)->kaynak($plesk));
    }
}
