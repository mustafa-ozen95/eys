<?php

declare(strict_types=1);

namespace App\Http\Requests\User;

class UpdateUserRequest extends UserRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('user'));
    }

    protected function passwordRequired(): bool
    {
        return false;
    }
}
