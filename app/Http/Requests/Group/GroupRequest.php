<?php

declare(strict_types=1);

namespace App\Http\Requests\Group;

use App\Enums\GroupKind;
use App\Models\CredentialGroup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

abstract class GroupRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:120',
                Rule::unique(CredentialGroup::class, 'name')->ignore($this->route('group')),
            ],
            'kind' => ['required', Rule::enum(GroupKind::class)],
            'description' => ['nullable', 'string', 'max:190'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'name' => 'ad',
            'kind' => 'tür',
            'description' => 'açıklama',
        ];
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return [
            'name' => $this->string('name')->squish()->value(),
            'kind' => $this->enum('kind', GroupKind::class),
            'description' => $this->string('description')->squish()->value() ?: null,
        ];
    }
}
