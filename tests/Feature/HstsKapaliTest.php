<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * HSTS KAPALI OLMALI — ve `max-age=0` ile gönderilmeli.
 *
 * Başlık tarayıcıya "bu adrese bir daha asla http ile gitme" der ve bunu
 * kendi içinde saklar. Sertifika düşerse (yenileme elle yapılıyorsa bu
 * olasıdır) tarayıcı uyarı ekranını bile göstermiyor: "yine de devam et"
 * düğmesi HSTS'li adreslerde yoktur ve panele hiçbir şekilde girilemez.
 *
 * BAŞLIĞI SİLMEK YETMEZ: tarayıcıda yerleşmiş kayıt bir yıl daha yaşar.
 * `max-age=0` onu sildirir — bu yüzden başlık duruyor ama sıfır.
 */
class HstsKapaliTest extends TestCase
{
    public function test_hsts_sifir_gonderilir(): void
    {
        $yanit = $this->get(route('login'));

        $yanit->assertOk();
        $yanit->assertHeader('Strict-Transport-Security', 'max-age=0');
    }

    /** Ayar dosyasında da bir yıllık bir değer kalmamalı. */
    public function test_yapilandirmada_uzun_omur_yok(): void
    {
        $this->assertSame('max-age=0', config('security.headers.Strict-Transport-Security'));
    }
}
