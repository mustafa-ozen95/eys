<?php

declare(strict_types=1);

namespace App\Http\Requests\User;

use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

abstract class UserRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => [
                'required', 'string', 'email', 'max:190',
                Rule::unique(User::class, 'email')->ignore($this->route('user')),
            ],
            'password' => [$this->passwordRequired() ? 'required' : 'nullable', 'confirmed', Password::min(12)->letters()->numbers()->symbols()],
            'role' => ['required', 'uuid', Rule::exists(Role::class, 'uuid')],
            'status' => ['required', Rule::enum(UserStatus::class)],
        ];
    }

    abstract protected function passwordRequired(): bool;

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'name' => 'ad',
            'email' => 'e-posta',
            'password' => 'parola',
            'role' => 'rol',
            'status' => 'durum',
        ];
    }

    /**
     * Rolü yalnızca yönetici değiştirebilir; "kullanıcıları düzenle" yetkisi
     * kendini yönetici yapma yetkisi DEĞİLDİR. Kendi rolünü kimse
     * değiştiremez (bkz. UserPolicy::updateRole).
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $data = [
            'name' => $this->string('name')->squish()->value(),
            'email' => $this->string('email')->lower()->trim()->value(),
            'status' => $this->enum('status', UserStatus::class),
        ];

        $target = $this->route('user');

        if ($target === null || $this->user()->can('updateRole', $target)) {
            $data['role_id'] = Role::query()->where('uuid', $this->string('role'))->value('id');
        }

        if (filled($this->input('password'))) {
            $data['password'] = $this->input('password');
        }

        return $data;
    }
}
