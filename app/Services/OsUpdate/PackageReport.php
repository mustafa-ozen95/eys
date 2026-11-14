<?php

declare(strict_types=1);

namespace App\Services\OsUpdate;

/**
 * Denetim çıktısından bekleyen paketler.
 *
 * KRİTİK = güvenlik güncellemesi:
 *   apt — paketin geldiği dağıtım kolu "-security" ile biter
 *         (noble-security, bookworm-security; Mint de Ubuntu'nunkini kullanır).
 *   dnf — paket bir güvenlik bildirisine (…/Sec.) bağlıdır.
 *
 * Yalnızca tanınan satırlar okunur; geri kalan her şey (uçbirim yankısı,
 * uyarılar) yok sayılır. Paket listesi en çok LIMIT satır saklanır.
 */
final class PackageReport
{
    public const LIMIT = 500;

    /**
     * @param  list<array{name: string, version: string, critical: bool}>  $packages
     */
    private function __construct(
        public readonly ?string $manager,
        public readonly array $packages,
        public readonly int $critical,
        public readonly int $normal,
        public readonly bool $rebootRequired,
        public readonly bool $repoWarning,
        public readonly ?string $os = null,
    ) {}

    public static function parse(string $cikti): self
    {
        $satirlar = preg_split('/\r\n|\r|\n/', self::temizle($cikti)) ?: [];
        $yonetici = null;
        $isletim = null;
        $bolum = null;
        $liste = [];
        $guvenlik = [];

        foreach ($satirlar as $satir) {
            $satir = trim($satir);

            if (preg_match('/^EYS-PM:(apt|dnf|yum|yok)$/', $satir, $m) === 1) {
                $yonetici = $m[1] === 'yok' ? null : $m[1];

                continue;
            }

            if (preg_match('/^EYS-OS:(.+)$/', $satir, $m) === 1) {
                $isletim = mb_substr(trim($m[1]), 0, 100) ?: null;

                continue;
            }

            if (preg_match('/^EYS-BOLUM:(liste|guvenlik|son)$/', $satir, $m) === 1) {
                $bolum = $m[1];

                continue;
            }

            if ($bolum === 'liste') {
                $liste[] = $satir;
            } elseif ($bolum === 'guvenlik') {
                $guvenlik[] = $satir;
            }
        }

        $paketler = match ($yonetici) {
            'apt' => self::apt($liste),
            'dnf', 'yum' => self::dnf($liste, $guvenlik),
            default => [],
        };

        usort($paketler, fn (array $a, array $b): int => [$b['critical'], $a['name']] <=> [$a['critical'], $b['name']]);

        $kritik = count(array_filter($paketler, fn (array $p): bool => $p['critical']));

        return new self(
            $yonetici,
            array_slice($paketler, 0, self::LIMIT),
            $kritik,
            count($paketler) - $kritik,
            preg_match('/^EYS-REBOOT\s*$/m', $cikti) === 1,
            preg_match('/^EYS-UYARI:depo\s*$/m', $cikti) === 1,
            $isletim,
        );
    }

    /**
     * `apt list --upgradable`:
     *   openssl/noble-updates,noble-security 3.0.13-0ubuntu3.5 amd64 [upgradable from: 3.0.13-0ubuntu3.4]
     *
     * @param  list<string>  $satirlar
     * @return list<array{name: string, version: string, critical: bool}>
     */
    private static function apt(array $satirlar): array
    {
        $paketler = [];

        foreach ($satirlar as $satir) {
            if (preg_match('#^([a-z0-9][a-z0-9.+-]*)/(\S+)\s+(\S+)\s+\S+\s+\[upgradable from:#i', $satir, $m) !== 1) {
                continue;
            }

            $paketler[$m[1]] = [
                'name' => $m[1],
                'version' => $m[3],
                'critical' => preg_match('/(^|,)[^,]*-security(,|$)/', $m[2]) === 1,
            ];
        }

        return array_values($paketler);
    }

    /**
     * `dnf -q check-update`:  openssl.x86_64   1:3.0.7-28.el9   baseos
     * `dnf -q updateinfo list --security`:  RHSA-2024:1234 Important/Sec. openssl-1:3.0.7-28.el9.x86_64
     *
     * Uzun paket adı dnf'te satırı ikiye bölebiliyor (ad tek başına, sürüm
     * alt satırda); o hâlde iki satır birleştirilir.
     *
     * @param  list<string>  $liste
     * @param  list<string>  $guvenlik
     * @return list<array{name: string, version: string, critical: bool}>
     */
    private static function dnf(array $liste, array $guvenlik): array
    {
        $guvenlikli = [];

        foreach ($guvenlik as $satir) {
            if (preg_match('#^\S+\s+\S*/Sec\.\s+(\S+)$#', $satir, $m) === 1) {
                /* ad-[epoch:]sürüm-sürüm.mimari → ad */
                $ad = preg_replace('/-(\d+:)?[^-]+-[^-]+$/', '', $m[1]);
                $guvenlikli[(string) $ad] = true;
            }
        }

        $paketler = [];
        $yarim = '';

        foreach ($liste as $satir) {
            if (str_starts_with($satir, 'Obsoleting') || str_starts_with($satir, 'Security:')) {
                break;
            }

            $satir = trim($yarim.' '.$satir);
            $parcalar = preg_split('/\s+/', $satir) ?: [];

            if (count($parcalar) === 1 && $parcalar[0] !== '') {
                $yarim = $satir;

                continue;
            }

            $yarim = '';

            if (count($parcalar) < 3 || preg_match('/^(.+)\.([A-Za-z0-9_]+)$/', $parcalar[0], $m) !== 1) {
                continue;
            }

            $paketler[$m[1]] = [
                'name' => $m[1],
                'version' => $parcalar[1],
                'critical' => isset($guvenlikli[$m[1]]),
            ];
        }

        return array_values($paketler);
    }

    /**
     * Uçbirim kodlarını atar. yum/dnf bazı makinelerde güncellenecek paketin
     * adını kalın basıyor (ESC[1m … ESC[m ve SI): kod adın ve sürümün parçası
     * sanılıp ekrana kare kare yazılıyordu; güvenlik bildirisiyle de
     * eşleşmediği için kritik paket normal sayılıyordu. Betik TERM=dumb
     * veriyor; bu temizlik ona uymayan araç için ikinci kat.
     */
    private static function temizle(string $cikti): string
    {
        $cikti = preg_replace('/\e\[[0-9;?]*[ -\/]*[@-~]|\e[()][0-9A-Za-z]|\e./', '', $cikti) ?? $cikti;

        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $cikti) ?? $cikti;
    }
}
