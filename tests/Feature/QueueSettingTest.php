<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

/**
 * `retry_after` İLE İŞ ZAMAN AŞIMI ARASINDAKİ İLİŞKİ.
 *
 * `retry_after` işin zaman aşımından kısaysa kuyruk, iş HÂLÂ ÇALIŞIRKEN onu
 * terk edilmiş sayıp ikinci bir işçiye verir ve iki kopya aynı anda koşar.
 * Burada bunun bedeli ağır: parola turu iki kez dönerse cihazdaki parola ile
 * kasadaki kayıt ayrışır.
 *
 * Örnek: `retry_after` 90 saniye, en uzun iş (VerifyActivityChainJob) 900
 * saniye, işçinin sınırı 300 saniye olursa uzun süren her iş üst üste biner.
 *
 * Sınama TEK BİR İŞE bakmıyor: `app/Jobs` altındaki hepsini tarıyor. Yarın
 * daha uzun bir iş eklenirse burası kırılsın, üretimde sessizce çakışmasın.
 */
class QueueSettingTest extends TestCase
{
    /** @return array<int, array{string, int}> */
    private function isler(): array
    {
        $bulunan = [];

        foreach (glob(app_path('Jobs/*.php')) ?: [] as $dosya) {
            $sinif = 'App\\Jobs\\'.basename($dosya, '.php');

            if (! class_exists($sinif)) {
                continue;
            }

            $ozellikler = (new ReflectionClass($sinif))->getDefaultProperties();

            /* Zaman aşımı yazmayan iş, işçinin sınırına tabidir. */
            $bulunan[] = [$sinif, (int) ($ozellikler['timeout'] ?? $this->isciZamanAsimi())];
        }

        return $bulunan;
    }

    private function isciZamanAsimi(): int
    {
        $conf = (string) file_get_contents(base_path('docker/rootfs/etc/supervisor/supervisord.conf'));

        preg_match_all('/queue:work.*--timeout=(\d+)/', $conf, $m);

        return max(array_map('intval', $m[1] ?: ['60']));
    }

    #[Test]
    public function retry_after_en_uzun_isten_uzundur(): void
    {
        $isler = $this->isler();

        $this->assertNotEmpty($isler, 'Hiç iş bulunamadı; sınama boşa koşuyor olabilir.');

        foreach (['database', 'redis', 'beanstalkd'] as $baglanti) {
            $retryAfter = (int) config("queue.connections.{$baglanti}.retry_after");

            foreach ($isler as [$sinif, $zamanAsimi]) {
                $this->assertGreaterThan(
                    $zamanAsimi,
                    $retryAfter,
                    "{$baglanti}: retry_after ({$retryAfter}) {$sinif} zaman aşımından ({$zamanAsimi}) uzun olmalı.",
                );
            }
        }
    }

    /** İŞÇİNİN sınırı da retry_after'ı aşmamalı. */
    #[Test]
    public function isci_zaman_asimi_retry_after_icinde_kalir(): void
    {
        $this->assertLessThanOrEqual(
            (int) config('queue.connections.redis.retry_after'),
            $this->isciZamanAsimi(),
            'İşçi zaman aşımı retry_after içinde kalmalı.',
        );
    }

    /** Ortam değişkeni ayarı AŞAĞI ÇEKEMEZ: elle kısmak sessiz arıza üretirdi. */
    #[Test]
    public function ortam_degiskeni_tabani_kiramaz(): void
    {
        putenv('REDIS_QUEUE_RETRY_AFTER=30');

        $taze = require config_path('queue.php');

        putenv('REDIS_QUEUE_RETRY_AFTER');

        $this->assertGreaterThan(900, (int) $taze['connections']['redis']['retry_after']);
    }
}
