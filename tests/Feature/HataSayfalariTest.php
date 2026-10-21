<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * HATA SAYFALARI — 403 ÖTEKİLERDEN AYRI DURUR.
 *
 * 404 "burada bir şey yok" der; 403 "burada bir şey var ama senin değil" der.
 * İkisi aynı sade kalıpla gösterilince yetki reddi sıradan bir hata gibi
 * görünür; bu yüzden 403 kendi tasarımıyla "yetkisiz erişim denemesi" olarak
 * gösterilir.
 *
 * Sayfa denenen YOLU da yazar: kullanıcı yanlış bağlantıya tıkladıysa
 * hangisi olduğunu görsün ve yöneticiye söyleyebilsin.
 */
final class HataSayfalariTest extends TestCase
{
    private function sayfa(int $durum): string
    {
        return view('errors.'.$durum, ['exception' => new \Exception])->render();
    }

    /** 403 yetkisiz erişim denemesi olarak okunur. */
    #[Test]
    public function yetkisiz_erisim_sayfasi_ayirt_edilir(): void
    {
        $sayfa = $this->sayfa(403);

        $this->assertStringContainsString('Yetkisiz erişim denemesi', $sayfa);
        $this->assertStringContainsString('gate__mark--uyari', $sayfa);
        $this->assertStringContainsString('Panele dön', $sayfa);

        /* 404'ün cümlesi buraya sızmamalı: ikisi ayrı şey söylüyor. */
        $this->assertStringNotContainsString('Aradığınız sayfa bulunamadı', $sayfa);
    }

    /** Biçem sınıfının karşılığı biçem dosyasında var. */
    #[Test]
    public function uyari_bicemi_tanimlidir(): void
    {
        $bicem = file_get_contents(resource_path('css/layout.css'));

        $this->assertStringContainsString('.gate__mark--uyari', $bicem);
        $this->assertStringContainsString('.gate__yol', $bicem);
    }

    /** 404 sade kalır: değişiklik yalnızca 403'e ait. */
    #[Test]
    public function bulunamadi_sayfasi_sade_kalir(): void
    {
        $sayfa = $this->sayfa(404);

        $this->assertStringContainsString('Aradığınız sayfa bulunamadı', $sayfa);
        $this->assertStringNotContainsString('gate__mark--uyari', $sayfa);
    }
}
