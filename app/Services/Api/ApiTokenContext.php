<?php

declare(strict_types=1);

namespace App\Services\Api;

use App\Models\ApiToken;

/**
 * İsteği yapan API jetonu.
 *
 * Yetki daraltması (bkz. AppServiceProvider'daki Gate::before) hangi jetonla
 * gelindiğini bilmek zorunda. Oturumla gelen isteklerde boştur ve hiçbir
 * daraltma yapılmaz.
 *
 * İstek başına tektir; kapsayıcıda singleton olarak durur.
 */
class ApiTokenContext
{
    private ?ApiToken $token = null;

    public function set(ApiToken $token): void
    {
        $this->token = $token;
    }

    public function get(): ?ApiToken
    {
        return $this->token;
    }

    public function present(): bool
    {
        return $this->token !== null;
    }
}
