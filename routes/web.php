<?php

declare(strict_types=1);

use App\Http\Controllers\Account\ProfileController;
use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\Ajax\CredentialPasswordController;
use App\Http\Controllers\Ajax\InterfaceMonitorController;
use App\Http\Controllers\Ajax\InterfaceProxyWatcher;
use App\Http\Controllers\Ajax\RemoteProxyGuardController;
use App\Http\Controllers\Ajax\RemoteSessionController;
use App\Http\Controllers\ApiTokenController;
use App\Http\Controllers\Auth\CenterLoginController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\BulkCredentialRotationController;
use App\Http\Controllers\BulkRoundController;
use App\Http\Controllers\CertificateController;
use App\Http\Controllers\CredentialController;
use App\Http\Controllers\CredentialExportController;
use App\Http\Controllers\CredentialRotationController;
use App\Http\Controllers\CredentialRotationProgressController;
use App\Http\Controllers\DatabaseController;
use App\Http\Controllers\ErrorLogController;
use App\Http\Controllers\GroupController;
use App\Http\Controllers\InterfaceSessionController;
use App\Http\Controllers\PortalSessionController;
use App\Http\Controllers\RemoteSessionPageController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\RotationRepairController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SubGroupController;
use App\Http\Controllers\UpdateController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\UserPermissionController;
use Illuminate\Support\Facades\Route;

/*
 * Giriş ekranı. Oturum açmamış kullanıcının görebildiği TEK ekran budur;
 * kayıt olma ucu yoktur — hesabı yönetici açar.
 */
Route::middleware('guest')->group(function (): void {
    Route::get('login', [LoginController::class, 'create'])->name('login');
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
    $girisRotasi = Route::post('login', [LoginController::class, 'store']);

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
     * saklanan `state` tutar (bkz. CenterLoginController).
     */
    Route::get('login/center', [CenterLoginController::class, 'start'])->name('identity.login');
    Route::get('login/center/callback', [CenterLoginController::class, 'callback'])->name('identity.callback');
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
    ->get('ajax/remote-session/verify', RemoteProxyGuardController::class)
    ->name('ajax.remote.guard');

/*
 * ESXi vekilinin bekçisi. Aynı gerekçeyle "auth" dışındadır; ayrıca nginx'e
 * HEDEFİ de bu uç söyler (X-Eys-Target), çünkü hedefin istekten değil kendi
 * kaydımızdan gelmesi şart (bkz. EsxiProxyGuardController).
 */
Route::middleware('device-interface')
    ->get('ajax/device-session/verify', InterfaceProxyWatcher::class)
    ->name('ajax.interface.guard');

/*
 * Vekilden geçen SOAP çağrılarının aynası: "ne yapıldı" sorusunun cevabı
 * gövdedeki yöntem adında. nginx'in İÇ isteğidir (mirror), dışarıdan
 * çağrılamaz; asıl isteği de beklemez.
 */
Route::middleware('device-interface')
    ->post('ajax/device-session/watch', InterfaceMonitorController::class)
    ->name('ajax.interface.trace');

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
Route::middleware('device-interface')->group(function (): void {
    $prefix = ltrim((string) config('remote.interface.prefix'), '/');

    Route::get($prefix.'/handoff/{devir}', [InterfaceSessionController::class, 'handOff'])
        ->whereUuid('devir')
        ->name('credentials.interface.handoff');

    /*
     * Ayrı kökende arayüz KÖKTE duruyor; nginx kökü buraya yönlendiriyor ve
     * kimliği çerez taşıyor. Panelin kendi kökeninde ise adres önekli.
     */
    Route::get($prefix.'/page', [InterfaceSessionController::class, 'page'])
        ->name('credentials.interface.root');

    Route::get($prefix.'/identity', [InterfaceSessionController::class, 'identity'])
        ->name('credentials.interface.identity.root');

    Route::get($prefix.'/boot.js', [InterfaceSessionController::class, 'boot'])
        ->name('credentials.interface.boot.root');

    Route::get($prefix.'/{token}/', [InterfaceSessionController::class, 'page'])
        ->whereUuid('token')
        ->name('credentials.interface.page');

    Route::get($prefix.'/{token}/identity', [InterfaceSessionController::class, 'identity'])
        ->whereUuid('token')
        ->name('credentials.interface.identity');

    Route::get($prefix.'/{token}/boot.js', [InterfaceSessionController::class, 'boot'])
        ->whereUuid('token')
        ->name('credentials.interface.boot');

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
    Route::post($prefix.'/leave', [InterfaceSessionController::class, 'leave'])
        ->name('credentials.interface.leave');
});

/*
 * Bunun altındaki her şey oturum ister. "Giriş yapmayan erişim bilgisi
 * göremez" kuralı tek bir yerde, burada duruyor: yeni bir uç eklendiğinde
 * bu grubun dışına düşmesi gerekir ve o zaman denetim betiği onu yakalar.
 */
Route::middleware('auth')->group(function (): void {
    Route::post('logout', [LoginController::class, 'destroy'])->name('logout');

    Route::redirect('/', '/access');

    /*
     * Konsol: kasadaki parolayı EKRANA GETİRMEDEN uzaktaki makineye oturum
     * açar. SSH tarayıcı içi terminale, uzak masaüstü Guacamole'a gider.
     */
    Route::get('remote-session/{token}', RemoteSessionPageController::class)->name('remote.session');

    Route::prefix('ajax/remote-session')->name('ajax.remote.')->group(function (): void {
        Route::post('connect', [RemoteSessionController::class, 'connect'])->name('connect');
        Route::post('keep-alive', [RemoteSessionController::class, 'keepAlive'])->name('keep-alive');
        Route::post('disconnect', [RemoteSessionController::class, 'disconnect'])->name('disconnect');
    });

    /*
     * Ekleme ve düzenleme formunun AYRI SAYFASI YOKTUR: ikisi de liste
     * ekranının üstünde pencere olarak açılır (?pencere=ekle / ?pencere=duzenle).
     * Yazma uçları yerinde duruyor; korunan da zaten onlar.
     */
    Route::get('access', [CredentialController::class, 'index'])->name('credentials.index');
    Route::post('access', [CredentialController::class, 'store'])->name('credentials.store');
    /* Sürükle-bırak sırası; {credential}'dan ÖNCE: "sira" kayıt kimliği sanılmasın. */
    Route::put('access/order', [CredentialController::class, 'reorder'])->name('credentials.reorder');
    Route::put('access/{credential}', [CredentialController::class, 'update'])->name('credentials.update');
    Route::delete('access/{credential}', [CredentialController::class, 'destroy'])->name('credentials.destroy');

    /*
     * Parolayı gösteren uç. GET değil POST: tarayıcı geçmişine, sunucu
     * erişim kaydına ve bağlantı ön yüklemesine takılmasın.
     */
    Route::post('access/{credential}/password', CredentialPasswordController::class)
        ->name('ajax.credentials.password');

    /* Uzaktaki makinenin parolasını değiştirir; ayrı yetki ve gerekçe ister. */
    Route::post('access/{credential}/rotate', CredentialRotationController::class)
        ->name('credentials.rotate');

    /*
     * Süren turun aşaması — ekrandaki ilerleme çubuğu bunu yoklar. GET:
     * hiçbir şeyi değiştirmiyor, yalnızca aşama adı döndürüyor.
     */
    Route::get('access/{credential}/rotate/status', CredentialRotationProgressController::class)
        ->name('credentials.rotate.progress');

    /*
     * Toplu döndürme. Kayıt kimliği yolda DEĞİL gövdededir; bu yüzden
     * 'access/{credential}' kalıbıyla çakışmasın diye ayrı ve sabit bir yol.
     */
    Route::post('access/bulk-rotate', BulkCredentialRotationController::class)
        ->name('credentials.rotate.bulk');

    /*
     * Toplu turun TEK göstergesi: durum (GET, yalnızca okur) ve kapatma.
     * Parti kimliği yolda; 'access/{credential}/…' kalıplarıyla çakışmasın
     * diye sabit 'bulk-rotate-form' öneki altında.
     */
    Route::get('access/bulk-rotate/{parti}/status', [BulkRoundController::class, 'status'])
        ->whereUuid('parti')->name('credentials.rotate.bulk.progress');
    Route::delete('access/bulk-rotate/{parti}', [BulkRoundController::class, 'dismiss'])
        ->whereUuid('parti')->name('credentials.rotate.bulk.dismiss');

    /*
     * Toplu parola çıktısı (Excel). Aynı sebeple POST ve sabit yol.
     *
     * Parolayı panelin DIŞINA çıkaran tek uç budur; kuralları ve gerekçesi
     * PasswordOutput'nde yazılı.
     */
    Route::post('access/bulk-export', CredentialExportController::class)
        ->name('credentials.export');

    /*
     * Portal oturumu: parolayı kullanıcıya göstermeden hedef sitenin giriş
     * formuna gönderir. Yalnızca POST — adres çubuğuna yazılamaz.
     */
    Route::post('access/{credential}/session', PortalSessionController::class)
        ->name('credentials.session');

    /*
     * Cihaz arayüzleri (ESXi, VAMI): forma gönderilebilen bir girişleri YOK,
     * o yüzden arayüzün kendisi panelin kökeninden vekilleniyor ve oturum
     * orada açılıyor (bkz. InterfaceType).
     */
    Route::post('access/{credential}/device-session', [InterfaceSessionController::class, 'open'])
        ->name('credentials.interface');

    /* Yarıda kalmış turu kapatır: kurtarma hesabını siler, kasayı hizalar. */
    Route::post('access/{credential}/rotate/{rotation}/repair', RotationRepairController::class)
        ->name('credentials.rotate.repair');

    Route::prefix('panel')->group(function (): void {
        /* Sürükleyerek sıralama; kaynağın PUT {group} rotasından ÖNCE, "sira" bir grup adı sanılmasın. */
        Route::put('groups/order', [GroupController::class, 'reorder'])->name('groups.reorder');

        Route::resource('groups', GroupController::class)
            ->parameters(['groups' => 'group'])
            ->names('groups')
            ->except('show');

        /*
         * ALT GRUPLAR grubun düzenleme sayfasından yönetilir; kendi listesi
         * yok. Bölme grubun İÇİNDEDİR, adres de öyle söyler.
         */
        Route::post('groups/{group}/sub-groups', [SubGroupController::class, 'store'])->name('groups.sub.store');
        Route::put('groups/{group}/sub-groups/order', [SubGroupController::class, 'reorder'])->name('groups.sub.reorder');
        Route::put('groups/{group}/sub-groups/{altGrup}', [SubGroupController::class, 'update'])->name('groups.sub.update');
        Route::delete('groups/{group}/sub-groups/{altGrup}', [SubGroupController::class, 'destroy'])->name('groups.sub.destroy');

        /*
         * SERTİFİKA. Tur konteyner dışındaki sertifika ajanında koşar; panel
         * isteği bırakır ve sonucu okur (bkz. CertificateController).
         */
        Route::get('settings/certificate', [CertificateController::class, 'index'])->name('certificate.index');
        Route::post('settings/certificate/run', [CertificateController::class, 'start'])->name('certificate.start');
        Route::post('settings/certificate/confirm', [CertificateController::class, 'complete'])->name('certificate.complete');

        /*
         * VERİTABANI. Panel bağlantıyı sınar ve taşıma isteğini bırakır;
         * kopya yeniden başlatmada, panel kapalıyken yapılır
         * (bkz. DatabaseController ve ConnectionFile).
         */
        Route::get('settings/database', [DatabaseController::class, 'index'])->name('database.index');
        Route::post('settings/database/test', [DatabaseController::class, 'test'])->name('database.test');
        Route::post('settings/database/migrate', [DatabaseController::class, 'migrate'])->name('database.migrate');

        /*
         * GÜNCELLEME. Çalışan sürüm GitHub'daki son başarılı derlemeyle
         * karşılaştırılır (bkz. UpdateCheck); yeni sürüm varsa apply platformun
         * deploy hook'unu tetikler (bkz. UpdateApply).
         */
        Route::get('settings/update', [UpdateController::class, 'index'])->name('update.index');
        Route::post('settings/update', [UpdateController::class, 'check'])->name('update.check');
        Route::post('settings/update/apply', [UpdateController::class, 'apply'])->name('update.apply');

        Route::get('settings', [SettingsController::class, 'index'])->name('settings.index');
        Route::put('settings', [SettingsController::class, 'update'])->name('settings.update');

        /*
         * API jetonları — Ayarlar'ın bir sekmesi.
         *
         * Jeton, sahibinin yetkisini kalıcı bir anahtara dönüştürür; bu yüzden
         * yönetimi yalnızca yöneticidedir (bkz. ApiTokenPolicy).
         */
        Route::controller(ApiTokenController::class)->prefix('api-tokens')->name('api-tokens.')->group(function (): void {
            Route::get('/', 'index')->name('index');

            /* Belge {apiToken}'dan ÖNCE: yoksa "belge" bir uuid sanılırdı. */
            Route::get('docs', 'documentation')->name('docs');

            Route::post('/', 'store')->name('store');
            Route::get('{apiToken}', 'show')->name('show');
            Route::put('{apiToken}', 'update')->name('update');
            Route::put('{apiToken}/secret', 'rotate')->name('rotate');
            Route::put('{apiToken}/revoke', 'revoke')->name('revoke');
            Route::delete('{apiToken}', 'destroy')->name('destroy');
        });

        Route::resource('roles', RoleController::class)
            ->parameters(['roles' => 'role'])
            ->names('roles')
            ->except('show');

        Route::resource('users', UserController::class)
            ->parameters(['users' => 'user'])
            ->names('users')
            ->except('show');

        Route::get('users/{user}/permissions', [UserPermissionController::class, 'edit'])
            ->name('users.permissions.edit');
        Route::put('users/{user}/permissions', [UserPermissionController::class, 'update'])
            ->name('users.permissions.update');

        Route::get('logs', [ActivityLogController::class, 'index'])->name('logs.index');
        Route::post('logs/verify', [ActivityLogController::class, 'verify'])->name('logs.verify');

        Route::get('errors', [ErrorLogController::class, 'index'])->name('errors.index');
        Route::post('errors/{error}/resolved', [ErrorLogController::class, 'resolve'])->name('errors.resolve');
    });
});

/*
 * Hesabım: herkes yalnızca KENDİ hesabını düzenler.
 *
 * Rol, durum ve grup izinleri burada değişmez — onlar yöneticinin ekranında
 * durur. "Kendi profilini düzenle" yetkisi, kendini yönetici yapma yetkisi
 * değildir.
 */
Route::middleware('auth')->prefix('account')->name('account.')->group(function (): void {
    Route::get('profile', [ProfileController::class, 'index'])->name('profile');
    Route::put('profile', [ProfileController::class, 'update'])->name('profile.save');
    Route::put('password', [ProfileController::class, 'password'])->name('password');
});
