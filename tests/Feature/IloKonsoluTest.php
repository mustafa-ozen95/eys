<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\CihazArayuzu\ArayuzTuru;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * iLO'NUN HTML5 KONSOL DÜĞMESİ.
 *
 * Düğme `iLO.startHtml5Irc()` çağırıyor, o da konsolu bir ÇERÇEVEDE arıyor:
 *
 *     iLOGlobal.topPage.appFrame.window.startHtml5Irc()
 *
 * Çerçevede o işlev yoksa çağrı hata veriyor ve hata `catch` içinde yutuluyor.
 * Belirtisi hiçbir şey: uyarı bile çıkmıyor, düğme tepkisiz kalıyor
 * (adresi `javascript:void(0)`).
 *
 * Konsolun kendi sayfası cihazda tek başına açılabiliyor ve oturum anahtarını
 * çerezden okuyabiliyor; panel o çerezi zaten indiriyor. Düğme oraya bağlanır.
 */
final class IloKonsoluTest extends TestCase
{
    private function betik(): string
    {
        return view('credentials.arayuz.ayrilis', [
            'ayrilisAdresi' => '/cihaz-oturum/ayril',
            'iloKonsolu' => true,
        ])->render();
    }

    /**
     * BETİK ORTAK AÇILIŞ BETİĞİNE EKLENİR, AYRI BİR AÇILIŞ GÖRÜNÜMÜ OLARAK DEĞİL.
     *
     * acilisGorunumu() dolu olsaydı arayüzün sayfasını PANEL verirdi; o kipte
     * sayfa /cihaz-oturum/sayfa adresinde durur ve iLO'nun göreli istekleri
     * panele düşer ("html/application.html" → panelin 404 sayfası, arayüz
     * hiç açılmaz).
     */
    #[Test]
    public function betik_kipi_degistirmeden_eklenir(): void
    {
        $this->assertNull(ArayuzTuru::Ilo->acilisGorunumu(), 'iLO açılış görünümü almamalı: o kipte sayfayı panel verir ve göreli yollar kırılır.');
        $this->assertFileExists(resource_path('views/credentials/arayuz/ilo.blade.php'));
    }

    /**
     * DÜĞME CİHAZIN KENDİ KONSOL SAYFASINA BAĞLANIR.
     *
     * Yeni bir konsol yazılmıyor; cihazın desteklediği yol kullanılıyor —
     * `/irc.html`, oturum anahtarı adreste ya da çerezde.
     */
    #[Test]
    public function dugme_cihazin_konsol_sayfasina_baglanir(): void
    {
        $betik = $this->betik();

        $this->assertStringContainsString('startHtml5Irc', $betik);
        $this->assertStringContainsString('/irc.html', $betik);
        $this->assertStringContainsString('getSessionKey', $betik);

        /* Ayrı pencere değil sekme: boyut verilmiyor. */
        $this->assertStringContainsString("'_blank'", $betik);
        $this->assertStringNotContainsString('width=', $betik);
    }

    /**
     * KONSOLUN YOLLARI iLO'YA AİT.
     *
     * Çerez başka bir arayüzü gösterse bile konsolun istekleri iLO oturumuna
     * gider: `/wss/ircport` isteği başka cihaza düşerse 400 alır; iLO'nun
     * kendisi aynı el sıkışmaya 101 veriyor.
     */
    #[Test]
    public function konsol_yollari_iloya_aittir(): void
    {
        $this->assertSame([ArayuzTuru::Ilo], ArayuzTuru::yolunSahipleri('/irc.html'));
        $this->assertSame([ArayuzTuru::Ilo], ArayuzTuru::yolunSahipleri('/wss/ircport'));
    }
}
