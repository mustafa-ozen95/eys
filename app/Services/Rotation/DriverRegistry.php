<?php

declare(strict_types=1);

namespace App\Services\Rotation;

use App\Enums\AccessType;
use App\Models\Credential;
use App\Models\PasswordRotation;
use App\Services\Rotation\Contracts\NeedsMachineAccount;
use App\Services\Rotation\Contracts\PasswordDriver;
use Illuminate\Contracts\Container\Container;

/**
 * Kayda hangi sürücünün uygulanacağını belirler.
 *
 * Sıra: kaydın kendi `rotation_driver` alanı -> platformun varsayılanı.
 * Kayıt alanı, aynı platformda farklı yol konuşan cihazlar için vardır.
 *
 * Sürücü listesi config/rotation.php içindedir; yeni bir platform desteklemek
 * bir sınıf yazıp oraya bir satır eklemektir.
 */
class DriverRegistry
{
    /**
     * Sürücülerin insan tarafında okunan adları.
     *
     * Anahtar burada yoksa kendisi gösterilir: yeni bir sürücü eklenince
     * liste bozulmaz, yalnızca ham adıyla görünür.
     */
    private const LABELS = [
        'ssh' => 'Linux · SSH',
        'windows' => 'Windows · OpenSSH',
        'winrm' => 'Windows · WinRM',
        'winrm_kurtarmali' => 'Windows · WinRM (geçici hesaplı)',
        'mailcow' => 'Mailcow · alan yöneticisi (API anahtarı)',
        'mailcow_admin' => 'Mailcow · süper yönetici',
        'rspamd' => 'Rspamd arayüzü',
        'aten_cn8000' => 'ATEN CN8000A · KVM over IP',
        'lantronix_spider' => 'Lantronix Spider / SpiderDuo · KVM over IP',
        'plesk' => 'Plesk paneli · Linux (SSH üzerinden)',
        'plesk_windows' => 'Plesk paneli · Windows (WinRM üzerinden)',
        'vsphere' => 'ESXi sunucusu',
        'vcenter' => 'vCenter · appliance hesabı (root)',
        'vcenter_sso' => 'vCenter · SSO hesabı (administrator@vsphere.local)',
        'ilo' => 'HPE iLO (nesli kendi bulur)',
        'redfish' => 'HPE iLO · Redfish (elle sabitleme)',
        'ribcl' => 'HPE iLO · RIBCL (elle sabitleme)',
        'junos' => 'Junos switch · NETCONF',
    ];

    /**
     * Her sürücünün CİHAZDAN İSTEDİĞİ.
     *
     * Ekranda "Parola güncelleme" başlığının yanındaki soru işareti bunu
     * gösterir. Kullanıcı düğmeyi göremediğinde ya da tur düştüğünde asıl
     * soru "bu makinede neyin açık olması gerekiyordu" oluyordu; cevabı
     * kaynağı okumadan bulmanın yolu yoktu.
     *
     * Metinler sürücülerin GERÇEKTEN kullandığı yoldan yazıldı (bkz. her
     * sürücünün başındaki ölçüm notu), tahminle değil. Bir sürücü eklenip
     * buraya satır yazılmazsa ekran genel açıklamayı gösterir — yanlış bilgi
     * vermez.
     *
     * @var array<string, string>
     */
    private const GEREKSINIMLER = [
        'ssh' => 'Makineye SSH ile bağlanılır (kayıttaki port, boşsa 22) ve parola '
            .'`passwd` ile değiştirilir. Kayıttaki hesabın sudo yetkisi olmalı; '
            .'parola sorulursa kendi parolasıyla yanıtlanır.',

        'windows' => 'Windows üzerinde OpenSSH sunucusu açık olmalı (kayıttaki port, boşsa 22). '
            .'Parola `net user` ile değiştirilir; hesabın yerel yönetici olması gerekir.',

        'winrm' => 'Windows Uzaktan Yönetimi (WinRM) açık olmalı — HTTP, port 5985. '
            .'Açmak için makinede: `winrm quickconfig`. Hesabın yerel yönetici olması gerekir. '
            .'RDP açık olması YETMEZ; RDP ile WinRM ayrı şeylerdir.',

        'winrm_kurtarmali' => 'WinRM (port 5985) açık olmalı. Bu sürücü önce geçici bir '
            .'yönetici hesabı açar, parolayı onun üzerinden değiştirir ve işi bitince '
            .'geçici hesabı siler — kendi parolasını değiştiren hesabın oturumu düşerse '
            .'tur yarıda kalmasın diye.',

        'plesk' => 'Panelin durduğu sunucuya SSH ile bağlanılır (port 22) ve parola '
            .'Plesk\'in kendi komut satırıyla değiştirilir. SSH hesabının sudo yetkisi olmalı.',

        'plesk_windows' => 'Panelin durduğu WINDOWS makineye WinRM ile bağlanılır (port 5985) '
            .'ve parola Plesk\'in kendi aracıyla değiştirilir (plesk.exe bin admin --set-password). '
            .'Seçilen hesabın makinede yönetici olması gerekir. Parola komut satırına yazılmaz: '
            .'Plesk\'in önerdiği gibi PSA_PASSWORD ortam değişkeninden geçirilir.',

        'vsphere' => 'ESXi\'nin vSphere Web Services (SOAP) arayüzü kullanılır — HTTPS, port 443. '
            .'Kayıttaki hesabın ESXi üzerinde Administrator rolü olmalı.',

        'vcenter' => 'vCenter appliance yönetim arayüzü (VAMI, port 5480) ve API (port 443) '
            .'kullanılır. Bu sürücü appliance\'in kendi hesabını (root) değiştirir.',

        'vcenter_sso' => 'vCenter SSO hesabı (administrator@vsphere.local) API üzerinden '
            .'değiştirilir — HTTPS, port 443. SSO parola kuralları gereği uzunluk sınırı vardır.',

        'ilo' => 'HPE iLO\'nun web arayüzüne HTTPS ile bağlanılır (port 443). Sürücü iLO\'nun '
            .'neslini kendisi yoklar ve Redfish ya da RIBCL yolundan hangisi çalışıyorsa onu '
            .'kullanır. Hesabın "User Administration" yetkisi olmalı.',

        'redfish' => 'HPE iLO · Redfish API — HTTPS, port 443. iLO 4 (2.30+) ve üstü. '
            .'Hesabın "User Administration" yetkisi olmalı.',

        'ribcl' => 'HPE iLO · RIBCL (XML) — HTTPS, port 443. Eski iLO nesilleri için. '
            .'Hesabın "User Administration" yetkisi olmalı.',

        'junos' => 'Junos switch\'e NETCONF ile (SSH subsystem, port 830) bağlanılır. Önce tam yetkili '
            .'bir kurtarma hesabı açılır, parola onunla değiştirilir (geri alınabilir commit) ve '
            .'kurtarma hesabı silinir. Switch\'te `system services netconf ssh` açık olmalı.',

        'mailcow' => 'mailcow API\'si kullanılır; kimlik doğrulama API ANAHTARIYLA yapılır, '
            .'kullanıcı adı/parolayla değil. Panelde okuma-yazma yetkili bir API anahtarı '
            .'tanımlı olmalı ve anahtarın erişim listesinde panelin adresi bulunmalı.',

        'mailcow_admin' => 'mailcow süper yöneticisi için API ucu YOK; sunucuya SSH ile '
            .'bağlanılıp mailcow\'un kendi yardımcı betiği çalıştırılır. SSH hesabının sudo '
            .'yetkisi ve mailcow dizinine erişimi olmalı.',

        'rspamd' => 'Rspamd arayüzünün parolası sunucudaki yapılandırma dosyasında durur; '
            .'SSH ile bağlanılıp dosya güncellenir ve servis yeniden yüklenir. SSH hesabının '
            .'sudo yetkisi olmalı.',

        'aten_cn8000' => 'ATEN CN8000A web arayüzüne bağlanılır. Cihazın web arayüzü açık '
            .'olmalı ve kayıttaki hesabın kullanıcı yönetimi yetkisi bulunmalı.',

        'lantronix_spider' => 'Lantronix Spider / SpiderDuo cihazına SSH ile bağlanılıp '
            .'belgelenmiş komut satırı kullanılır (port 22). SSH erişimi açık olmalı.',
    ];

    public function __construct(private Container $container) {}

    /**
     * Bu kayıt için parola güncellemenin CİHAZDAN İSTEDİĞİ.
     *
     * Sürücü seçilemiyorsa (platform tanımsız) null döner: orada anlatılacak
     * bir gereksinim yok, zaten "sürücü tanımlı değil" yazıyor.
     */
    public function gereksinim(Credential $credential): ?string
    {
        $key = $this->keyFor($credential);

        return $key === null ? null : (self::GEREKSINIMLER[$key] ?? null);
    }

    /** @return array<string, class-string<PasswordDriver>> */
    public function drivers(): array
    {
        return config('rotation.drivers', []);
    }

    public function keyFor(Credential $credential): ?string
    {
        $key = $credential->rotation_driver ?: $credential->operating_system->rotationDriver();

        /*
         * ANAHTAR NULL OLABİLİR ve önce o sorulur.
         *
         * Sürücüsü olmayan türler (uygulama hesabı, dış portal, gömülü cihaz)
         * için `rotationDriver()` null döner. Doğrudan `isset($dizi[null])`
         * yazmak PHP 8.5'te uyarı üretiyor — kasadaki her sürücüsüz kayıt
         * için bir tane, yani liste ekranında düzinelerce. PHP 9'da bu bir
         * hata olacak.
         */
        if ($key === null) {
            return null;
        }

        return isset($this->drivers()[$key]) ? $key : null;
    }

    public function for(Credential $credential): ?PasswordDriver
    {
        $key = $this->keyFor($credential);

        return $key === null ? null : $this->container->make($this->drivers()[$key]);
    }

    /**
     * Kayıt döndürülebilir mi? Değilse insanca bir neden döner.
     *
     * Ekran bu metni doğrudan gösterir: "neden bu düğme yok" sorusunun
     * cevabı kullanıcıda kalmasın.
     */
    public function blockedReason(Credential $credential): ?string
    {
        /*
         * Genel kapatma en başta sorulur: kapalıysa kaydın kendi ayarını
         * incelemenin anlamı yok ve mesaj da doğru sebebi söylemeli.
         */
        if (! config('rotation.enabled', true)) {
            return 'Otomatik parola güncelleme panel ayarlarından kapatılmış.';
        }

        /*
         * KİŞİSEL KAYIT OTOMASYONA GİRMEZ. Panel kimsenin kendi parolasını
         * kendiliğinden değiştirmemeli; sebep ekranda da yazsın ki "neden bu
         * düğme yok" sorusu kullanıcıda kalmasın.
         */
        if ($credential->kisiselMi()) {
            return 'Kişisel kayıt: otomatik güncelleme yapılmaz, parolayı siz değiştirirsiniz.';
        }

        /*
         * Sürücü sorusu ÖNCE gelir. Tersi sırada, hiç döndürülemeyecek bir
         * kayıt için "otomatik döndürme açık değil" yazıyordu — sanki
         * kutuyu işaretlemek yetecekmiş gibi.
         */
        $driver = $this->for($credential);

        if ($driver === null) {
            return $credential->operating_system->label()
                .' için sürücü tanımlı değil; bu kaydın parolası elle güncellenir.';
        }

        return $driver->unsupportedReason($credential);
    }

    /**
     * Bu kaydın turu kaç adım sürecek — tur BAŞLAMADAN önce.
     *
     * Sırada bekleyen tur için ekran "0/6" yazıyordu; oysa kurtarma hesabı
     * açmayan sürücülerde (WinRM, iLO…) tur üç adımdır ve işçi başlayınca
     * gösterge "0/3"e düşüyordu. Rotator'la AYNI kural: sürücü kurtarma destekliyorsa kurtarmalı sıra
     * (bkz. PasswordRotator::rotate).
     */
    public function adimSayisi(Credential $credential): int
    {
        return $this->for($credential)?->supportsRecovery()
            ? PasswordRotation::ADIM_KURTARMALI
            : PasswordRotation::ADIM_DOGRUDAN;
    }

    /**
     * OTOMATİK tur bu kaydı alır mı?
     *
     * `rotation_enabled` ELLE GÜNCELLEMEYİ ENGELLEMEZ. Engelliyordu ve bu
     * saçmaydı: kutunun adı "otomatik güncellemeye açık" iken kapalı olduğu
     * kayıtta, kullanıcının kendi başlattığı tur da "otomatik güncelleme
     * açık değil" diye reddediliyordu; oysa elle tur otomatik değildir.
     *
     * Elle turun kapısı ZATEN AYRI: `rotate` yetkisi olmayan kimse
     * başlatamıyor (bkz. RotationLauncher::reasonToSkip). Bayrak, kaydın
     * zamanlanmış turlara dahil olup olmadığını söyler.
     */
    public function autoBlockedReason(Credential $credential): ?string
    {
        if (! $credential->rotation_enabled) {
            return 'Otomatik güncellemeye kapalı; tek tek güncellenebilir.';
        }

        return $this->blockedReason($credential);
    }

    /** @return array<string, string> */
    /**
     * Seçim listesi.
     *
     * Anahtarlar ('winrm_kurtarmali', 'ribcl') koda göre isimlendirilmiş;
     * listede ham hâlleriyle duruyorlardı ve hangisinin ne olduğu ancak
     * kaynağı okuyarak anlaşılıyordu. Etiketler bunu düzeltir.
     *
     * HİÇBİR SÜRÜCÜ LİSTEDEN ÇIKARILMAZ. Kullanılmıyor görünen üçünden ikisi
     * ('redfish', 'ribcl') aslında her iLO kaydında çalışıyor:
     * 'ilo' cihazın neslini yoklayıp bu ikisinden birine devrediyor. Listeden
     * silmek, otomatik seçim yanıldığında elle sabitleme imkânını da
     * götürürdü — üstelik o kaydı düzenleyen biri, seçili değeri listede
     * bulamadığı için farkında olmadan sıfırlardı.
     */
    public function options(): array
    {
        return ['' => 'Platforma göre seç', ...collect($this->drivers())
            ->keys()
            ->mapWithKeys(fn (string $key): array => [$key => self::LABELS[$key] ?? $key])
            ->all()];
    }

    /**
     * MAKİNENİN KENDİ HESABINI isteyen sürücüler.
     *
     * "Güncelleme hesabı — kasadan seç" alanı her kayıtta aynı duruyordu;
     * oysa o alanın ne işe yaradığı SÜRÜCÜYE GÖRE DEĞİŞİYOR. Çoğu sürücüde parolayı değiştiren, parolası değişen
     * hesabın kendisidir ve orada kasadan SSH seçmenin bir anlamı yok;
     * Plesk/mailcow yöneticisi/rspamd'de ise değiştiren şey makinenin SSH
     * kimliğidir.
     *
     * Liste SÜRÜCÜLERİN KENDİSİNDEN çıkar (NeedsMachineAccount arayüzü); ekranda
     * elle tutulan ikinci bir liste olsaydı yeni bir sürücü eklendiğinde
     * sessizce eskirdi.
     *
     * @return list<string>
     */
    public function makineHesabiIsteyenler(): array
    {
        return collect($this->drivers())
            ->filter(fn (string $sinif): bool => is_a($sinif, NeedsMachineAccount::class, true))
            ->keys()
            ->values()
            ->all();
    }

    /**
     * Kasadan seçilebilecek TÜM kayıt türleri (bütün sürücülerin birleşimi).
     *
     * Yeni kayıt penceresinde sürücü henüz seçilmemiştir; orada daraltacak bir
     * bilgi yok. Düzenleme penceresi kaydın kendi sürücüsüne sorar
     * (bkz. MachineAccount::erisimTurleri).
     *
     * @return list<string>
     */
    public function makineErisimTurleri(): array
    {
        $turler = collect($this->drivers())
            ->filter(fn (string $sinif): bool => is_a($sinif, NeedsMachineAccount::class, true))
            ->flatMap(fn (string $sinif): array => $this->container->make($sinif)->makineErisimTurleri())
            ->map(fn (AccessType $tur): string => $tur->value)
            ->unique()
            ->values()
            ->all();

        return $turler === [] ? [AccessType::Ssh->value] : $turler;
    }
}
