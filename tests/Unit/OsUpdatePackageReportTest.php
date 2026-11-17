<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\OsUpdate\OsUpdater;
use App\Services\OsUpdate\OsUpdateScripts;
use App\Services\OsUpdate\PackageReport;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Denetim çıktısının okunması: kritik/normal ayrımı ve betiklerin sınırı.
 *
 * Çıktılar kayıtlı örneklerdir (Ubuntu/Mint apt, AlmaLinux dnf); testte
 * hiçbir makineye bağlanılmaz.
 */
class OsUpdatePackageReportTest extends TestCase
{
    #[Test]
    public function apt_guvenlik_kolundan_gelen_paket_kritik_sayilir(): void
    {
        $cikti = implode("\r\n", [
            'EYS-SUDO-PAROLA:***',
            'EYS-PM:apt',
            'EYS-BOLUM:liste',
            'Listing...',
            'openssl/noble-updates,noble-security 3.0.13-0ubuntu3.5 amd64 [upgradable from: 3.0.13-0ubuntu3.4]',
            'libssl3t64/noble-updates,noble-security 3.0.13-0ubuntu3.5 amd64 [upgradable from: 3.0.13-0ubuntu3.4]',
            'mintupdate/zena 6.1.2 all [upgradable from: 6.1.1]',
            'firefox/noble-updates 133.0+linuxmint1+zena amd64 [upgradable from: 132.0]',
            'EYS-BOLUM:son',
            'EYS-REBOOT',
        ]);

        $rapor = PackageReport::parse($cikti);

        $this->assertSame('apt', $rapor->manager);
        $this->assertSame(2, $rapor->critical);
        $this->assertSame(2, $rapor->normal);
        $this->assertTrue($rapor->rebootRequired);
        $this->assertFalse($rapor->repoWarning);

        /* Kritikler önce, sonra ada göre. */
        $this->assertSame(['libssl3t64', 'openssl', 'firefox', 'mintupdate'], array_column($rapor->packages, 'name'));
        $this->assertSame('3.0.13-0ubuntu3.5', $rapor->packages[1]['version']);
    }

    /**
     * Ubuntu'nun aşamalı (phased) güncellemesi: apt list gösterir ama apt
     * sıra gelene kadar kurmaz. Bekleyen sayılsaydı MailGateway'de "tümünü
     * kur" sonrasında da 3 paket kalıyordu (16.11.2026).
     */
    #[Test]
    public function asamali_apt_guncellemesi_bekleyen_sayilmaz(): void
    {
        $rapor = PackageReport::parse(implode("\n", [
            'EYS-PM:apt',
            'EYS-BOLUM:liste',
            'Listing...',
            'distro-info/noble-updates 1.7ubuntu0.1 amd64 [upgradable from: 1.7build1]',
            'python3-distro-info/noble-updates 1.7ubuntu0.1 all [upgradable from: 1.7build1]',
            'snapd/noble-updates 2.78+ubuntu24.04 amd64 [upgradable from: 2.77]',
            'libc6/noble-updates,noble-security 2.39-0ubuntu8.7 amd64 [upgradable from: 2.39-0ubuntu8.6]',
            'curl/noble-updates 8.5.0-2ubuntu10.7 amd64 [upgradable from: 8.5.0-2ubuntu10.6]',
            'EYS-BOLUM:ertelenen',
            '  distro-info python3-distro-info',
            '  snapd libc6:i386',
            'EYS-BOLUM:son',
        ]));

        $this->assertSame(0, $rapor->critical, 'aşamalı dağıtım mimariden bağımsız: libc6:i386 ertelendiyse libc6 de');
        $this->assertSame(1, $rapor->normal);
        $this->assertSame(['curl'], array_column(array_filter($rapor->packages, fn (array $p): bool => ! $p['critical']), 'name'));
        $this->assertStringContainsString('EYS-BOLUM:ertelenen', OsUpdateScripts::denetim());
        $this->assertStringContainsString('apt-get -s', OsUpdateScripts::denetim(), 'ertelenenler simülasyondan okunur, kurulmaz');
    }

    /** /boot boş alanı okunur; satır yoksa (eski betik, okunamadı) boş kalır. */
    #[Test]
    public function boot_bos_alani_okunur(): void
    {
        $this->assertSame(42, PackageReport::parse("EYS-PM:dnf\nEYS-BOLUM:liste\nEYS-BOLUM:son\nEYS-BOOT:42\n")->bootFreeMb);
        $this->assertNull(PackageReport::parse("EYS-PM:apt\nEYS-BOLUM:liste\nEYS-BOLUM:son\n")->bootFreeMb);
        $this->assertStringContainsString('df -Pm /boot', OsUpdateScripts::denetim());
    }

    /**
     * /boot dolunca yeni çekirdek sığmıyor, kurulum hiç başlamıyordu
     * (Barkod Yazıcı, 16.11.2026). Kurulum önce eski çekirdekleri siler;
     * apt'de YALNIZCA linux-* paketleri, başka "gereksiz" paket değil.
     * Denetim hâlâ hiçbir şey silmez.
     */
    #[Test]
    public function kurulum_once_eski_cekirdekleri_temizler_denetim_temizlemez(): void
    {
        foreach (OsUpdateScripts::MODLAR as $mod) {
            $betik = OsUpdateScripts::kurulum($mod);

            $this->assertStringContainsString('remove --oldinstallonly --setopt installonly_limit=2 || true', $betik);
            $this->assertStringContainsString('package-cleanup -y -q --oldkernels --count=2 || true', $betik);
            $this->assertStringContainsString('s/^Remv \\(linux-[^ ]*\\) .*/\\1/p', $betik, 'apt: yalnızca çekirdek paketleri');
            $this->assertStringNotContainsString('apt-get -y -q $o autoremove', $betik, 'apt: toplu autoremove yok');
            $this->assertLessThan(strpos($betik, '$pm -y -q upgrade'), strpos($betik, 'oldinstallonly'), 'dnf: temizlik kurulumdan önce');
            $aptKurulum = $mod === OsUpdateScripts::KRITIK ? 'install --only-upgrade' : 'apt-get -y -q $o upgrade';
            $this->assertLessThan(strpos($betik, $aptKurulum), strpos($betik, 'purge $ak'), 'apt: temizlik kurulumdan önce');
        }

        foreach (['purge', 'remove', 'autoremove -y', 'package-cleanup'] as $yasak) {
            $this->assertStringNotContainsString($yasak, OsUpdateScripts::denetim());
        }
    }

    /**
     * sudo'lu bağlantıda uçbirim girdi sonunu "^@" diye yankılıyor ve ilk
     * satırın önüne yapıştırıyordu (Batuhan, Linux Mint 22.3, gerçek çıktı).
     * Kurulum başarılı bittiği hâlde "sonucu okunamadı" sayılıyor, denetimde
     * işletim sistemi adı düşüyordu.
     */
    #[Test]
    public function sudo_yankisi_ilk_satiri_bozmaz(): void
    {
        $kurulum = "EYS-SUDO-PAROLA:\r\n\r\nNDSGT89033111\r\n^@EYS-SONUC:0\r\n\r\nNDSRC4456fe4686d1:0";
        $this->assertSame([0, []], OsUpdateScripts::kurulumSonucu($kurulum));

        $hata = "NDSGT1\r\n^@^DEYS-SONUC:100\r\nEYS-GUNLUK:bas\r\nE: dpkg was interrupted\r\nEYS-GUNLUK:son\r\nNDSRC1:0";
        $this->assertSame([100, ['E: dpkg was interrupted']], OsUpdateScripts::kurulumSonucu($hata));

        $denetim = PackageReport::parse(implode("\r\n", [
            'EYS-SUDO-PAROLA:', '', 'NDSGT89033111',
            '^@EYS-OS:Linux Mint 22.3',
            'EYS-PM:apt', 'EYS-BOLUM:liste', 'Listing...',
            'firefox/noble-updates 133.0+linuxmint1+zena amd64 [upgradable from: 132.0]',
            'EYS-BOLUM:ertelenen', 'EYS-BOLUM:son', 'EYS-BOOT:58495', '', 'NDSRC4456fe4686d1:0',
        ]));
        $this->assertSame('Linux Mint 22.3', $denetim->os);
        $this->assertSame(1, $denetim->normal);
        $this->assertSame(58495, $denetim->bootFreeMb);
    }

    #[Test]
    public function debian_guvenlik_kolu_da_kritiktir_ama_adinda_security_gecen_kol_degildir(): void
    {
        $rapor = PackageReport::parse(implode("\n", [
            'EYS-PM:apt', 'EYS-BOLUM:liste',
            'curl/bookworm-security 7.88.1-10+deb12u8 amd64 [upgradable from: 7.88.1-10+deb12u7]',
            'tool/securityteam-updates 1.2 amd64 [upgradable from: 1.1]',
            'EYS-BOLUM:son',
        ]));

        $this->assertSame(1, $rapor->critical);
        $this->assertSame('curl', $rapor->packages[0]['name']);
        $this->assertSame(1, $rapor->normal);
    }

    /** Makineden gelen gerçek biçim: ad kalın, sonra ESC[m ve SI. */
    #[Test]
    public function uclbirim_renk_kodlari_paket_adina_ve_surume_karismaz(): void
    {
        $cikti = implode("\n", [
            'EYS-PM:dnf',
            'EYS-BOLUM:liste',
            "\e[1mbind-libs.x86_64 \e[m\x0f   32:9.11.4-26.P2.el7_9.16    updates",
            "\e[1mcurl.x86_64      \e[m\x0f   7.29.0-59.el7_9.2           updates",
            'EYS-BOLUM:guvenlik',
            "RHSA-2024:1234 Important/Sec. \e[1mbind-libs-32:9.11.4-26.P2.el7_9.16.x86_64\e[m",
            'EYS-BOLUM:son',
        ]);

        $rapor = PackageReport::parse($cikti);

        $this->assertSame([
            ['name' => 'bind-libs', 'version' => '32:9.11.4-26.P2.el7_9.16', 'critical' => true],
            ['name' => 'curl', 'version' => '7.29.0-59.el7_9.2', 'critical' => false],
        ], $rapor->packages);
        $this->assertSame(1, $rapor->critical);
        $this->assertSame(1, $rapor->normal);
    }

    #[Test]
    public function denetim_ve_kurulum_betigi_uclbirimi_renksiz_ve_sayfalayicisiz_ister(): void
    {
        foreach ([OsUpdateScripts::denetim(), OsUpdateScripts::kurulum(OsUpdateScripts::TUMU)] as $betik) {
            $this->assertStringContainsString('TERM=dumb', $betik);
            /* apt 3.x uçbirimde sayfalayıcı açıp tuş bekliyordu (Ubuntu 26.04). */
            $this->assertStringContainsString('PAGER=cat', $betik);
        }

        $this->assertStringContainsString('apt list --upgradable 2>/dev/null | cat', OsUpdateScripts::denetim());
    }

    #[Test]
    public function isletim_sistemi_adi_okunur(): void
    {
        $rapor = PackageReport::parse(implode("\n", [
            'EYS-OS:Ubuntu 26.04.1 LTS',
            'EYS-PM:apt',
            'EYS-BOLUM:liste',
            'EYS-BOLUM:son',
        ]));

        $this->assertSame('Ubuntu 26.04.1 LTS', $rapor->os);
        $this->assertNull(PackageReport::parse("EYS-PM:yok\n")->os);
    }

    #[Test]
    public function dnf_guvenlik_bildirisine_bagli_paket_kritik_bolunmus_satir_birlesir(): void
    {
        $cikti = implode("\n", [
            'EYS-PM:dnf',
            'EYS-BOLUM:liste',
            '',
            'openssl.x86_64                1:3.0.7-28.el9_4          baseos',
            'kernel.x86_64                 5.14.0-427.42.1.el9_4     baseos',
            'python3-cloud-what-a-very-long-package-name.noarch',
            '                              1.29.40-1.el9             appstream',
            'Obsoleting Packages',
            'grub2-tools.x86_64            1:2.06-80.el9             baseos',
            'EYS-BOLUM:guvenlik',
            'ALSA-2024:9541 Important/Sec. openssl-1:3.0.7-28.el9_4.x86_64',
            'EYS-BOLUM:son',
            'EYS-UYARI:depo',
        ]);

        $rapor = PackageReport::parse($cikti);

        $this->assertSame('dnf', $rapor->manager);
        $this->assertSame(1, $rapor->critical);
        $this->assertSame(2, $rapor->normal);
        $this->assertContains('python3-cloud-what-a-very-long-package-name', array_column($rapor->packages, 'name'));
        $this->assertNotContains('grub2-tools', array_column($rapor->packages, 'name'));
        $this->assertTrue($rapor->repoWarning);
        $this->assertFalse($rapor->rebootRequired);
    }

    #[Test]
    public function paket_yoneticisi_yoksa_bos_rapor(): void
    {
        $rapor = PackageReport::parse("EYS-PM:yok\n");

        $this->assertNull($rapor->manager);
        $this->assertSame([], $rapor->packages);
    }

    /** Uçbirim yankısı ve isteğe bağlı metin paket sanılmaz: yalnızca liste bölümü okunur. */
    #[Test]
    public function liste_bolumu_disindaki_satirlar_okunmaz(): void
    {
        $rapor = PackageReport::parse(implode("\n", [
            'evil/noble-security 1 amd64 [upgradable from: 0]',
            'EYS-PM:apt', 'EYS-BOLUM:liste', 'EYS-BOLUM:son',
            'other/noble-security 1 amd64 [upgradable from: 0]',
        ]));

        $this->assertSame(0, $rapor->critical + $rapor->normal);
    }

    #[Test]
    public function denetim_betigi_hicbir_sey_kurmaz(): void
    {
        $betik = OsUpdateScripts::denetim();

        foreach (['apt-get -y', 'apt-get install', 'dist-upgrade', 'full-upgrade', 'dnf -y', 'yum -y'] as $yasak) {
            $this->assertStringNotContainsString($yasak, $betik, "denetim betiğinde '{$yasak}' olmamalı");
        }

        /* "upgrade" yalnızca listede ya da SİMÜLASYONDA (-s ilk bayrak) geçer. */
        foreach (preg_split('/\n/', $betik) ?: [] as $satir) {
            if (preg_match('/\supgrade(\s|$)/', $satir) === 1) {
                $this->assertMatchesRegularExpression('/^\s*apt-get -s\s/', $satir, 'kuran upgrade: '.$satir);
            }
        }

        /* /var/run/reboot-required yalnızca OKUNUR; komut olarak reboot/shutdown yok. */
        $this->assertDoesNotMatchRegularExpression('/(^|[;&|\s])(reboot|shutdown|systemctl)(\s|;|$)/m', $betik);
    }

    #[Test]
    public function kurulum_oturumdan_bagimsiz_baslar_ve_yeniden_baslatmaz(): void
    {
        $kritik = OsUpdateScripts::kurulum(OsUpdateScripts::KRITIK);
        $tumu = OsUpdateScripts::kurulum(OsUpdateScripts::TUMU);

        foreach ([$kritik, $tumu] as $betik) {
            $this->assertStringStartsWith('setsid sh -c ', $betik);
            $this->assertStringContainsString('& wait $!; rc=$?', $betik);
            $this->assertStringEndsWith('; true', $betik);
            $this->assertStringContainsString('--force-confold', $betik);
            $this->assertDoesNotMatchRegularExpression('/(^|[;&|\s])(reboot|shutdown)(\s|;|$)/m', $betik);
        }

        $this->assertStringContainsString('--only-upgrade', $kritik);
        $this->assertStringContainsString('upgrade --security', $kritik);
        $this->assertStringContainsString('upgrade --with-new-pkgs', $tumu);
        $this->assertStringNotContainsString('--security', $tumu);
    }

    /** Başarısız kurulumun nedeni panele gelir: kod ve günlüğün son satırları. */
    #[Test]
    public function kurulum_sonucu_kodu_ve_gunluk_satirlarini_okur(): void
    {
        $basarisiz = "dgn parola yankisi\r\nEYS-SONUC:1\r\nEYS-GUNLUK:bas\r\nError: Transaction test error:\r\n  file /usr/lib conflicts\r\n\r\nEYS-GUNLUK:son\r\n";

        $this->assertSame([1, ['Error: Transaction test error:', 'file /usr/lib conflicts']], OsUpdateScripts::kurulumSonucu($basarisiz));
        $this->assertSame([0, []], OsUpdateScripts::kurulumSonucu("EYS-SONUC:0\n"));
        $this->assertSame([null, []], OsUpdateScripts::kurulumSonucu('bağlantı yarıda'));

        $this->assertNull(OsUpdater::kurulumHatasi(0, []));
        $this->assertSame('Kurulum hatayla bitti (kod 1). Error: x · y', OsUpdater::kurulumHatasi(1, ['Error: x', 'y']));
        $this->assertStringStartsWith('Kurulumun sonucu okunamadı', OsUpdater::kurulumHatasi(null, []));

        $uzun = OsUpdater::kurulumHatasi(1, [str_repeat('a', 200), str_repeat('b', 200), 'Error: son satır']);
        $this->assertLessThanOrEqual(290, mb_strlen($uzun));
        $this->assertStringEndsWith('Error: son satır', $uzun);
    }

    #[Test]
    public function tanimsiz_mod_betige_girmez(): void
    {
        $this->expectException(InvalidArgumentException::class);

        OsUpdateScripts::kurulum('tumu; reboot');
    }
}
