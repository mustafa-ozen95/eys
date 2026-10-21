<?php

declare(strict_types=1);

namespace App\Http\Requests\Role;

class UpdateRoleRequest extends RoleRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('role'));
    }
}
