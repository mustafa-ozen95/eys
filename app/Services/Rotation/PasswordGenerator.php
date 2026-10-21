<?php

declare(strict_types=1);

namespace App\Services\Rotation;

/**
 * Döndürmede kullanılan parolayı üretir.
 *
 * Karışabilecek karakterler (0/O, 1/l/I) alfabede yoktur: bu parolalar bazen
 * konsola elle yazılıyor.
 *
 * HER SINIFTAN EN AZ BİR KARAKTER GARANTİDİR. Alfabeden düz rastgele seçilen
 * parola kimi zaman büyük harf ya da özel karakter içermez; katı politikası
 * olan cihazlar onu reddeder. Örnek: vCenter 8.0.3'ün varsayılan SSO
 * politikası en az 1 büyük, 1 küçük, 1 rakam, 1 özel karakter ve 8-20
 * karakter ister. Arıza SEYREK olur — parolanın rastgeleliğine bağlıdır —
 * yani ancak bazı turlar patlar; en kötü hata
 * türü budur.
 */
class PasswordGenerator
{
    /**
     * Politikaların ayrı ayrı saydığı sınıflar.
     *
     * Alfabeyle KESİŞTİRİLİR: yapılandırma bir sınıfı tümden çıkarmışsa o
     * sınıf zorunlu tutulmaz, yoksa üretim hiç bitmezdi.
     */
    private const CLASSES = [
        'ABCDEFGHIJKLMNOPQRSTUVWXYZ',
        'abcdefghijklmnopqrstuvwxyz',
        '0123456789',
        '!#$%&*+-.:=?@_~',
    ];

    public function generate(?int $length = null): string
    {
        $alphabet = (string) config('rotation.password_alphabet');
        $length = $length ?? (int) config('rotation.password_length', 24);

        $required = $this->required($alphabet);

        /* İstenen uzunluk sınıf sayısına yetmiyorsa garanti verilemez. */
        $characters = count($required) > $length
            ? []
            : array_map(fn (string $set): string => $this->pick($set), $required);

        for ($i = count($characters); $i < $length; $i++) {
            $characters[] = $this->pick($alphabet);
        }

        return $this->shuffle($characters);
    }

    /**
     * Alfabede gerçekten bulunan sınıflar.
     *
     * @return array<int, string>
     */
    private function required(string $alphabet): array
    {
        $sets = array_map(
            fn (string $class): string => $this->intersect($class, $alphabet),
            self::CLASSES,
        );

        return array_values(array_filter($sets, fn (string $set): bool => $set !== ''));
    }

    private function intersect(string $class, string $alphabet): string
    {
        return implode('', array_filter(
            str_split($class),
            fn (string $char): bool => str_contains($alphabet, $char),
        ));
    }

    private function pick(string $set): string
    {
        return $set[random_int(0, strlen($set) - 1)];
    }

    /**
     * Fisher-Yates, random_int ile.
     *
     * str_shuffle KULLANILMAZ: içindeki üreteç kriptografik değildir ve
     * zorunlu karakterlerin yeri tahmin edilebilir kalırdı.
     *
     * @param  array<int, string>  $characters
     */
    private function shuffle(array $characters): string
    {
        for ($i = count($characters) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$characters[$i], $characters[$j]] = [$characters[$j], $characters[$i]];
        }

        return implode('', $characters);
    }
}
