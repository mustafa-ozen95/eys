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
     */
    public static function kurulum(string $mod): string
    {
        if (! in_array($mod, self::MODLAR, true)) {
            throw new InvalidArgumentException("Tanımsız kurulum modu: {$mod}");
        }

        $kritik = $mod === self::KRITIK;

        $apt = 'o="-o DPkg::Lock::Timeout=120 -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold"; '
            .'apt-get update -qq $o || true; '
            .($kritik
                ? 'pk=$(apt list --upgradable 2>/dev/null | grep -e "-security" | cut -d/ -f1 | tr "\n" " "); '
                    .'if [ -n "$pk" ]; then apt-get -y -q $o install --only-upgrade $pk; fi'
                : 'apt-get -y -q $o upgrade --with-new-pkgs');

        $dnf = 'pm=dnf; command -v dnf >/dev/null 2>&1 || pm=yum; '
            .'$pm -y -q upgrade'.($kritik ? ' --security' : '');

        $is = 'export LC_ALL=C TERM=dumb PAGER=cat DEBIAN_FRONTEND=noninteractive NEEDRESTART_MODE=l NEEDRESTART_SUSPEND=1; '
            .'date; '
            .'if command -v apt-get >/dev/null 2>&1; then '.$apt.'; '
            .'elif command -v dnf >/dev/null 2>&1 || command -v yum >/dev/null 2>&1; then '.$dnf.'; '
            .'else echo "paket yoneticisi yok"; exit 3; fi';

        return 'setsid sh -c '.escapeshellarg($is).' >'.self::GUNLUK.' 2>&1 </dev/null & wait $!';
    }
}
