<?php

declare(strict_types=1);

use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\Ajax\ArayuzIzlemeController;
use App\Http\Controllers\Ajax\ArayuzVekilBekcisi;
use App\Http\Controllers\Ajax\CredentialPasswordController;
use App\Http\Controllers\Ajax\RemoteProxyGuardController;
use App\Http\Controllers\Ajax\RemoteSessionController;
use App\Http\Controllers\AltGrupController;
use App\Http\Controllers\ApiTokenController;
use App\Http\Controllers\ArayuzOturumController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\MerkezGirisController;
use App\Http\Controllers\BulkCredentialRotationController;
use App\Http\Controllers\CredentialController;
use App\Http\Controllers\CredentialExportController;
use App\Http\Controllers\CredentialRotationController;
use App\Http\Controllers\CredentialRotationProgressController;
use App\Http\Controllers\ErrorLogController;
use App\Http\Controllers\GroupController;
use App\Http\Controllers\GuncellemeController;
use App\Http\Controllers\Hesap\ProfilController;
use App\Http\Controllers\PortalSessionController;
use App\Http\Controllers\RemoteSessionPageController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\RotationRepairController;
use App\Http\Controllers\SertifikaController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\TopluTurController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\UserPermissionController;
use App\Http\Controllers\VeritabaniController;
use Illuminate\Support\Facades\Route;

/*
 * Giriş ekranı. Oturum açmamış kullanıcının görebildiği TEK ekran budur;
 * kayıt olma ucu yoktur — hesabı yönetici açar.
 */
Route::middleware('guest')->group(function (): void {
    Route::get('giris', [LoginController::class, 'create'])->name('login');
    /*
     * Giriş hız sınırı KAPATILABİLİR: LOGIN_THROTTLE=0 verildiğinde ara katman
     * hiç eklenmez. `throttle:0,1` yazmak sınırı kaldırmaz, dakikada SIFIR
     * isteğe indirir ve girişi tümüyle kapatırdı.
     *
     * BU TEK FREN DEĞİLDİR. İkincisi LoginRequest'te: art arda yanlış
     * paroladan sonra e-posta+IP başına kilit (config/auth.php,
     * max_attempts). İkisi ayrı şeyi sayar, ikisi de ayardan kapatılır ve
     * varsayılanda ikisi de KAPALIDIR.
     *
     * İkisi birden kapalıyken bu panelde parola denemesinin önünde hiçbir
     * fren kalmaz; koruma dışarıdan gelmelidir (ters vekil, güvenlik duvarı,
     * ağın kendisi). Dışarı açılacaksa ikisine de bir sayı verin.
     */
    $girisRotasi = Route::post('giris', [LoginController::class, 'store']);

    if ((int) config('auth.login_throttle') > 0) {
        $girisRotasi->middleware('throttle:'.config('auth.login_throttle').',1');
    }

    /*
     * Kimlik Yönetim Merkezi ile giriş — İKİNCİ yol, tek yol değil.
     *
     * Parolayla giriş yukarıda yerinde duruyor; merkez erişilemez olduğunda
     * kasaya girilebilmeli, çünkü diğer bütün sistemlerin parolaları burada.
     * Kapalı kurulumda controller 404 döner.
     *
     * Dönüş ucu GET ve gövdesiz olmak zorunda: kullanıcı merkezden buraya
     * tarayıcı yönlendirmesiyle gelir, POST edemez. CSRF'in yerini oturumda
     * saklanan `state` tutar (bkz. MerkezGirisController).
     */
    Route::get('giris/merkez', [MerkezGirisController::class, 'baslat'])->name('kimlik.giris');
    Route::get('giris/merkez/geri', [MerkezGirisController::class, 'geri'])->name('kimlik.donus');
});

/*
 * KONSOL PROXY'SİNİN BEKÇİSİ.
 *
 * Yetkilendirme: kontrolü controller'ın kendisi yapar (bkz.
 * RemoteProxyGuardController). "auth" ara katmanının DIŞINDADIR: o katman
 * kimliksiz isteği login'e yönlendirir (302), nginx auth_request ise
 * 2xx/401/403 dışındaki her yanıtı 500 sayar.
 */
Route::middleware('web')
    ->get('ajax/uzak-oturum/dogrula', RemoteProxyGuardController::class)
    ->name('ajax.remote.guard');

/*
 * ESXi vekilinin bekçisi. Aynı gerekçeyle "auth" dışındadır; ayrıca nginx'e
 * HEDEFİ de bu uç söyler (X-Eys-Hedef), çünkü hedefin istekten değil kendi
 * kaydımızdan gelmesi şart (bkz. EsxiProxyGuardController).
 */
Route::middleware('cihaz-arayuzu')
    ->get('ajax/cihaz-oturum/dogrula', ArayuzVekilBekcisi::class)
    ->name('ajax.arayuz.guard');

/*
 * Vekilden geçen SOAP çağrılarının aynası: "ne yapıldı" sorusunun cevabı
 * gövdedeki yöntem adında. nginx'in İÇ isteğidir (mirror), dışarıdan
 * çağrılamaz; asıl isteği de beklemez.
 */
Route::middleware('cihaz-arayuzu')
    ->post('ajax/cihaz-oturum/izle', ArayuzIzlemeController::class)
    ->name('ajax.arayuz.trace');

/*
 * VEKİLLENEN ARAYÜZ — panelin "web" katmanının DIŞINDA, bilerek.
 *
 * Bu uçlar vekilin alan adından isteniyor ve orada panelin oturum çerezi
 * YOKTUR: çerez konak-özeldir ve başka bir alan adına gitmez. Zaten gitmesini
 * de istemeyiz — gitseydi cihazın arayüzünden çıkan betik panele, oturumu
 * açan kişi adına istek atabilirdi.
 *
 * Kimlik bunun yerine ANLIK bir devir jetonuyla taşınır (tek kullanımlık,
 * devir ucunda yanar) ve devamı yalnızca vekil kökeninde yaşayan HttpOnly bir
 * çerezle sürer. Yetki her istekte kayıttaki kişiye göre yeniden sorulur.
 *
 * OTURUM KATMANI DA YOK ve bu önemli: "web" grubu her istekte panelin oturumunu
 * açıp çerezini yeniliyor. Vekil kökeni panelle AYNI ALAN ADINDA (yalnızca
 * portu farklı) ve çerezler portu umursamıyor; cihaz arayüzünün attığı istekler
 * panelin oturum çerezini eziyor ve kullanıcı panelden DÜŞÜYORDU. Bu uçların
 * oturuma zaten ihtiyacı yok: kimliği kendi çerezleri taşıyor.
 */
Route::middleware('cihaz-arayuzu')->group(function (): void {
    $onek = ltrim((string) config('remote.arayuz.prefix'), '/');

    Route::get($onek.'/devir/{devir}', [ArayuzOturumController::class, 'devral'])
        ->whereUuid('devir')
        ->name('credentials.arayuz.handoff');

    /*
     * Ayrı kökende arayüz KÖKTE duruyor; nginx kökü buraya yönlendiriyor ve
     * kimliği çerez taşıyor. Panelin kendi kökeninde ise adres önekli.
     */
    Route::get($onek.'/sayfa', [ArayuzOturumController::class, 'sayfa'])
        ->name('credentials.arayuz.root');

    Route::get($onek.'/kimlik', [ArayuzOturumController::class, 'kimlik'])
        ->name('credentials.arayuz.identity.root');

    Route::get($onek.'/acilis.js', [ArayuzOturumController::class, 'acilis'])
        ->name('credentials.arayuz.boot.root');

    Route::get($onek.'/{token}/', [ArayuzOturumController::class, 'sayfa'])
        ->whereUuid('token')
        ->name('credentials.arayuz.page');

    Route::get($onek.'/{token}/kimlik', [ArayuzOturumController::class, 'kimlik'])
        ->whereUuid('token')
        ->name('credentials.arayuz.identity');

    Route::get($onek.'/{token}/acilis.js', [ArayuzOturumController::class, 'acilis'])
        ->whereUuid('token')
        ->name('credentials.arayuz.boot');

    /*
     * "Sayfadan ayrıldım" haberi. Tarayıcı kapanırken sendBeacon ile atılır;
     * yanıtı beklenmez, beklenemez de.
     *
     * Neden ayrı bir uç: cihazda açtığımız oturumu cihazda kapatmak bize
     * düşüyor ve sessizlikten anlamak dakikalar alıyor. Bu uç oturumu HEMEN
     * KAPATMAZ, yalnızca "sesi kesildi" diye işaretler — sayfa yenilenmesi de
     * aynı olayı tetikliyor ve tek bir F5 oturumu kapatmamalı. Gerçekten
     * gidildiyse geri ses gelmez ve dakikalık temizlik kapatır.
     */
    Route::post($onek.'/ayril', [ArayuzOturumController::class, 'ayril'])
        ->name('credentials.arayuz.leave');
});

/*
 * Bunun altındaki her şey oturum ister. "Giriş yapmayan erişim bilgisi
 * göremez" kuralı tek bir yerde, burada duruyor: yeni bir uç eklendiğinde
 * bu grubun dışına düşmesi gerekir ve o zaman denetim betiği onu yakalar.
 */
Route::middleware('auth')->group(function (): void {
    Route::post('cikis', [LoginController::class, 'destroy'])->name('logout');

    Route::redirect('/', '/erisim');

    /*
     * Konsol: kasadaki parolayı EKRANA GETİRMEDEN uzaktaki makineye oturum
     * açar. SSH tarayıcı içi terminale, uzak masaüstü Guacamole'a gider.
     */
    Route::get('uzak-oturum/{token}', RemoteSessionPageController::class)->name('remote.session');

    Route::prefix('ajax/uzak-oturum')->name('ajax.remote.')->group(function (): void {
        Route::post('baglan', [RemoteSessionController::class, 'connect'])->name('connect');
        Route::post('canli-tut', [RemoteSessionController::class, 'keepAlive'])->name('keep-alive');
        Route::post('kapat', [RemoteSessionController::class, 'disconnect'])->name('disconnect');
    });

    /*
     * Ekleme ve düzenleme formunun AYRI SAYFASI YOKTUR: ikisi de liste
     * ekranının üstünde pencere olarak açılır (?pencere=ekle / ?pencere=duzenle).
     * Yazma uçları yerinde duruyor; korunan da zaten onlar.
     */
    Route::get('erisim', [CredentialController::class, 'index'])->name('credentials.index');
    Route::post('erisim', [CredentialController::class, 'store'])->name('credentials.store');
    /* Sürükle-bırak sırası; {credential}'dan ÖNCE: "sira" kayıt kimliği sanılmasın. */
    Route::put('erisim/sira', [CredentialController::class, 'sirala'])->name('credentials.sirala');
    Route::put('erisim/{credential}', [CredentialController::class, 'update'])->name('credentials.update');
    Route::delete('erisim/{credential}', [CredentialController::class, 'destroy'])->name('credentials.destroy');

    /*
     * Parolayı gösteren uç. GET değil POST: tarayıcı geçmişine, sunucu
     * erişim kaydına ve bağlantı ön yüklemesine takılmasın.
     */
    Route::post('erisim/{credential}/parola', CredentialPasswordController::class)
        ->name('ajax.credentials.password');

    /* Uzaktaki makinenin parolasını değiştirir; ayrı yetki ve gerekçe ister. */
    Route::post('erisim/{credential}/dondur', CredentialRotationController::class)
        ->name('credentials.rotate');

    /*
     * Süren turun aşaması — ekrandaki ilerleme çubuğu bunu yoklar. GET:
     * hiçbir şeyi değiştirmiyor, yalnızca aşama adı döndürüyor.
     */
    Route::get('erisim/{credential}/dondur/durum', CredentialRotationProgressController::class)
        ->name('credentials.rotate.progress');

    /*
     * Toplu döndürme. Kayıt kimliği yolda DEĞİL gövdededir; bu yüzden
     * 'erisim/{credential}' kalıbıyla çakışmasın diye ayrı ve sabit bir yol.
     */
    Route::post('erisim/toplu-dondur', BulkCredentialRotationController::class)
        ->name('credentials.rotate.bulk');

    /*
     * Toplu turun TEK göstergesi: durum (GET, yalnızca okur) ve kapatma.
     * Parti kimliği yolda; 'erisim/{credential}/…' kalıplarıyla çakışmasın
     * diye sabit 'toplu-dondur' öneki altında.
     */
    Route::get('erisim/toplu-dondur/{parti}/durum', [TopluTurController::class, 'durum'])
        ->whereUuid('parti')->name('credentials.rotate.bulk.progress');
    Route::delete('erisim/toplu-dondur/{parti}', [TopluTurController::class, 'kapat'])
        ->whereUuid('parti')->name('credentials.rotate.bulk.dismiss');

    /*
     * Toplu parola çıktısı (Excel). Aynı sebeple POST ve sabit yol.
     *
     * Parolayı panelin DIŞINA çıkaran tek uç budur; kuralları ve gerekçesi
     * ParolaCiktisi'nde yazılı.
     */
    Route::post('erisim/toplu-cikti', CredentialExportController::class)
        ->name('credentials.export');

    /*
     * Portal oturumu: parolayı kullanıcıya göstermeden hedef sitenin giriş
     * formuna gönderir. Yalnızca POST — adres çubuğuna yazılamaz.
     */
    Route::post('erisim/{credential}/oturum', PortalSessionController::class)
        ->name('credentials.session');

    /*
     * Cihaz arayüzleri (ESXi, VAMI): forma gönderilebilen bir girişleri YOK,
     * o yüzden arayüzün kendisi panelin kökeninden vekilleniyor ve oturum
     * orada açılıyor (bkz. ArayuzTuru).
     */
    Route::post('erisim/{credential}/cihaz-oturum', [ArayuzOturumController::class, 'ac'])
        ->name('credentials.arayuz');

    /* Yarıda kalmış turu kapatır: kurtarma hesabını siler, kasayı hizalar. */
    Route::post('erisim/{credential}/dondur/{rotation}/onar', RotationRepairController::class)
        ->name('credentials.rotate.repair');

    Route::prefix('panel')->group(function (): void {
        /* Sürükleyerek sıralama; kaynağın PUT {group} rotasından ÖNCE, "sira" bir grup adı sanılmasın. */
        Route::put('gruplar/sira', [GroupController::class, 'sirala'])->name('groups.sirala');

        Route::resource('gruplar', GroupController::class)
            ->parameters(['gruplar' => 'group'])
            ->names('groups')
            ->except('show');

        /*
         * ALT GRUPLAR grubun düzenleme sayfasından yönetilir; kendi listesi
         * yok. Bölme grubun İÇİNDEDİR, adres de öyle söyler.
         */
        Route::post('gruplar/{group}/alt-gruplar', [AltGrupController::class, 'store'])->name('groups.alt.store');
        Route::put('gruplar/{group}/alt-gruplar/sira', [AltGrupController::class, 'sirala'])->name('groups.alt.sirala');
        Route::put('gruplar/{group}/alt-gruplar/{altGrup}', [AltGrupController::class, 'update'])->name('groups.alt.update');
        Route::delete('gruplar/{group}/alt-gruplar/{altGrup}', [AltGrupController::class, 'destroy'])->name('groups.alt.destroy');

        /*
         * SERTİFİKA. Tur konteyner dışındaki sertifika ajanında koşar; panel
         * isteği bırakır ve sonucu okur (bkz. SertifikaController).
         */
        Route::get('ayarlar/sertifika', [SertifikaController::class, 'index'])->name('sertifika.index');
        Route::post('ayarlar/sertifika/tur', [SertifikaController::class, 'baslat'])->name('sertifika.baslat');
        Route::post('ayarlar/sertifika/onayla', [SertifikaController::class, 'tamamla'])->name('sertifika.tamamla');

        /*
         * VERİTABANI. Panel bağlantıyı sınar ve taşıma isteğini bırakır;
         * kopya yeniden başlatmada, panel kapalıyken yapılır
         * (bkz. VeritabaniController ve BaglantiDosyasi).
         */
        Route::get('ayarlar/veritabani', [VeritabaniController::class, 'index'])->name('veritabani.index');
        Route::post('ayarlar/veritabani/sina', [VeritabaniController::class, 'sina'])->name('veritabani.sina');
        Route::post('ayarlar/veritabani/tasi', [VeritabaniController::class, 'tasi'])->name('veritabani.tasi');

        /*
         * GÜNCELLEME. Yalnızca bakar: çalışan sürüm GitHub'daki son başarılı
         * derlemeyle karşılaştırılır (bkz. GuncellemeDenetimi).
         */
        Route::get('ayarlar/guncelleme', [GuncellemeController::class, 'index'])->name('guncelleme.index');
        Route::post('ayarlar/guncelleme', [GuncellemeController::class, 'denetle'])->name('guncelleme.denetle');

        Route::get('ayarlar', [SettingsController::class, 'index'])->name('settings.index');
        Route::put('ayarlar', [SettingsController::class, 'update'])->name('settings.update');

        /*
         * API jetonları — Ayarlar'ın bir sekmesi.
         *
         * Jeton, sahibinin yetkisini kalıcı bir anahtara dönüştürür; bu yüzden
         * yönetimi yalnızca yöneticidedir (bkz. ApiTokenPolicy).
         */
        Route::controller(ApiTokenController::class)->prefix('api-jetonlari')->name('api-tokens.')->group(function (): void {
            Route::get('/', 'index')->name('index');

            /* Belge {apiToken}'dan ÖNCE: yoksa "belge" bir uuid sanılırdı. */
            Route::get('belge', 'documentation')->name('docs');

            Route::post('/', 'store')->name('store');
            Route::get('{apiToken}', 'show')->name('show');
            Route::put('{apiToken}', 'update')->name('update');
            Route::put('{apiToken}/sir', 'rotate')->name('rotate');
            Route::put('{apiToken}/iptal', 'revoke')->name('revoke');
            Route::delete('{apiToken}', 'destroy')->name('destroy');
        });

        Route::resource('roller', RoleController::class)
            ->parameters(['roller' => 'role'])
            ->names('roles')
            ->except('show');

        Route::resource('kullanicilar', UserController::class)
            ->parameters(['kullanicilar' => 'user'])
            ->names('users')
            ->except('show');

        Route::get('kullanicilar/{user}/izinler', [UserPermissionController::class, 'edit'])
            ->name('users.permissions.edit');
        Route::put('kullanicilar/{user}/izinler', [UserPermissionController::class, 'update'])
            ->name('users.permissions.update');

        Route::get('kayitlar', [ActivityLogController::class, 'index'])->name('logs.index');
        Route::post('kayitlar/dogrula', [ActivityLogController::class, 'verify'])->name('logs.verify');

        Route::get('hatalar', [ErrorLogController::class, 'index'])->name('errors.index');
        Route::post('hatalar/{error}/cozuldu', [ErrorLogController::class, 'resolve'])->name('errors.resolve');
    });
});

/*
 * Hesabım: herkes yalnızca KENDİ hesabını düzenler.
 *
 * Rol, durum ve grup izinleri burada değişmez — onlar yöneticinin ekranında
 * durur. "Kendi profilini düzenle" yetkisi, kendini yönetici yapma yetkisi
 * değildir.
 */
Route::middleware('auth')->prefix('hesap')->name('hesap.')->group(function (): void {
    Route::get('profil', [ProfilController::class, 'index'])->name('profil');
    Route::put('profil', [ProfilController::class, 'update'])->name('profil.kaydet');
    Route::put('parola', [ProfilController::class, 'parola'])->name('parola');
});
