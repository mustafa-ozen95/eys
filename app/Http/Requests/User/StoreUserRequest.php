<?php

declare(strict_types=1);

namespace App\Http\Requests\User;

use App\Models\User;

class StoreUserRequest extends UserRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', User::class);
    }

    protected function passwordRequired(): bool
    {
        return true;
    }
}
