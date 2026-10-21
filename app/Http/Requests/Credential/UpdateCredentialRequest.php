<?php

declare(strict_types=1);

namespace App\Http\Requests\Credential;

class UpdateCredentialRequest extends CredentialRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('credential'));
    }
}
