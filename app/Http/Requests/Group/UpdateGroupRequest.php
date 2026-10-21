<?php

declare(strict_types=1);

namespace App\Http\Requests\Group;

class UpdateGroupRequest extends GroupRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('group'));
    }
}
