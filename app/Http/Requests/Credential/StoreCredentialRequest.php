<?php

declare(strict_types=1);

namespace App\Http\Requests\Credential;

use App\Models\Credential;

class StoreCredentialRequest extends CredentialRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Credential::class);
    }
}
