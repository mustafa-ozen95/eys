<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Pagination\LengthAwarePaginator;
use Tests\TestCase;

/**
 * SAYFALAMA PANELİN KENDİ ŞERİDİYLE ÇİZİLİR.
 *
 * Şerit (resources/views/vendor/pagination/default.blade.php) ve biçemi
 * (components/pagination.css) depoda durur; çerçeveye hangi görünümü
 * kullanacağı da açıkça söylenmeli. Söylenmezse Laravel'in varsayılanı
 * `pagination::tailwind` kullanılır; bu panellerde Tailwind yok, dolayısıyla
 * şablonun içindeki SVG oklar hiçbir boyut sınırı olmadan SAYFA BOYUNDA
 * çizilir.
 */
class PaginationBarTest extends TestCase
{
    public function test_sayfalama_panelin_kendi_seridiyle_cizilir(): void
    {
        $sayfalayici = new LengthAwarePaginator(range(1, 5), 100, 5, 2, ['path' => '/x']);

        $html = (string) $sayfalayici->links();

        $this->assertStringContainsString('pagination__link', $html);
    }

    /** Tailwind şablonunun izi: boyutu sınırlanmamış SVG oklar. */
    public function test_tailwind_sablonu_kullanilmaz(): void
    {
        $sayfalayici = new LengthAwarePaginator(range(1, 5), 100, 5, 2, ['path' => '/x']);

        $this->assertStringNotContainsString('<svg', (string) $sayfalayici->links());
    }
}
