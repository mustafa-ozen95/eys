<?php

declare(strict_types=1);

namespace App\Services\Rotation\Drivers;

use App\Models\Credential;
use App\Services\Rotation\Contracts\NamesRecoveryAccount;
use App\Services\Rotation\Contracts\PasswordDriver;
use App\Services\Rotation\DeviceUnreachableException;
use App\Services\Rotation\Junos\ConfigurationWriter;
use App\Services\Rotation\Junos\JunosSession;
use App\Services\Rotation\Junos\NetconfClient;
use App\Services\Rotation\Junos\NetconfException;
use App\Services\Rotation\Junos\NetconfSession;
use App\Services\Rotation\Junos\NetconfTarget;
use App\Services\Rotation\RotationException;
use App\Services\Rotation\RotationSession;

/**
 * Junos switch hesaplarını NETCONF üzerinden döndürür.
 *
 * EYS bağımsızdır: bu sürücü başka bir panele başvurmaz; NETCONF
 * yığınının en küçük hâli app/Services/Rotation/Junos altında durur.
 *
 * SIRA (PasswordRotator yönetir, burada yalnızca cihazla konuşulur):
 * kurtarma super-user'ı açılır -> onunla girilebildiği kanıtlanır -> panel
 * hesabının parolası KURTARMA oturumundan değiştirilir (kendi parolanı
 * değiştirdiğin oturumla onay veremezsin) -> yeni parola denenir -> kurtarma
 * hesabı yeni parolayla silinir.
 *
 * GERİ ALMA GÜVENCESİ: parola değişimi geri alınabilir commit'tir (onay yeni
 * bir oturumda, kurtarma kimliğiyle verilir); kurtarma hesabını açmak ve
 * silmek düz commit'tir, çünkü ikisi de şu an kullandığımız hesaba dokunmaz.
 *
 * ADRES KİLİDİ YOK: sürücüye ulaşan her kayıt işlenir; kapı kaydın kendi
 * `rotation_driver` alanıdır ('junos') ve döndürme ayrıca `rotation_enabled`
 * ister.
 */
class JunosPasswordDriver implements NamesRecoveryAccount, PasswordDriver
{
    public const KEY = 'junos';

    private ?NetconfClient $client;

    public function __construct(?NetconfClient $client = null)
    {
        /* Testler sahte cihazlı istemci verir; üretimde SSH taşıması kurulur. */
        $this->client = $client;
    }

    public function supportsRecovery(): bool
    {
        return true;
    }

    public function canVerify(): bool
    {
        return true;
    }

    public function recoveryName(): string
    {
        return (string) config('rotation.junos.kurtarma_adi', 'eys-kurtarma');
    }

    public function unsupportedReason(Credential $credential): ?string
    {
        return match (true) {
            blank($credential->host) => 'Adres tanımlı değil.',
            blank($credential->username) => 'Kullanıcı adı tanımlı değil.',
            blank($credential->password) => 'Kayıtlı parola yok; güncelleme mevcut parolayla başlar.',
            strcasecmp(trim((string) $credential->username), $this->recoveryName()) === 0 => 'Kurtarma hesabının adı ('.$this->recoveryName().') güncellenen hesapla aynı olamaz; kurtarma hesabı asıl hesabın üstüne yazardı.',
            default => null,
        };
    }

    public function open(Credential $credential, string $username, #[\SensitiveParameter] string $password): RotationSession
    {
        $target = $this->target($credential, $username, $password);

        try {
            $netconf = $this->client()->connect($target);
        } catch (NetconfException $e) {
            throw $this->cevir($e, "Kimlik ile bağlanılamadı ({$username}).", [$password]);
        }

        return new RotationSession(
            new JunosSession($target, $netconf),
            $username,
            $password,
            static fn () => $netconf->close(),
        );
    }

    /**
     * Kurtarma hesabı: tam yetkili (super-user) yerel kullanıcı.
     *
     * Düz commit: yeni bir hesap eklemek şu an kullandığımız hesaba dokunmaz.
     * Yazma yarıda kalırsa fazladan bir hesap kalır — onu PasswordRotator
     * onarımı kapatır.
     */
    public function createRecovery(RotationSession $session, string $username, #[\SensitiveParameter] string $password): void
    {
        $this->guardName($username);
        $this->guardRecoveryName($username);
        $this->guardPassword($password);

        $this->yaz(
            $session,
            $this->userElement($username, (string) config('rotation.junos.kurtarma_sinifi', 'super-user'), $password),
            'EYS kurtarma hesabi olusturuldu',
            false,
            'Kurtarma hesabı açılamadı.',
            [$password, $session->password],
        );
    }

    /**
     * Hesabın parolasını değiştirir.
     *
     * GERİ ALINABİLİR commit ŞART: kendi giriş parolamızı değiştiriyoruz. Onay
     * ikinci bir oturumda, KURTARMA kimliğiyle verilir; açılamazsa cihaz
     * kendini eski parolaya döndürür. Turun bütün güvenliği bu adımda.
     */
    public function changePassword(RotationSession $session, string $username, #[\SensitiveParameter] string $newPassword): void
    {
        $this->guardName($username);
        $this->guardPassword($newPassword);

        $this->yaz(
            $session,
            $this->userElement($username, null, $newPassword),
            'EYS hesap parolasi degistirildi',
            true,
            'Parola değiştirilemedi.',
            [$newPassword, $session->password],
        );
    }

    /**
     * Kurtarma hesabını siler.
     *
     * Silinen hesap, oturumu açan hesap OLAMAZ (onay oturumu artık açılamazdı)
     * ve adı kurtarma adıyla başlamak zorundadır: yanlış bir çağrı panel
     * hesabını ya da başkasının hesabını silemesin.
     */
    public function deleteRecovery(RotationSession $session, string $username): void
    {
        $this->guardName($username);
        $this->guardRecoveryName($username);

        if (strcasecmp($username, $session->username) === 0) {
            throw new RotationException('Kurtarma hesabı kendi oturumuyla silinemez.');
        }

        $this->yaz(
            $session,
            '<configuration><system><login><user delete="delete"><name>'.$this->xml($username).'</name></user></login></system></configuration>',
            'EYS kurtarma hesabi silindi',
            false,
            'Kurtarma hesabı silinemedi.',
            [$session->password],
        );
    }

    /**
     * Verilen kimlikle NETCONF oturumu açılıp cihaza bir soru sorulabiliyor mu.
     *
     * İstisna ATMAZ (rotator yanıta bakıp geri alma kararı verir); tek
     * istisna ULAŞILAMAMAKTIR: bu `false` DEĞİLDİR, çünkü "cihaz yanıt vermedi"
     * ile "parola reddedildi" aynı şey değil (bkz. DeviceUnreachableException).
     */
    public function verify(Credential $credential, string $username, #[\SensitiveParameter] string $password): bool
    {
        try {
            $this->client()->runOn(
                $this->target($credential, $username, $password),
                static fn (NetconfSession $s) => $s->rpc('<get-software-information/>'),
            );

            return true;
        } catch (NetconfException $e) {
            if ($e->unreachable()) {
                throw new DeviceUnreachableException("Cihaza ulaşılamadı: {$e->getMessage()}");
            }

            return false;
        }
    }

    /**
     * Tek yazma: oturumun içindeki yazıcıyla, hata metni parolasız.
     *
     * @param  list<string>  $gizli  Hata iletisinden silinecek değerler
     */
    private function yaz(RotationSession $session, string $belge, string $ozet, bool $geriAlinabilir, string $failure, array $gizli): void
    {
        /** @var JunosSession $oturum */
        $oturum = $session->handle;

        try {
            (new ConfigurationWriter($this->client(), (int) config('rotation.junos.onay_dakikasi', 2)))
                ->apply($oturum->netconf, $oturum->target, $belge, $ozet, $geriAlinabilir);
        } catch (NetconfException $e) {
            throw $this->cevir($e, $failure, $gizli);
        }
    }

    /**
     * Cihaz hatasını RotationException'a çevirir.
     *
     * Önceki istisna TAŞINMAZ (iz, çağrı bağımsız değişkenlerini barındırabilir)
     * ve bilinen sırlar mesajdan silinir: Junos bazı hata metinlerinde
     * yapılandırma satırını yankılıyor.
     *
     * @param  list<string>  $gizli
     */
    private function cevir(NetconfException $e, string $failure, array $gizli): RotationException
    {
        $mesaj = $failure.' '.$e->getMessage();

        foreach ($gizli as $sir) {
            if ($sir !== '') {
                $mesaj = str_replace([$sir, $this->xml($sir)], '***', $mesaj);
            }
        }

        return $e->unreachable() && $e->stage() === 'connect'
            ? new DeviceUnreachableException($mesaj)
            : new RotationException($mesaj);
    }

    private function userElement(string $name, ?string $class, string $password): string
    {
        $body = '<name>'.$this->xml($name).'</name>';

        /* Mevcut hesabın parolası değişirken sınıfına dokunulmaz. */
        if ($class !== null) {
            $body .= '<class>'.$this->xml($class).'</class>';
        }

        $body .= '<authentication><plain-text-password-value>'.$this->xml($password).'</plain-text-password-value></authentication>';

        return "<configuration><system><login><user>{$body}</user></login></system></configuration>";
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES);
    }

    /**
     * NETCONF portu AYARDAN gelir, kaydın portundan DEĞİL: switch kaydındaki
     * port SSH/web portudur (22) ve NETCONF oraya bakmaz.
     */
    private function target(Credential $credential, string $username, #[\SensitiveParameter] string $password): NetconfTarget
    {
        return new NetconfTarget(
            trim((string) $credential->host),
            (int) config('rotation.junos.port', 830),
            $username,
            $password,
        );
    }

    private function client(): NetconfClient
    {
        return $this->client ??= NetconfClient::ssh();
    }

    /** XML'e ve günlüğe giden ad yalnızca güvenli karakterler taşır. */
    private function guardName(string $username): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,62}$/', $username) !== 1) {
            throw new RotationException('Hesap adı Junos\'a gönderilemeyecek bir karakter içeriyor.');
        }
    }

    /** Kurtarma işlemleri yalnızca kurtarma hesabının adıyla çalışır. */
    private function guardRecoveryName(string $username): void
    {
        if (! str_starts_with(strtolower($username), strtolower($this->recoveryName()))) {
            throw new RotationException('Bu işlem yalnızca kurtarma hesabı için yapılabilir ('.$this->recoveryName().').');
        }
    }

    /** XML 1.0 denetim karakterlerini taşıyamaz; boş parola da gönderilmez. */
    private function guardPassword(string $password): void
    {
        if ($password === '' || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $password) === 1) {
            throw new RotationException('Parola Junos\'a gönderilemeyecek bir karakter içeriyor.');
        }
    }
}
