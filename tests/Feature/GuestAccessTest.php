<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Credential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * "Giriş yapmayan erişim bilgisi göremez."
 *
 * Bu testin işi açığın kapandığını göstermek değil, BİR DAHA AÇILAMAYACAĞINI
 * göstermektir: `auth` grubunun dışına düşen her yeni uç burada patlar.
 */
class GuestAccessTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function oturumsuz_kullanici_her_ucta_girise_yonlenir(): void
    {
        $credential = Credential::factory()->create();

        $uclar = [
            ['get', '/'],
            ['get', route('credentials.index')],
            ['get', route('credentials.index', ['pencere' => 'ekle'])],
            ['get', route('credentials.index', ['kayit' => $credential->uuid, 'pencere' => 'duzenle'])],
            ['post', route('credentials.store')],
            ['put', route('credentials.update', $credential)],
            ['get', route('groups.index')],
            ['get', route('users.index')],
            ['get', route('logs.index')],
        ];

        foreach ($uclar as [$method, $url]) {
            $this->{$method}($url)->assertRedirect(route('login'));
        }

        $this->post(route('ajax.credentials.password', $credential))
            ->assertRedirect(route('login'));
    }

    /**
     * Oturumsuz erişilebilen uçlar SAYILIDIR.
     *
     * Yukarıdaki test bilinen uçları koruyor; bu test TERSİNİ yapıyor —
     * `auth` dışında kalan her yeni uç burada patlar. Liste bilerek elle
     * tutulur: otomatik geçen bir kural, sessizce açılan bir kapı demekti.
     *
     * Merkez uçları kayıt akışı DEĞİLDİR: eşleşmeyen kimlik için hesap
     * açılmaz (bkz. CenterLoginTest::test_eslesmeyen_kimlik_icin_hesap_acilmaz).
     */
    #[Test]
    public function oturumsuz_uclar_sayilidir(): void
    {
        $acik = collect(Route::getRoutes()->getRoutes())
            /*
             * Ölçüt "auth ile başlayan bir ara katman taşıyor mu": API uçları
             * `auth:api` taşır ve onlar da korunmuştur. Yalnızca 'auth'
             * aransaydı bütün API oturumsuz sanılırdı.
             */
            ->filter(fn ($rota): bool => ! collect($rota->gatherMiddleware())
                ->contains(fn ($ara): bool => is_string($ara) && str_starts_with($ara, 'auth')))
            ->reject(fn ($rota): bool => str_starts_with($rota->uri(), '_'))
            ->map(fn ($rota): string => $rota->uri())
            ->unique()
            ->sort()
            ->values()
            ->all();

        /*
         * `storage/{path}` ARTIK YOK: Laravel'in kendi ucu, disk 'serve'
         * ayarıyla açıldığında GET ve PUT olmak üzere iki oturumsuz rota
         * tanımlıyordu. Panelde o adresleri üreten hiçbir yer olmadığı için
         * kapatıldı (bkz. config/filesystems.php) — kullanılmayan bir uç,
         * denetimde her seferinde yeniden sorgulanan bir yüzeydir.
         */
        /*
         * `ajax/uzak-oturum/dogrula` bilinçli olarak "auth" DIŞINDADIR.
         *
         * nginx auth_request yalnızca 2xx/401/403 anlar; "auth" katmanı
         * kimliksiz isteği login'e YÖNLENDİRİR (302) ve nginx onu 500 sayar.
         * Kimlik, hesabın etkinliği ve konsol yetkisi controller'ın kendi
         * içinde sorulur (bkz. RemoteProxyGuardController) — yani uç açık
         * değil, kapısı başka yerde.
         */
        /*
         * `up` KONTEYNERİN SAĞLIK YOKLAMASIDIR ve oturum isteyemez.
         *
         * Docker her 30 saniyede bir buraya bakıyor; kimlik isteseydi yanıt
         * 302 olur, panel ölü sayılır ve sürekli yeniden başlatılırdı. Uç
         * hiçbir veri döndürmez: yalnızca uygulamanın ayakta olduğunu söyler
         * (bkz. bootstrap/app.php → health).
         */
        /*
         * `api/kimlik/tazele` OTURUM TAŞIMAZ ve taşıyamaz.
         *
         * Çağıran bir insan değil, Kimlik Yönetim Merkezi'dir: "şu kimlik
         * değişti" der. Kimlik doğrulaması imzadır (istemciye verilen bildirim
         * sırrıyla HMAC) ve gelen gövdeye zaten güvenilmez — panel doğruyu
         * merkezden kendi sorar. Oturum aransaydı bildirim hiç ulaşamazdı.
         */
        /*
         * `ajax/cihaz-oturum/dogrula` ve `.../izle` de aynı sebeple "auth"
         * DIŞINDADIR: ikisi de nginx'in iç isteğidir.
         *
         * Bekçiyi auth_request çağırıyor ve 302'yi 500 sayıyor. İzleme ucunu
         * ise mirror çağırıyor; CSRF jetonu taşıyamaz. İkisi de kimliği
         * kendi içinde soruyor: bekçi kimliksiz isteğe 401, izleme ucu ise
         * HİÇBİR ŞEY yapmıyor ve kaydı yalnızca isteği yapanın kendi açık
         * oturumuna yazıyor.
         */
        /*
         * `cihaz-oturum/...` uçları da "auth" DIŞINDADIR ve bu, güvenliği
         * ZAYIFLATMAK değil GÜÇLENDİRMEK için.
         *
         * Vekillenen arayüz ayrı bir alan adından verilebiliyor ve orada
         * panelin oturum çerezi YOKTUR (konak-özeldir). Zaten olmasını da
         * istemeyiz: olsaydı cihazın arayüzünden çıkan betik panele, oturumu
         * açan kişi adına istek atabilirdi — tarayıcı çerezi kendiliğinden
         * ekler, betiğin onu okumasına gerek yok.
         *
         * Kimlik bunun yerine ANLIK bir devir jetonuyla taşınır (tek
         * kullanımlık, ilk açılışta yanar) ve devamı yalnızca vekil kökeninde
         * yaşayan HttpOnly bir çerezle sürer. Her uç o çerezi arar, adresle
         * eşleştirir ve yetkiyi kayıttaki kişiye göre yeniden sorar.
         *
         * Uçlar İKİ biçimde birden duruyor: ayrı kökende arayüz KÖKTE olduğu
         * için adres önekli olamıyor (Plesk ve iLO kökü baştan aşağı
         * kullanıyor), panelin kendi kökeninde ise önek şart.
         */
        $this->assertSame([
            'ajax/cihaz-oturum/dogrula',
            'ajax/cihaz-oturum/izle',
            'ajax/uzak-oturum/dogrula',
            'api/kimlik/cikis',
            'api/kimlik/tazele',
            'cihaz-oturum/acilis.js',
            /*
             * "Ayrıldım" haberi. Oturumu KAPATMAZ, sessiz sayar; kimliği de
             * kendi çerezinden okur. Panel oturumu istemez çünkü sayfa
             * kapanırken sendBeacon ile atılıyor ve cihazın kökeninde panelin
             * çerezi yok.
             */
            'cihaz-oturum/ayril',
            'cihaz-oturum/devir/{devir}',
            'cihaz-oturum/kimlik',
            'cihaz-oturum/sayfa',
            'cihaz-oturum/{token}',
            'cihaz-oturum/{token}/acilis.js',
            'cihaz-oturum/{token}/kimlik',
            'giris',
            'giris/merkez',
            'giris/merkez/geri',
            'up',
        ], $acik);
    }

    #[Test]
    public function kayit_olma_ucu_yoktur(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register')->assertNotFound();
    }

    #[Test]
    public function pasif_hesap_dogru_parolayla_da_giremez(): void
    {
        $user = User::factory()->passive()->create();

        $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'gecerli-parola-1!',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    #[Test]
    public function etkin_hesap_girebilir_ve_giris_kayda_gecer(): void
    {
        $user = User::factory()->create();

        $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'gecerli-parola-1!',
        ])->assertRedirect(route('credentials.index'));

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $user->id,
            'action' => 'logged_in',
        ]);
        $this->assertNotNull($user->fresh()->last_login_at);
    }
}
