<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Rotation\Contracts\LimitsPasswordLength;

/**
 * Parola uzunluğuna üst sınır koyan sahte makine.
 *
 * Gerçek örneği vCenter SSO: 20 karakterden uzun parolayı reddediyor.
 * Sınırı görmezden gelen bir tur, cihazın "politika" hatasıyla düşerdi.
 */
class FakeLimitedPasswordDriver extends FakePasswordDriver implements LimitsPasswordLength
{
    public static int $limit = 20;

    public function maxPasswordLength(): int
    {
        return self::$limit;
    }
}
