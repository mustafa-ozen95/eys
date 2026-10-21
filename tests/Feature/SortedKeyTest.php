<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * SIRALI BİRİNCİL ANAHTAR DIŞARI ÇIKMAZ.
 *
 * Adres çubuğundaki, form değerindeki ya da DOM kimliğindeki 1, 2, 3 hem kaç
 * kaydınız olduğunu söyler hem de komşu kaydı denemeye davet eder. Kural
 * CLAUDE.md'de yazılı ama tek bir unutulmuş `->id` ile sessizce delinebilir.
 *
 * Bu yüzden kural KAYNAKTA sınanır: görünümler model anahtarı basamaz.
 * Bilinçli bir istisna gerekirse satırın sonuna "// sirali-anahtar-istisna"
 * yazılır ve gerekçesi yorumda durur.
 */
final class SortedKeyTest extends TestCase
{
    public function test_gorunumler_sirali_anahtar_basmaz(): void
    {
        $bulunan = [];

        foreach ($this->gorunumler() as $dosya) {
            foreach (file($dosya) ?: [] as $no => $satir) {
                if (preg_match('/->id\b(?!entity)/', $satir) !== 1) {
                    continue;
                }

                /* Oturumdaki kişinin kendi kimliği karşılaştırmadır, çıktı değil. */
                if (str_contains($satir, 'user()->id') || str_contains($satir, 'auth()->id')) {
                    continue;
                }

                if (str_contains($satir, 'sirali-anahtar-istisna')) {
                    continue;
                }

                $bulunan[] = str_replace(base_path().'/', '', $dosya).':'.($no + 1);
            }
        }

        $this->assertSame([], $bulunan, 'Görünümde model anahtarı basılıyor; uuid kullanın.');
    }

    /** @return array<int, string> */
    private function gorunumler(): array
    {
        $dosyalar = [];

        $gezgin = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views'), \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($gezgin as $dosya) {
            if (str_ends_with($dosya->getFilename(), '.blade.php')) {
                $dosyalar[] = $dosya->getPathname();
            }
        }

        return $dosyalar;
    }
}
