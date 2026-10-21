<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AccessType;
use App\Models\Credential;
use App\Models\User;
use App\Services\CihazArayuzu\ArayuzOturumu;
use App\Services\CihazArayuzu\ArayuzTuru;
use App\Services\Portal\PortalLogin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * RSPAMD — sır çerezde değil, BAŞLIKTA taşınır.
 *
 * Öteki arayüzlerde panel cihazda giriş yapar, dönen çerezi saklar ve vekil
 * her isteğe onu ekler. Rspamd'ın oturum kavramı YOK — hesap kavramı bile
 * yok: tek bir parola var ve arayüz onu her isteğin `Password` başlığında
 * gönderiyor.
 *
 * Rspamd 4.1.0'ın (mailcow içinde) yanıtları:
 *
 *     GET /rspamd/auth               → 401 {"error":"Unauthorized"}
 *     GET /rspamd/auth  Password: …  → 200 {"auth":"ok","version":"4.1.0",…}
 *     GET /rspamd/stat  Password: …  → 200
 *
 * Bu yüzden parolayı VEKİL koyar; tarayıcıya hiç inmez.
 */
final class RspamdArayuzTest extends TestCase
{
    use RefreshDatabase;

    private function kayit(array $ustune = []): Credential
    {
        return Credential::factory()->create([
            'name' => 'Rspamd',
            'access_type' => AccessType::Panel,
            'host' => 'posta.ornek.test',
            'port' => null,
            'url' => 'https://posta.ornek.test/rspamd',
            'username' => null,
            'password' => 'DENETIM-PAROLASI',
            ...$ustune,
        ]);
    }

    #[Test]
    public function adresinden_taninir(): void
    {
        config(['remote.arayuz.turler' => ['rspamd']]);

        $this->assertSame(ArayuzTuru::Rspamd, ArayuzTuru::icin($this->kayit()));
        $this->assertNull(ArayuzTuru::icin($this->kayit(['url' => 'https://posta.ornek.test/panel'])));
    }

    /** Vekil açıkken form yolu devreye girmez; ikisi aynı kaydı sahiplenemez. */
    #[Test]
    public function vekil_acikken_form_yolu_devre_disi(): void
    {
        config(['remote.arayuz.turler' => ['rspamd']]);

        $this->assertFalse(PortalLogin::hazirMi($this->kayit([
            'username' => 'yonetici',
            'login_url' => 'https://posta.ornek.test/rspamd/auth',
            'login_username_field' => 'user',
            'login_password_field' => 'pass',
        ])));
    }

    /**
     * SIR HANGİ BAŞLIKTA GİDER.
     *
     * Yalnızca Rspamd'da bir başlık var; ötekilerde oturum çerezde duruyor ve
     * onlara anlamsız bir `Password:` satırı eklemenin karşılığı yok.
     */
    #[Test]
    public function gizli_basligi_yalnizca_rspamdde_vardir(): void
    {
        $this->assertSame('Password', ArayuzTuru::Rspamd->gizliBasligi());

        foreach ([ArayuzTuru::Plesk, ArayuzTuru::Ilo, ArayuzTuru::Vcenter,
            ArayuzTuru::DirectAdmin, ArayuzTuru::Qnap, ArayuzTuru::Esxi, ArayuzTuru::Vami] as $tur) {
            $this->assertNull($tur->gizliBasligi(), $tur->value.' için başlık tanımlanmamalı.');
        }
    }

    /**
     * CİHAZA GİRİŞ YAPILMAZ.
     *
     * Açılacak bir oturum yok; sunucu tarafı giriş arayan kod bu türde
     * cihaza boşuna istek atardı.
     */
    #[Test]
    public function sunucuda_giris_yapilmaz(): void
    {
        $this->assertFalse(ArayuzTuru::Rspamd->sunucudaGiris());
        $this->assertSame('/rspamd/', ArayuzTuru::Rspamd->cihazYolu());
    }

    /**
     * ARAYÜZ VEKİLİN KÖKÜNDE AÇILIR — arayüzün kendi kodu bunu dayatıyor.
     *
     * js/main.js tabanı şöyle kuruyor:
     *
     *     location.hostname + (port) +
     *     (location.pathname !== "/" ? location.pathname : "")
     *
     * Sayfa kökte değilse bütün API çağrıları o yola yapışıyor:
     * /cihaz-oturum/sayfa adresinde açılan arayüz "/cihaz-oturum/sayfastat"
     * ister, 404 alır ve ekrana "Cannot get server status: HTTP 404" yazar.
     *
     * Açılış betiği de bu yüzden YOK: betik sayfayı o adrese taşıyor ve
     * gerekmiyor — /stat 200 dönünce arayüz giriş kutusunu hiç göstermiyor.
     */
    #[Test]
    public function kokte_acilir_ve_acilis_betigi_yoktur(): void
    {
        $this->assertSame('/', ArayuzTuru::Rspamd->inisYolu());
        $this->assertNull(ArayuzTuru::Rspamd->acilisGorunumu());
        $this->assertFileDoesNotExist(resource_path('views/credentials/arayuz/rspamd.blade.php'));
    }

    /**
     * OTURUM KAYDINDA SIR DURUR — ve oraya PAROLANIN KENDİSİ yazılır.
     *
     * Vekil her isteğe onu koyacak. Kayıt panelde kalıyor; tarayıcıya ne
     * parola ne de bir oturum belirteci iniyor.
     */
    #[Test]
    public function oturum_kaydina_parola_yazilir(): void
    {
        config(['remote.arayuz.turler' => ['rspamd']]);

        $kayit = $this->kayit();
        $kullanici = User::factory()->admin()->create();

        $oturum = app(ArayuzOturumu::class)->ac($kayit, $kullanici->id);

        $saklanan = app(ArayuzOturumu::class)->bul($oturum['token']);

        $this->assertSame('DENETIM-PAROLASI', $saklanan['gizli'] ?? null);

        /* Çerez yolu bu türde hiç kullanılmaz. */
        $this->assertSame('', $saklanan['cerez'] ?? '');
    }

    /**
     * KULLANICI ADI OLMADAN DA AÇILIR.
     *
     * Kaydın arayüz türünü hesaplayan yol kullanıcı adını ŞART koşuyordu ve
     * Rspamd'ın hesabı olmadığı için tür hiç hesaplanmıyordu: ekranda düğme
     * çıkmıyor, sebebi de yazmıyordu. Şart artık türe
     * ait; ötekilerde aynen duruyor.
     */
    #[Test]
    public function kullanici_adi_olmadan_da_arayuz_turu_bulunur(): void
    {
        config(['remote.arayuz.turler' => ['rspamd', 'qnap']]);

        $this->assertSame(ArayuzTuru::Rspamd, $this->kayit()->arayuz_turu);
        $this->assertFalse(ArayuzTuru::Rspamd->kullaniciAdiGerekir());

        /* Hesabı olan arayüzlerde şart yerinde: kullanıcı adı yoksa tür yok. */
        $qnap = Credential::factory()->create([
            'access_type' => AccessType::Panel,
            'host' => 'nas.ornek.test',
            'url' => 'https://nas.ornek.test',
            'login_url' => 'https://nas.ornek.test/cgi-bin/authLogin.cgi',
            'username' => null,
            'password' => 'parola',
        ]);

        $this->assertTrue(ArayuzTuru::Qnap->kullaniciAdiGerekir());
        $this->assertNull($qnap->arayuz_turu);
    }
}
