<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * BİLEŞEN ETİKETİNİN İÇİNDE BLADE YÖNERGESİ OLMAZ.
 *
 * `@disabled(...)`, `@required(...)` gibi yönergeler bileşen etiketinden
 * ÖNCE derleniyor. Araya giren `<?php ?>` bloğu yüzünden Blade etiketi artık
 * bileşen olarak tanımıyor ve tarayıcıya HAM `<x-form.select>` gidiyor;
 * tarayıcı bilmediği bu etiketi çizmiyor.
 *
 * Belirti: kullanıcı düzenleme ekranında Rol kutusu ile iki parola
 * kutusu HİÇ görünmüyordu. Etiketleri, ipucu ve doğrulama hatası yerindeydi —
 * yalnızca girdinin kendisi yoktu. "Rol zorunludur" hatası veriliyor ama rolü
 * seçecek kutu ekranda bulunmuyordu.
 *
 * Doğrusu bağlı özniteliktir: `:disabled="$kosul"`. Öznitelik torbası `false`
 * ve `null` değerleri zaten atar, `true` olanı da değersiz öznitelik olarak
 * basar.
 *
 * Bu sınama TEK TEK ekranları değil, KALIBI kilitler: yeni bir ekranda aynı
 * şey yazıldığında burada düşer.
 */
final class BladeBilesenEtiketiTest extends TestCase
{
    /** Bileşen etiketi içinde aranmayacak yönergeler. */
    private const YONERGELER = ['disabled', 'required', 'checked', 'readonly', 'selected'];

    #[Test]
    public function bilesen_etiketlerinde_blade_yonergesi_kullanilmaz(): void
    {
        $bulunan = [];

        foreach ($this->blades() as $dosya) {
            foreach ($this->ihlaller($dosya) as $satir => $yonerge) {
                $bulunan[] = str_replace(resource_path('views').'/', '', $dosya).":{$satir} → @{$yonerge}";
            }
        }

        $this->assertSame([], $bulunan, implode("\n", array_merge(
            ['Bileşen etiketi içinde Blade yönergesi var; bu etiket ham HTML olarak çıkar ve ekranda hiç görünmez.'],
            ['Bağlı özniteliğe çevirin: @disabled($k) yerine :disabled="$k"'],
            $bulunan,
        )));
    }

    /** @return list<string> */
    private function blades(): array
    {
        $dosyalar = [];

        /** @var \SplFileInfo $dosya */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views'))) as $dosya) {
            if ($dosya->isFile() && str_ends_with($dosya->getFilename(), '.blade.php')) {
                $dosyalar[] = $dosya->getPathname();
            }
        }

        sort($dosyalar);

        return $dosyalar;
    }

    /**
     * Açık bir `<x-...>` etiketinin içinde kalan yönergeler.
     *
     * Etiketin nerede bittiğini ararken TIRNAK İÇİ yok sayılır: öznitelik
     * değerlerinde `?->` gibi `>` içeren ifadeler var ve onlar etiketi
     * kapatmıyor.
     *
     * @return array<int, string> satır => yönerge
     */
    private function ihlaller(string $dosya): array
    {
        $desen = '/@('.implode('|', self::YONERGELER).')\s*\(/';
        $bulunan = [];
        $icinde = false;

        foreach (explode("\n", (string) file_get_contents($dosya)) as $no => $satir) {
            $kalan = $satir;

            if (! $icinde) {
                if (preg_match('/<x-[\w.:-]+/', $satir, $e, PREG_OFFSET_CAPTURE) !== 1) {
                    continue;
                }

                $kalan = substr($satir, $e[0][1] + strlen($e[0][0]));
                $icinde = ! str_contains($this->tirnaksiz($kalan), '>');
            }

            if (preg_match($desen, $kalan, $y) === 1) {
                $bulunan[$no + 1] = $y[1];
            }

            if ($icinde && str_contains($this->tirnaksiz($kalan), '>')) {
                $icinde = false;
            }
        }

        return $bulunan;
    }

    private function tirnaksiz(string $metin): string
    {
        return (string) preg_replace('/"[^"]*"|\'[^\']*\'/', '', $metin);
    }
}
