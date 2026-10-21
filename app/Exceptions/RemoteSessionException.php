<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Uzak oturum açılamadığında fırlatılır.
 *
 * Kullanıcıya anlaşılır bir mesaj döner; ayrıntı loglarda kalır.
 */
class RemoteSessionException extends RuntimeException
{
    public function render(): Response
    {
        return response()->json(['message' => $this->getMessage()], Response::HTTP_CONFLICT);
    }
}
