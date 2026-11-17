<?php

declare(strict_types=1);

namespace App\Services\OsUpdate;

use InvalidArgumentException;

/**
 * Makinede çalışan betikler. Hepsi root olarak (doğrudan ya da sudo ile)
 * TEK `sh -c` içinde çalışır; çıktı yalnızca EYS- işaretli satırlar ve paket
 * yöneticisinin liste satırları üzerinden okunur (bkz. PackageReport).
 *
 * Betiğe kullanıcıdan gelen hiçbir değer girmez: mod iki sabitten biridir.
 */
final class OsUpdateScripts
{
    public const KRITIK = 'kritik';

    public const TUMU = 'tumu';

    public const MODLAR = [self::KRITIK, self::TUMU];

    /** Kurulum günlüğü: ayrıntı (hata satırları) makinede burada kalır, panele taşınmaz. */
    public const GUNLUK = '/var/log/eys-sistem-guncelleme.log';

    /** Kurulumun sonuç işaretleri (bkz. kurulumSonucu). */
    public const SONUC = 'EYS-SONUC:';

    public const GUNLUK_BAS = 'EYS-GUNLUK:bas';

    public const GUNLUK_SON = 'EYS-GUNLUK:son';

    /** Başarısız kurulumda panele taşınan günlük satırı sayısı. */
    public const GUNLUK_SATIR = 8;

    /**
     * Denetim: depo listesini yeniler ve bekleyenleri sayar. KURULUM YAPMAZ.
     *
     * TERM=dumb: yum/dnf paket adını kalın basmasın (bkz. PackageReport::temizle).
     *
     * PAGER=cat ve `| cat`: komut uçbirimle (PTY) çalışıyor; apt 3.x
     * (Ubuntu 26.04) listeyi uçbirimde sayfalayıcıyla açıp tuş bekliyordu.
     * Denetim zaman aşımına kadar asılı kalıp "komut tamamlanmadı" ile
     * düşüyordu.
     *
     * EYS-OS: /etc/os-release'teki ad; ekranda makine adının altında durur.
     *
     * EYS-BOLUM:ertelenen (apt): Ubuntu'nun AŞAMALI güncellemeleri. Yeni sürüm
     * önce makinelerin bir yüzdesine açılır; apt sıra gelene kadar onu
     * KURMAZ ("deferred due to phasing"), `apt list --upgradable` ise yine
     * listeler. Bekleyen sayılsaydı "tümünü kur" sonrasında da kalıyorlardı.
     * Simülasyondan (-s) okunur: hiçbir şey kurulmaz, kilit alınmaz.
     *
     * EYS-BOOT: /boot'un bulunduğu bölümde boş alan (MB). Yeni çekirdek
     * sığmazsa kurulum hiç başlamadan düşer; ekran önceden uyarır.
     *
     * Son satır `true`: betiğin çıkış kodu "denetim koştu" demektir;
     * deponun yenilenememesi ayrı bir işaretle (EYS-UYARI:depo) bildirilir.
     */
    public static function denetim(): string
    {
        return <<<'SH'
export LC_ALL=C TERM=dumb PAGER=cat
if [ -r /etc/os-release ]; then ( . /etc/os-release; echo "EYS-OS:${PRETTY_NAME:-$NAME $VERSION_ID}" ); fi
if command -v apt-get >/dev/null 2>&1; then
  echo EYS-PM:apt
  apt-get update -qq -o DPkg::Lock::Timeout=60 >/dev/null 2>&1 || echo EYS-UYARI:depo
  echo EYS-BOLUM:liste
  apt list --upgradable 2>/dev/null | cat
  echo EYS-BOLUM:ertelenen
  apt-get -s -o Debug::NoLocking=1 upgrade 2>/dev/null | sed -n '/deferred due to phasing:/,/^[^ ]/{/^ /p}' | cat
  echo EYS-BOLUM:son
elif command -v dnf >/dev/null 2>&1 || command -v yum >/dev/null 2>&1; then
  pm=dnf; command -v dnf >/dev/null 2>&1 || pm=yum
  echo EYS-PM:$pm
  echo EYS-BOLUM:liste
  $pm -q check-update 2>/dev/null; rc=$?
  echo EYS-BOLUM:guvenlik
  $pm -q updateinfo list --security 2>/dev/null
  echo EYS-BOLUM:son
  if [ "$rc" = 1 ]; then echo EYS-UYARI:depo; fi
  if command -v needs-restarting >/dev/null 2>&1; then
    needs-restarting -r >/dev/null 2>&1
    if [ "$?" = 1 ]; then echo EYS-REBOOT; fi
  fi
else
  echo EYS-PM:yok
fi
if [ -f /var/run/reboot-required ]; then echo EYS-REBOOT; fi
df -Pm /boot 2>/dev/null | awk 'NR==2{print "EYS-BOOT:"$4}'
true
SH;
    }

    /**
     * Kurulum.
     *
     * OTURUMDAN BAĞIMSIZ: asıl iş `setsid` ile yeni bir oturumda başlar ve
     * panel onu `wait` ile bekler. SSH bağlantısı koparsa (ağ, zaman aşımı,
     * işçinin yeniden başlaması) kapanan uçbirimin SIGHUP'ı yarıda kesilmiş
     * bir dpkg bırakmaz; kurulum makinede sonuna kadar sürer.
     *
     * Etkileşim yok: yapılandırma dosyası sorusu mevcut dosyayı korur
     * (confdef/confold), needrestart yalnızca listeler. Yeniden başlatma
     * YAPILMAZ; gerekiyorsa denetim onu ayrıca bildirir.
     *
     * ESKİ ÇEKİRDEKLER ÖNCE TEMİZLENİR: /boot dolunca yeni çekirdek
     * sığmıyor, kurulum hiç başlamadan düşüyordu (16.11.2026, Barkod
     * Yazıcı). Çalışan çekirdeğe dokunulmaz; temizlik başarısız olsa da
     * kurulum denenir.
     *   dnf — `remove --oldinstallonly`: çalışan + en yeni 2 sürüm kalır
     *         (kernel, kernel-core, kernel-modules… hepsi).
     *   yum — package-cleanup varsa aynı sınırla.
     *   apt — YALNIZCA autoremove'un eski saydığı linux-* paketleri; başka
     *         "artık gereksiz" paket silinmez. Hangi çekirdeğin korunacağını
     *         apt kendisi bilir (çalışan + en yeni; 01autoremove-kernels).
     */
    public static function kurulum(string $mod): string
    {
        if (! in_array($mod, self::MODLAR, true)) {
            throw new InvalidArgumentException("Tanımsız kurulum modu: {$mod}");
        }

        $kritik = $mod === self::KRITIK;

        $apt = 'o="-o DPkg::Lock::Timeout=120 -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold"; '
            .'apt-get update -qq $o || true; '
            .'ak=$(apt-get -s $o autoremove 2>/dev/null | sed -n "s/^Remv \\(linux-[^ ]*\\) .*/\\1/p" | tr "\n" " "); '
            .'if [ -n "$ak" ]; then echo "eski cekirdekler: $ak"; apt-get -y -q $o purge $ak || true; fi; '
            .($kritik
                ? 'pk=$(apt list --upgradable 2>/dev/null | grep -e "-security" | cut -d/ -f1 | tr "\n" " "); '
                    .'if [ -n "$pk" ]; then apt-get -y -q $o install --only-upgrade $pk; fi'
                : 'apt-get -y -q $o upgrade --with-new-pkgs');

        $dnf = 'pm=dnf; command -v dnf >/dev/null 2>&1 || pm=yum; '
            .'if [ "$pm" = dnf ]; then dnf -y -q remove --oldinstallonly --setopt installonly_limit=2 || true; '
            .'elif command -v package-cleanup >/dev/null 2>&1; then package-cleanup -y -q --oldkernels --count=2 || true; fi; '
            .'$pm -y -q upgrade'.($kritik ? ' --security' : '');

        $is = 'export LC_ALL=C TERM=dumb PAGER=cat DEBIAN_FRONTEND=noninteractive NEEDRESTART_MODE=l NEEDRESTART_SUSPEND=1; '
            .'date; '
            .'if command -v apt-get >/dev/null 2>&1; then '.$apt.'; '
            .'elif command -v dnf >/dev/null 2>&1 || command -v yum >/dev/null 2>&1; then '.$dnf.'; '
            .'else echo "paket yoneticisi yok"; exit 3; fi';

        /*
         * Sonuç ÇIKIŞ KODUYLA DEĞİL, işaretle döner: komut sıfırdan farklı
         * biterse taşıma çıktıyı hiç vermiyor (PTY yankısında parola var), panel
         * yalnızca "kod 1" yazabiliyordu. Başarısızlıkta günlüğün son satırları
         * EYS-GUNLUK işaretleri arasında gelir; günlüğe parola girmez (sudo
         * parolası betikten önce okunur), yine de kabuk çıktıyı maskeler.
         */
        return 'setsid sh -c '.escapeshellarg($is).' >'.self::GUNLUK.' 2>&1 </dev/null & wait $!; '
            .'rc=$?; echo "'.self::SONUC.'$rc"; '
            .'if [ "$rc" != 0 ]; then echo '.self::GUNLUK_BAS.'; tail -n '.self::GUNLUK_SATIR.' '.self::GUNLUK.'; echo '.self::GUNLUK_SON.'; fi; true';
    }

    /**
     * Yeniden başlatma: bir dakika SONRAYA kurulur ve hemen döner. Bağlantı
     * temiz kapanır, oturumdaki kullanıcılar uyarıyı görür. Panelden başka
     * bir yolla ÇAĞRILMAZ (bkz. OsRebooter).
     */
    public static function yenidenBaslatma(): string
    {
        return "shutdown -r +1 'EYS: panelden yeniden baslatma'";
    }

    /**
     * Kurulum çıktısından çıkış kodu ve (başarısızsa) günlüğün son satırları.
     * İşaret yoksa kod null: kurulumun sonucu okunamadı.
     *
     * @return array{0: int|null, 1: list<string>}
     */
    public static function kurulumSonucu(string $cikti): array
    {
        $cikti = PackageReport::temizle(str_replace("\r", '', $cikti));
        $kod = preg_match('/^'.self::SONUC.'(\d+)\s*$/m', $cikti, $m) === 1 ? (int) $m[1] : null;
        $satirlar = [];

        if (preg_match('/^'.self::GUNLUK_BAS.'\s*$(.*?)^'.self::GUNLUK_SON.'\s*$/ms', $cikti, $g) === 1) {
            $satirlar = array_values(array_filter(
                array_map(fn (string $s): string => trim(preg_replace('/\e\[[0-9;]*[A-Za-z]/', '', $s) ?? $s), explode("\n", $g[1])),
                fn (string $s): bool => $s !== '',
            ));
        }

        return [$kod, $satirlar];
    }
}
