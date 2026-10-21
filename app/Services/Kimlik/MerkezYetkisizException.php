<?php

declare(strict_types=1);

namespace App\Services\Kimlik;

use RuntimeException;

/**
 * Merkez "bu kişi bu uygulamaya giremez" dedi.
 *
 * "Merkez cevap vermedi" ile "senin yetkin yok" AYRI ŞEYLERDİR ve ayrı
 * karşılanmalı: birincisinde kullanıcı kendi parolasıyla girişe düşer,
 * ikincisinde kapı kapalıdır ve parola da açmaz. Tek bir genel hata
 * fırlatılsaydı iki durum aynı mesajı verir ve kimse hangisi olduğunu
 * bilemezdi.
 */
class MerkezYetkisizException extends RuntimeException {}
