<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Kaydın üzerinde çalıştığı platform.
 *
 * Bu alan süsleme değildir: parola sıfırlama otomasyonunda HANGİ SÜRÜCÜNÜN
 * çalışacağını belirler. "Bu kayda hangi yöntem uygulanır" sorusu kodda
 * değil, veride durur — yeni bir kayıt eklendiğinde otomasyon dosyasına
 * dokunmak gerekmesin.
 *
 * İşletim sistemi olmayan hâller de burada: BMC'nin (iLO) üstünde işletim
 * sistemi çalışmaz, portalın hiç yoktur. Onları "diğer"e atmak, sıfırlama
 * yolu olmayanla henüz belirlenmemişi aynı kutuya koyardı.
 */
enum OperatingSystem: string
{
    case Linux = 'linux';
    case Windows = 'windows';
    case Esxi = 'esxi';
    case Vcenter = 'vcenter';
    case Bmc = 'bmc';
    case Embedded = 'embedded';
    case Application = 'application';
    case Portal = 'portal';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Linux => 'Linux',
            self::Windows => 'Windows',
            self::Esxi => 'VMware ESXi',
            self::Vcenter => 'VMware vCenter',
            self::Bmc => 'BMC / iLO',
            self::Embedded => 'Gömülü cihaz',
            self::Application => 'Uygulama hesabı',
            self::Portal => 'Dış portal',
            self::Unknown => 'Belirsiz',
        };
    }

    public function badge(): BadgeVariant
    {
        return match ($this) {
            self::Linux => BadgeVariant::Green,
            self::Windows => BadgeVariant::Blue,
            self::Esxi, self::Vcenter => BadgeVariant::Purple,
            self::Bmc => BadgeVariant::Orange,
            self::Embedded => BadgeVariant::Cyan,
            self::Application => BadgeVariant::Yellow,
            self::Portal => BadgeVariant::Pink,
            self::Unknown => BadgeVariant::Gray,
        };
    }

    /**
     * Parolası otomasyonla sıfırlanabilir mi?
     *
     * Yanıt "bu platformda bir yol VAR mı" sorusudur; o yolun sizin
     * cihazınızda açık olup olmadığı ölçülmeden bilinmez.
     */
    public function rotatable(): bool
    {
        return match ($this) {
            self::Linux, self::Windows, self::Esxi, self::Vcenter, self::Bmc => true,
            self::Embedded, self::Application, self::Portal, self::Unknown => false,
        };
    }

    /**
     * Bu platformda anlamlı erişim türleri — EN OLASI ÖNCE.
     *
     * Kayıt formunda önce platform seçilir, erişim türü listesi ona göre
     * daralır: seçim sırası platform, sonra erişim türü. Windows seçen biri listenin başında uzak masaüstünü,
     * iLO seçen yalnızca iLO'yu görür.
     *
     * Liste GENİŞ tutuldu: kullanılabilecek her platform–tür çifti burada
     * var, yani hiçbir kayıt listesinde olmayan bir türe düşmez. "Belirsiz" her türü kabul eder.
     *
     * @return list<AccessType>
     */
    public function accessTypes(): array
    {
        return match ($this) {
            self::Linux => [AccessType::Ssh, AccessType::Vnc, AccessType::Web, AccessType::Panel,
                AccessType::Database, AccessType::Ftp, AccessType::Backup, AccessType::Other],
            self::Windows => [AccessType::Rdp, AccessType::Ssh, AccessType::Vnc, AccessType::RemoteTool,
                AccessType::Web, AccessType::Panel, AccessType::Database, AccessType::Ftp,
                AccessType::Backup, AccessType::Other],
            self::Esxi => [AccessType::Esxi, AccessType::Ssh],
            self::Vcenter => [AccessType::Vcenter, AccessType::Panel, AccessType::Ssh],
            self::Bmc => [AccessType::Ilo, AccessType::Web],
            self::Embedded => [AccessType::Panel, AccessType::Web, AccessType::Ssh, AccessType::Other],
            self::Application => [AccessType::Web, AccessType::Panel, AccessType::Database,
                AccessType::Ftp, AccessType::RemoteTool, AccessType::Backup, AccessType::Other],
            self::Portal => [AccessType::Membership, AccessType::License, AccessType::Web, AccessType::Other],
            self::Unknown => AccessType::cases(),
        };
    }

    /** Sıfırlamanın hangi yoldan yapılacağı — sürücü seçimi buradan okunur. */
    public function rotationDriver(): ?string
    {
        return match ($this) {
            self::Linux => 'ssh',
            self::Windows => 'windows',
            self::Esxi => 'vsphere',
            self::Vcenter => 'vcenter',
            self::Bmc => 'ilo',
            default => null,
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $os): array => [$os->value => $os->label()])
            ->all();
    }
}
