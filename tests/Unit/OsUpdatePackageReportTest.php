<?php

declare(strict_types=1);

namespace Tests\Unit;

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

        foreach (['apt-get -y', 'apt-get install', ' upgrade', 'dnf -y', 'yum -y'] as $yasak) {
            $this->assertStringNotContainsString($yasak, $betik, "denetim betiğinde '{$yasak}' olmamalı");
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
            $this->assertStringEndsWith('& wait $!', $betik);
            $this->assertStringContainsString('--force-confold', $betik);
            $this->assertDoesNotMatchRegularExpression('/(^|[;&|\s])(reboot|shutdown)(\s|;|$)/m', $betik);
        }

        $this->assertStringContainsString('--only-upgrade', $kritik);
        $this->assertStringContainsString('upgrade --security', $kritik);
        $this->assertStringContainsString('upgrade --with-new-pkgs', $tumu);
        $this->assertStringNotContainsString('--security', $tumu);
    }

    #[Test]
    public function tanimsiz_mod_betige_girmez(): void
    {
        $this->expectException(InvalidArgumentException::class);

        OsUpdateScripts::kurulum('tumu; reboot');
    }
}
