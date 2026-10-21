<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Erişim türü — bir kayda NASIL bağlanıldığı.
 *
 * Varsayılan port türle birlikte gelir: aynı sayıyı her kayıtta elle yazmak
 * er ya da geç bir kayıtta yanlış yazılır.
 */
enum AccessType: string
{
    /*
     * RemoteTool, Rdp'den AYRIDIR: RustDesk/AnyDesk gibi araçlar RDP
     * protokolünü konuşmaz, 3389'a bağlanmaz ve kimliği adres değil bir
     * kimlik numarasıdır. İkisini aynı türe koymak varsayılan portu da,
     * ileride seçilecek sıfırlama sürücüsünü de yanlış yapardı.
     */
    case Ssh = 'ssh';
    case Rdp = 'rdp';

    /*
     * VNC, Rdp'den AYRIDIR ve RemoteTool da değildir: protokolü kendi
     * protokolü, portu 5900 ve konsol onu RDP istemcisiyle açamaz.
     *
     * NEDEN GEREKTİ: ekranı sürekli değişen bir makinede RDP yolu taşımıyor.
     * xrdp'nin VNC istemcisi yalnızca RAW konuşuyor, yani her kare
     * sıkıştırılmadan gidiyor ve tüketen tarafı boğuyor. Guacamole'un KENDİ
     * VNC istemcisi sıkıştırmalı kodlama konuşuyor; tarayıcıya giden veri
     * kat kat azalıyor.
     */
    case Vnc = 'vnc';
    case RemoteTool = 'remote_tool';
    case Panel = 'panel';
    case Web = 'web';
    case Ilo = 'ilo';
    case Esxi = 'esxi';
    case Vcenter = 'vcenter';
    case Backup = 'backup';
    case Database = 'database';
    case Ftp = 'ftp';
    case Membership = 'membership';
    case License = 'license';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Ssh => 'SSH',
            self::Rdp => 'Uzak Masaüstü',
            self::Vnc => 'VNC',
            self::RemoteTool => 'Uzak erişim aracı',
            self::Panel => 'Panel',
            self::Web => 'Web',
            self::Ilo => 'iLO / IPMI',
            self::Esxi => 'ESXi',
            self::Vcenter => 'vCenter',
            self::Backup => 'Yedek',
            self::Database => 'Veritabanı',
            self::Ftp => 'FTP',
            self::Membership => 'Üyelik',
            self::License => 'Lisans',
            self::Other => 'Diğer',
        };
    }

    public function badge(): BadgeVariant
    {
        return match ($this) {
            self::Ssh => BadgeVariant::Green,
            self::Rdp => BadgeVariant::Blue,
            self::Vnc => BadgeVariant::Blue,
            self::RemoteTool => BadgeVariant::Cyan,
            self::Panel => BadgeVariant::Cyan,
            self::Web => BadgeVariant::Cyan,
            self::Ilo => BadgeVariant::Orange,
            self::Esxi, self::Vcenter => BadgeVariant::Purple,
            self::Backup => BadgeVariant::Yellow,
            self::Database => BadgeVariant::Pink,
            self::Ftp => BadgeVariant::Yellow,
            self::Membership, self::License => BadgeVariant::Gray,
            self::Other => BadgeVariant::Gray,
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $type): array => [$type->value => $type->label()])
            ->all();
    }
}
