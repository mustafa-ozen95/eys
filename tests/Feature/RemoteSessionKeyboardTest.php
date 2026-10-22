<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UZAK OTURUM ÇERÇEVESİ ODAK ALABİLMELİ.
 *
 * Guacamole ekranda fareye basıldığında `preventDefault()` çağırıyor (metin
 * seçilmesin diye) ve tarayıcıda mousedown'da preventDefault ODAK DEĞİŞİMİNİ
 * DE engeller. Guacamole kendi sayfasında açıldığında belge zaten odaktadır
 * ve bu görünmez; iframe içinde odak ana sayfada kalır ve tuşlar uzak
 * makineye hiç ulaşmaz.
 *
 * Odağı ana sayfa veriyor ve tutamağı bu işaret (bkz. remote-session.js).
 * Sınama işaretin düşmediğini kilitler: düşerse klavye sessizce ölür ve
 * sebebi ekranda görünmez.
 */
class RemoteSessionKeyboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_oturum_cercevesi_odak_icin_isaretli(): void
    {
        $kaynak = file_get_contents(resource_path('views/remote/session.blade.php'));

        $this->assertStringContainsString('data-session-frame', (string) $kaynak);
    }

    /**
     * KONSOL SEKMEDE AÇILIR.
     *
     * Ayrı ve sabit boyutlu bir pencere çözünürlüğü bozar: uzak masaüstünün
     * ölçüsünü pencere belirliyor ve küçük bir pencerede geniş bir masaüstü
     * küçülüp görev çubuğu okunmaz olur.
     *
     * Sekmede o sorun yok: sekmenin görünen alanı zaten pencerenin tamamı.
     * Kazancı, arayüzlerle birlikte her şeyin tek pencerede toplanması.
     *
     * ÖLÇÜ VERİLMEMELİ: özellik listesi verilen her çağrıyı tarayıcı açılır
     * pencere sayıyor.
     */
    public function test_konsol_sekmede_acilir(): void
    {
        $betik = (string) file_get_contents(resource_path('js/components/remote-session.js'));

        $this->assertStringContainsString('window.open(session.url, `session-${session.token}`)', $betik);

        $this->assertStringNotContainsString('width=', $betik);
        $this->assertStringNotContainsString('pencereOlculeri', $betik);
        $this->assertStringNotContainsString('availWidth', $betik);
    }

    public function test_betik_cerceveyi_odakliyor(): void
    {
        $betik = (string) file_get_contents(resource_path('js/components/remote-session.js'));

        $this->assertStringContainsString('[data-session-frame]', $betik);
        $this->assertStringContainsString('contentWindow?.focus()', $betik);

        /* Fare ekrana dönünce de odak gelmeli. */
        $this->assertStringContainsString("'mouseenter'", $betik);
    }

    /**
     * KAPATMA DÜĞMESİ YOK — ÇIKIŞ YİNE DE KAYDA GEÇER.
     *
     * Düğme, sekmenin çarpısının zaten yaptığı işi ikinci kez yapıyordu:
     * kapatma ucuna haber ver, pencereyi kapat. Kapanış iki yoldan haber
     * veriliyor ve ikisi de aynı uca gidiyor — `pagehide` olayındaki
     * sendBeacon ve paneli açan sekmenin `pencere.closed` yoklaması. Uç
     * denetim defterine "Konsol kapatıldı" yazıyor.
     *
     * Sınama düğmenin geri gelmediğini değil, ÇIKIŞ YOLLARININ durduğunu
     * kilitler: düğme dursaydı da olurdu, ama bu iki yol düşerse oturum
     * cihazda açık kalır ve kimse fark etmez.
     */
    public function test_cikis_pencere_kapaninca_haber_verilir(): void
    {
        $betik = (string) file_get_contents(resource_path('js/components/remote-session.js'));

        $this->assertStringContainsString("addEventListener('pagehide'", $betik);
        $this->assertStringContainsString('closeBeacon', $betik);
        $this->assertStringContainsString('popup?.closed', $betik);

        /* Artık olmayan düğmenin dinleyicisi de kalmamalı. */
        $this->assertStringNotContainsString('data-session-close', $betik);

        $sayfa = (string) file_get_contents(resource_path('views/remote/session.blade.php'));
        $this->assertStringNotContainsString('data-session-close', $sayfa);
    }
}
