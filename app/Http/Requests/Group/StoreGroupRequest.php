<?php

declare(strict_types=1);

namespace App\Http\Requests\Group;

use App\Models\CredentialGroup;

class StoreGroupRequest extends GroupRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', CredentialGroup::class);
    }
}
