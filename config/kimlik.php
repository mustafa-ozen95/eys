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
     * Yöneticinin KARARI. Ayar ekranından değiştirilir (ayarlar/kimlik).
     *
     * Tek başına yetmez: istemci bilgileri yoksa akış zaten çalışamaz. İkisini
     * `MerkezIstemcisi::acikMi()` birleştirir — ayar, eksik yapılandırmayı
     * geçersiz kılamasın diye kural tek yerde durur.
     */
    'acik' => env('KIMLIK_ACIK', false),

    /*
     * YETENEK: istemci bilgileri elimizde mi?
     *
     * Ayardan bindirilmez ve bindirilmemeli; yoksa yapılandırılmamış bir
     * kurulumda düğme açılabilir ve kullanıcı çalışmayan bir yola gönderilirdi.
     */
    'yapilandirildi' => filled(env('KIMLIK_ISTEMCI')) && filled(env('KIMLIK_ISTEMCI_SIR')),

    'adres' => rtrim((string) env('KIMLIK_ADRES', 'https://kimlik.ornek.test'), '/'),
    'istemci' => env('KIMLIK_ISTEMCI'),
    'sir' => env('KIMLIK_ISTEMCI_SIR'),

    /*
     * Merkezin "bu kimlik değişti" bildirimini imzaladığı sır.
     *
     * OAuth istemci sırrından AYRIDIR: merkez onu bcrypt özeti olarak tutuyor
     * ve imza üretemiyor. Bu sır yalnızca bildirimi imzalar, hiçbir yetki
     * taşımaz — gelen bildirimin içeriğine zaten güvenilmiyor, panel doğruyu
     * merkezden kendi soruyor (bkz. KimlikTazeleController).
     *
     * Boş bırakılırsa bildirim kabul edilmez ve panel değişikliği beş
     * dakikalık eşitleme turunda öğrenir.
     */
    'bildirim_sirri' => env('KIMLIK_BILDIRIM_SIR'),

    /* Merkezde kayıtlı olan adresle BİREBİR aynı olmalı. */
    'donus' => env('KIMLIK_DONUS', 'https://eys.ornek.test/giris/merkez/geri'),

    'ad' => env('KIMLIK_AD', 'Kimlik Yönetim Merkezi'),

    /*
     * TANINMAYAN KİŞİ İÇİN HESAP AÇILSIN MI — BURADA DEĞİL, MERKEZDE.
     *
     * Karar iki ayrı yerde (panelde ve merkezde) tutulursa biri değişince
     * öbürü sessizce eski kalır. Bu yüzden tek yerdedir: merkezdeki
     * İstemciler ekranında uygulama başına verilir, kimlik yanıtında
     * `hesap_acilabilir` olarak gelir (bkz. HesapAcici::acilabilirMi).
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
    'yonetici_devri' => env('KIMLIK_YONETICI_DEVRI', false),

    /*
     * Merkezdeki durum kaç dakikada bir yeniden sorulsun?
     *
     * Merkezde rolü değişen ya da hesabı askıya alınan kişi burada erişmeye
     * devam etmemeli; girişte bakmak yetmiyor, çünkü değişiklik kişi hiç
     * giriş yapmadan da olur. Her istekte sormak ise her sayfa açılışını
     * merkeze bir isteğe bağlardı.
     *
     * Bu sayı, "erişimi kalkan biri en fazla ne kadar daha içeride kalabilir"
     * sorusunun yanıtıdır (bkz. KimlikDurumunuDogrula).
     */
    'dogrulama_dakika' => (int) env('KIMLIK_DOGRULAMA_DAKIKA', 5),

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
    'kok' => env('KIMLIK_KOK'),
];
