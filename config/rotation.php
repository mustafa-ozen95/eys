<?php

declare(strict_types=1);

use App\Services\Rotation\Drivers\AtenCn8000PasswordDriver;
use App\Services\Rotation\Drivers\EsxiPasswordDriver;
use App\Services\Rotation\Drivers\IloPasswordDriver;
use App\Services\Rotation\Drivers\JunosPasswordDriver;
use App\Services\Rotation\Drivers\LantronixSpiderPasswordDriver;
use App\Services\Rotation\Drivers\MailcowAdminPasswordDriver;
use App\Services\Rotation\Drivers\MailcowPasswordDriver;
use App\Services\Rotation\Drivers\PleskPasswordDriver;
use App\Services\Rotation\Drivers\PleskWindowsPasswordDriver;
use App\Services\Rotation\Drivers\RedfishPasswordDriver;
use App\Services\Rotation\Drivers\RibclPasswordDriver;
use App\Services\Rotation\Drivers\RspamdPasswordDriver;
use App\Services\Rotation\Drivers\SshPasswordDriver;
use App\Services\Rotation\Drivers\VCenterPasswordDriver;
use App\Services\Rotation\Drivers\VCenterSsoPasswordDriver;
use App\Services\Rotation\Drivers\WindowsPasswordDriver;
use App\Services\Rotation\Drivers\WindowsWinRmPasswordDriver;
use App\Services\Rotation\Drivers\WindowsWinRmRecoveryPasswordDriver;

/**
 * Parola döndürme ayarları.
 *
 * Sürücü listesi burada durur; yeni bir platform desteklemek bir sınıf yazıp
 * buraya bir satır eklemektir. Anahtar, OperatingSystem::rotationDriver()
 * değeriyle ya da kaydın rotation_driver alanıyla eşleşir.
 */
return [

    /*
     * Genel kapatma düğmesi. Kapalıyken hiçbir kaydın parolası güncellenemez; tek tek
     * kayıt ayarlarına BAKILMAZ. Panelden değiştirilebilir (Ayarlar).
     */
    'enabled' => env('ROTATION_ENABLED', true),

    'drivers' => [
        SshPasswordDriver::KEY => SshPasswordDriver::class,

        /* Windows: OpenSSH açıksa 'windows', kapalıysa 'winrm'. */
        WindowsPasswordDriver::KEY => WindowsPasswordDriver::class,
        WindowsWinRmPasswordDriver::KEY => WindowsWinRmPasswordDriver::class,

        /*
         * Geçici hesaplı sıra yalnızca LocalAccountTokenFilterPolicy=1 olan
         * makinelerde işler; hangi makinede işlediğini kaydın sürücü alanı
         * söyler (bkz. WindowsWinRmRecoveryPasswordDriver).
         */
        WindowsWinRmRecoveryPasswordDriver::KEY => WindowsWinRmRecoveryPasswordDriver::class,
        /*
         * Plesk WINDOWS ÜZERİNDE de koşuyor ve orada SSH yok: bağlantı WinRM
         * ile kuruluyor, parolayı Plesk'in kendi aracı değiştiriyor
         * (ölçüldü: Windows Server 2022, Plesk 18.0.81.0).
         */
        PleskWindowsPasswordDriver::KEY => PleskWindowsPasswordDriver::class,

        /* Alan yöneticisi: API anahtarıyla. */
        MailcowPasswordDriver::KEY => MailcowPasswordDriver::class,

        /* Süper yönetici: API'de ucu yok, makinenin üzerinden. */
        MailcowAdminPasswordDriver::KEY => MailcowAdminPasswordDriver::class,
        RspamdPasswordDriver::KEY => RspamdPasswordDriver::class,

        /*
         * KVM over IP. SSH/Telnet cihaz yönetimi için DEĞİL (seri konsol
         * geçişi); parola yalnızca web arayüzünün /Inquery ucundan
         * değişir. Her model ayrı sürücüdür — kodlar ve alan adları
         * modele göre değişiyor.
         */
        AtenCn8000PasswordDriver::KEY => AtenCn8000PasswordDriver::class,

        /*
         * Lantronix SLS: ATEN'in aksine BELGELENMİŞ bir komut satırı var,
         * parola oradan değişiyor. Doğrulama web girişinden yapılıyor —
         * döndürülen hesabın CLI yetkisi olmayabilir.
         */
        LantronixSpiderPasswordDriver::KEY => LantronixSpiderPasswordDriver::class,
        /* Panel hesabı: değiştiren SSH kimliği, değişen panel parolası. */
        PleskPasswordDriver::KEY => PleskPasswordDriver::class,

        /*
         * vCenter'da İKİ ayrı kimlik dünyası var ve tek sürücü ikisini de
         * yönetemez: 'vcenter' appliance'ın yerel hesaplarını (root),
         * 'vcenter_sso' ise vmdir'deki SSO kullanıcılarını
         * (administrator@vsphere.local) döndürür.
         */
        VCenterSsoPasswordDriver::KEY => VCenterSsoPasswordDriver::class,

        EsxiPasswordDriver::KEY => EsxiPasswordDriver::class,
        VCenterPasswordDriver::KEY => VCenterPasswordDriver::class,
        /*
         * iLO için VARSAYILAN budur: nesli kendisi bulur (iLO 3-6).
         * Aşağıdaki ikisi elle seçmek isteyenler için duruyor.
         */
        IloPasswordDriver::KEY => IloPasswordDriver::class,

        RedfishPasswordDriver::KEY => RedfishPasswordDriver::class,

        /*
         * iLO 3 ve iLO 4'ün eski firmware'lerinde Redfish YOKTUR; onlarda
         * RIBCL kullanılır. Kayıttaki sürücü alanı hangisinin geçerli
         * olduğunu söyler.
         */
        RibclPasswordDriver::KEY => RibclPasswordDriver::class,

        /*
         * Junos switch, NETCONF üzerinden. Gömülü işletim sistemi kayıtları
         * bu sürücüyü SEÇMEZ (rotationDriver() null); yalnızca kaydın kendi
         * sürücü alanı 'junos' ise ve adres aşağıdaki izin listesindeyse
         * çalışır.
         */
        JunosPasswordDriver::KEY => JunosPasswordDriver::class,
    ],

    /*
     * Üretilen parolanın uzunluğu. Kısaltmak isterseniz önce hedef sistemin
     * sınırına bakın: bazı eski cihazlar sessizce kırpıyor ve kasadaki parola
     * cihazdakinden farklı kalıyor.
     */
    'password_length' => (int) env('ROTATION_PASSWORD_LENGTH', 24),

    /*
     * Karışabilecek karakterler dışarıda: parola bazen elle yazılıyor.
     * Kırpılma riskine karşı özel karakter kümesi de dar tutulur.
     */
    'password_alphabet' => 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789.-_+=',

    /*
     * SSH taşımasının zaman aşımı.
     *
     * 15 saniye yetmiyor (ölçüldü): kurulumla meşgul bir makinede
     * `chpasswd` bunu aşabiliyor; sürücü "değiştiremedim" der ama parola
     * makinede değişmiş olur ve tur "doğrulanamadı" kapanıp onarım ister.
     * Ayar sürücüye bu anahtardan ulaşır.
     */
    'ssh_timeout' => (int) env('ROTATION_SSH_TIMEOUT', 30),

    /*
     * Kayıt başına döndürme kilidinin ömrü (saniye).
     *
     * İşin zaman aşımından (120 sn) uzun tutulur: işi öldüren bir zaman
     * aşımı temizlik bloğunu çalıştırmayabilir ve kilit ancak süresi dolunca
     * düşer. Kısa tutmak, ölü bir turun kilidini erken bırakıp ikinci turun
     * araya girmesine yol açardı.
     */
    'lock_seconds' => (int) env('ROTATION_LOCK_SECONDS', 600),

    /*
     * Plesk paneli.
     *
     * Panel kaydının portu 8443'tür ama parola SSH üzerinden değiştirilir;
     * bu yüzden SSH portu ayrıca yazılır.
     */
    'plesk' => [
        'ssh_port' => (int) env('ROTATION_PLESK_SSH_PORT', 22),
    ],

    /*
     * vCenter appliance'ın KAPILARI.
     *
     * Aynı makinede iki ayrı servis var ve karıştırmak sessiz hataya yol
     * açıyor. Ölçülen davranış:
     *
     *   443  : REST API (/api/session -> 201) ve vSphere Client (/ui/ -> 200)
     *   5480 : Appliance yönetim arayüzü. /api/session YOK (404),
     *          /ui/ de YOK (404); uygulama KÖKTEN açılır (200).
     *
     * Bu yüzden hem sürücü hem panel bağlantısı buradan okur.
     */
    'vcenter' => [
        'api_port' => (int) env('ROTATION_VCENTER_API_PORT', 443),
        'vami_port' => (int) env('ROTATION_VCENTER_VAMI_PORT', 5480),

        /*
         * SSO parola politikası. Ölçüldü (GetLocalPasswordPolicy):
         * 8-20 karakter, her sınıftan en az bir karakter. Panel varsayılanı
         * daha uzunsa parola bu sınıra kısaltılır.
         */
        'sso_max_password' => (int) env('ROTATION_VCENTER_SSO_MAX_PASSWORD', 20),

        /* Kurtarma hesabının yetkiyi aldığı yerel grup. */
        'sso_group' => env('ROTATION_VCENTER_SSO_GROUP', 'Administrators'),
    ],

    /*
     * WinRM taşıması.
     *
     * Şifreleme zorunlu olduğunda (AllowUnencrypted=false) mesajı mühürlemek
     * gerekiyor; işi olgun bir istemciye yaptırıyoruz. MD4 OpenSSL 3'ün
     * varsayılan sağlayıcısında yok, NTLM ise onu istiyor: sistem ayarına
     * dokunmadan yalnızca bu sürece özel bir yapılandırma veriliyor.
     */
    'winrm' => [
        'python' => env('ROTATION_PYTHON', '/usr/bin/python3'),
        'runner' => env('ROTATION_WINRM_RUNNER', resource_path('bin/winrm-runner.py')),
        'openssl_conf' => env('ROTATION_OPENSSL_CONF', resource_path('bin/openssl-legacy.cnf')),
        'port' => (int) env('ROTATION_WINRM_PORT', 5985),
        'timeout' => (int) env('ROTATION_WINRM_TIMEOUT', 60),

        /*
         * Kurtarma hesabı yalnızca LocalAccountTokenFilterPolicy=1 olan
         * makinelerde işe yarar; aksi hâlde hesap açılır ama uzaktan
         * giremez ve tur iptal olur (bkz. WindowsWinRmPasswordDriver).
         */
        'recovery' => filter_var(env('ROTATION_WINRM_RECOVERY', false), FILTER_VALIDATE_BOOL),
    ],

    /*
     * mailcow-dockerized kurulum dizini. Rspamd sürücüsü komutlarını burada
     * çalıştırır; yanlışsa `docker compose` projeyi bulamaz.
     */
    'mailcow_path' => env('ROTATION_MAILCOW_PATH', '/opt/mailcow-dockerized'),

    /*
     * Junos (NETCONF) sürücüsü.
     */
    'junos' => [
        /*
         * NETCONF portu. Switch kaydındaki port SSH/web portudur (22) ve
         * NETCONF oraya bakmaz; Junos'ta NETCONF-over-SSH 830'dadır.
         */
        'port' => (int) env('ROTATION_JUNOS_PORT', 830),

        /*
         * Saniye. Commit, cihazın en yavaş adımıdır (EX4200'de ve ELS'te
         * saniyeler sürer); kısa tutmak yarım kalmış commit üretir.
         */
        'timeout' => (int) env('ROTATION_JUNOS_TIMEOUT', 60),

        /* Kurtarma hesabının sabit adı ve yetkisi. */
        'kurtarma_adi' => env('ROTATION_JUNOS_KURTARMA_ADI', 'eys-kurtarma'),
        'kurtarma_sinifi' => 'super-user',

        /*
         * Geri alınabilir commit'in süresi (dakika). Onay verilmezse cihaz bu
         * süre sonunda kendini eski parolaya döndürür; sıfır yapılmamalı.
         */
        'onay_dakikasi' => (int) env('ROTATION_JUNOS_ONAY_DAKIKASI', 2),
    ],

];
