<?php

declare(strict_types=1);

namespace App\Services\Rotation\Contracts;

/**
 * Cihazın parola uzunluğuna ÜST sınır koyduğunu bildiren sürücü.
 *
 * Genelde uzun parola iyidir ve tek ayar yeter; ama bazı platformlar üst
 * sınır koyuyor ve sınırı aşan parolayı reddediyor. Örnek: vCenter 8.0.3'ün
 * varsayılan SSO politikası 8-20 karakter kabul eder. Panelin varsayılanı bunun
 * üstüne çıkarsa tur "değiştiremedim" diye kapanırdı ve neden ekranda
 * görünmezdi.
 *
 * Ayrı bir arayüz olmasının nedeni: sürücülerin çoğunda böyle bir
 * sınır yok; sözleşmeye zorunlu metot eklemek hepsine anlamsız bir gövde
 * yazdırmak olurdu.
 */
interface LimitsPasswordLength
{
    /** Cihazın kabul ettiği en uzun parola. */
    public function maxPasswordLength(): int;
}
