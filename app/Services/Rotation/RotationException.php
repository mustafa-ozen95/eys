<?php

declare(strict_types=1);

namespace App\Services\Rotation;

use RuntimeException;

/**
 * Döndürme sırasında cihazdan gelen hata.
 *
 * Mesajı kullanıcıya gösterilir; bu yüzden içinde PAROLA GEÇMEZ. Sürücüler
 * cihaz çıktısını doğrudan mesaja koymaz, özetler.
 */
class RotationException extends RuntimeException {}
