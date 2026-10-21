<?php

declare(strict_types=1);

namespace App\Http\Requests\Role;

use App\Models\Role;

class StoreRoleRequest extends RoleRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Role::class);
    }
}
