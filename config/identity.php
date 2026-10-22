<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Kimlik Yönetim Merkezi ile giriş
|--------------------------------------------------------------------------
|
| Merkez yalnızca KİMLİĞİ kanıtlar; bu panelin rolleri, yetkileri ve kendi
| giriş ekranı yerinde kalır. Sır boşsa özellik tümden kapalıdır — düğme bile
| çizilmez, yani yapılandırılmamış bir kurulum yarım çalışmaz.
|
*/

return [
    /*
     * Yöneticinin KARARI. Ayar ekranından değiştirilir (settings/identity).
     *
     * Tek başına yetmez: istemci bilgileri yoksa akış zaten çalışamaz. İkisini
     * `CenterClient::acikMi()` birleştirir — ayar, eksik yapılandırmayı
     * geçersiz kılamasın diye kural tek yerde durur.
     */
    'acik' => env('IDENTITY_ENABLED', false),

    /*
     * YETENEK: istemci bilgileri elimizde mi?
     *
     * Ayardan bindirilmez ve bindirilmemeli; yoksa yapılandırılmamış bir
     * kurulumda düğme açılabilir ve kullanıcı çalışmayan bir yola gönderilirdi.
     */
    'configured' => filled(env('IDENTITY_CLIENT_ID')) && filled(env('IDENTITY_CLIENT_SECRET')),

    'url' => rtrim((string) env('IDENTITY_URL', 'https://kimlik.ornek.test'), '/'),
    'client_id' => env('IDENTITY_CLIENT_ID'),
    'client_secret' => env('IDENTITY_CLIENT_SECRET'),

    /*
     * Merkezin "bu kimlik değişti" bildirimini imzaladığı sır.
     *
     * OAuth istemci sırrından AYRIDIR: merkez onu bcrypt özeti olarak tutuyor
     * ve imza üretemiyor. Bu sır yalnızca bildirimi imzalar, hiçbir yetki
     * taşımaz — gelen bildirimin içeriğine zaten güvenilmiyor, panel doğruyu
     * merkezden kendi soruyor (bkz. IdentityRefreshController).
     *
     * Boş bırakılırsa bildirim kabul edilmez ve panel değişikliği beş
     * dakikalık eşitleme turunda öğrenir.
     */
    'notify_secret' => env('IDENTITY_NOTIFY_SECRET'),

    /* Merkezde kayıtlı olan adresle BİREBİR aynı olmalı. */
    'redirect_uri' => env('IDENTITY_REDIRECT_URI', 'https://eys.ornek.test/login/center/callback'),

    'name' => env('IDENTITY_NAME', 'Kimlik Yönetim Merkezi'),

    /*
     * TANINMAYAN KİŞİ İÇİN HESAP AÇILSIN MI — BURADA DEĞİL, MERKEZDE.
     *
     * Karar iki ayrı yerde (panelde ve merkezde) tutulursa biri değişince
     * öbürü sessizce eski kalır. Bu yüzden tek yerdedir: merkezdeki
     * İstemciler ekranında uygulama başına verilir, kimlik yanıtında
     * `can_create_account` olarak gelir (bkz. AccountOpener::acilabilirMi).
     *
     * Panelin kendi koşulları YERİNDE DURUYOR: adres doğrulanmış olmalı ve
     * kişiye merkezde bir rol atanmış olmalı. Merkez kapıyı açar, içerideki
     * ayrıntı panelin işidir.
     */

    /*
     * Merkezdeki YÖNETİCİ rolü buraya da geçsin mi?
     *
     * Varsayılan HAYIR ve bu bilinçli: merkezin yöneticisi kimlikleri
     * yönetir, bu kasanın yöneticisi ise bütün sistemlerin parolalarını
     * görür. Merkezde rol dağıtabilen biri, kendini burada da yönetici
     * yapabilirdi — hem de buradaki hiçbir yöneticinin haberi olmadan.
     *
     * Açıkken merkezdeki yönetici burada da yönetici doğar. Kapalıyken
     * varsayılan rolle doğar; yöneticiliği yerel bir yönetici verir.
     */
    'yonetici_devri' => env('IDENTITY_ADMIN_TAKEOVER', false),

    /*
     * Merkezdeki durum kaç dakikada bir yeniden sorulsun?
     *
     * Merkezde rolü değişen ya da hesabı askıya alınan kişi burada erişmeye
     * devam etmemeli; girişte bakmak yetmiyor, çünkü değişiklik kişi hiç
     * giriş yapmadan da olur. Her istekte sormak ise her sayfa açılışını
     * merkeze bir isteğe bağlardı.
     *
     * Bu sayı, "erişimi kalkan biri en fazla ne kadar daha içeride kalabilir"
     * sorusunun yanıtıdır (bkz. VerifyIdentityStatus).
     */
    'verify_minutes' => (int) env('IDENTITY_VERIFY_MINUTES', 5),

    /*
     * Merkezin sertifikasını doğrulamak için kullanılacak kök.
     *
     * NEDEN VAR: merkez şirket içi bir adreste durabilir ve orada genel bir
     * sertifika otoritesi yoktur. Böyle bir kurulumda doğrulamayı KAPATMAK
     * (verify => false) akla gelen ilk çözümdür ve yanlıştır: jeton takası
     * sırasında araya giren biri istemci sırrını okur. Bunun yerine merkezin
     * kendi sertifikası burada gösterilir; doğrulama açık kalır, yalnızca
     * kime güvenileceği daraltılır — bu, genel bir otoriteye güvenmekten
     * daha dardır, daha geniş değil.
     *
     * Boşsa normal doğrulama yapılır. Kapatma seçeneği YOKTUR.
     */
    'ca_cert' => env('IDENTITY_CA_CERT'),
];
