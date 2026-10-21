<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Rotation\PasswordGenerator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Üretilen parola.
 *
 * Buradaki asıl mesele SEYREK ARIZA: üretici düz rastgele seçtiği sürece
 * parolaların çoğu katı politikaları geçiyor, arada biri geçmiyordu. Böyle
 * bir hata ancak bazı turlarda ortaya çıkar ve nedeni aranırken cihaz suçlu
 * sanılır. Bu yüzden garanti testle kilitlenir.
 */
class PasswordGeneratorTest extends TestCase
{
    #[Test]
    public function her_sinifdan_en_az_bir_karakter_bulunur(): void
    {
        $generator = new PasswordGenerator;

        /* Tek bir üretim şanslı olabilir; garanti her seferinde geçerli. */
        for ($i = 0; $i < 200; $i++) {
            $password = $generator->generate(12);

            $this->assertMatchesRegularExpression('/[A-Z]/', $password, 'Büyük harf yok.');
            $this->assertMatchesRegularExpression('/[a-z]/', $password, 'Küçük harf yok.');
            $this->assertMatchesRegularExpression('/[0-9]/', $password, 'Rakam yok.');
            $this->assertMatchesRegularExpression('/[^A-Za-z0-9]/', $password, 'Özel karakter yok.');
        }
    }

    #[Test]
    public function istenen_uzunluk_birebir_uretilir(): void
    {
        $generator = new PasswordGenerator;

        /* vCenter SSO en fazla 20 karakter kabul ediyor; taşan parola reddedilir. */
        foreach ([8, 12, 20, 24] as $length) {
            $this->assertSame($length, strlen($generator->generate($length)));
        }
    }

    /** Alfabe dışına çıkılmaz: karışan karakterler bilerek dışarıda. */
    #[Test]
    public function alfabe_disinda_karakter_uretilmez(): void
    {
        $alphabet = (string) config('rotation.password_alphabet');

        $password = (new PasswordGenerator)->generate(40);

        foreach (str_split($password) as $char) {
            $this->assertStringContainsString($char, $alphabet, "Alfabede olmayan karakter: {$char}");
        }
    }

    /**
     * Sınıf sayısından kısa parola istenirse üretim DURMAZ.
     *
     * Garanti verilemez ama uzunluk yine de doğru olmalı; burada patlamak
     * turu hiç başlatmamak olurdu.
     */
    #[Test]
    public function cok_kisa_uzunlukta_bile_uretim_yapilir(): void
    {
        $this->assertSame(2, strlen((new PasswordGenerator)->generate(2)));
    }
}
