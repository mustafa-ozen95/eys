<?php

declare(strict_types=1);

namespace App\Http\Requests\Role;

use App\Enums\BadgeVariant;
use App\Models\CredentialGroup;
use App\Models\Role;
use App\Policies\CredentialPolicy;
use App\Services\Authorization\PermissionRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Rol formu. Ekleme ve düzenleme aynı kuralları kullanır.
 *
 * Şablon, rolün hazır yetki kümesidir; iki katmanı vardır: bütün gruplarda
 * geçerli aksiyonlar ve yalnızca seçili gruplarda geçerli ek aksiyonlar.
 * İkinci katman olmadan "her yerde görsün, parolayı yalnızca ağ gruplarında
 * açsın" ifade edilemezdi.
 */
abstract class RoleRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $registry = app(PermissionRegistry::class);
        $actions = $registry->actionsFor(CredentialPolicy::RESOURCE);

        return [
            'label' => ['required', 'string', 'max:60'],
            'slug' => [
                $this->routeRole()?->is_system ? 'nullable' : 'required',
                'string', 'max:40', 'regex:/^[a-z0-9-]+$/',
                Rule::unique(Role::class, 'slug')->ignore($this->routeRole()),
            ],
            'description' => ['nullable', 'string', 'max:190'],
            'badge' => ['required', Rule::enum(BadgeVariant::class)],
            'is_default' => ['nullable', 'boolean'],
            'position' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'template_all' => ['array'],
            'template_all.*' => [Rule::in($actions)],
            'template_groups' => ['array'],
            'template_groups.*' => ['array'],
            'template_groups.*.*' => [Rule::in($actions)],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'label' => 'ad',
            'slug' => 'kısa ad',
            'description' => 'açıklama',
            'badge' => 'renk',
            'position' => 'sıra',
        ];
    }

    protected function routeRole(): ?Role
    {
        $role = $this->route('role');

        return $role instanceof Role ? $role : null;
    }

    /**
     * Kaydedilecek veri.
     *
     * `is_admin` BURADA YOKTUR: yönetici rolü üretmek forma bir kutu koymakla
     * verilecek bir yetki değil. Yerleşik yönetici rolü zaten var; ikincisi
     * gerekiyorsa veritabanından açılır.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $role = $this->routeRole();

        $data = [
            'label' => $this->string('label')->squish()->value(),
            'description' => $this->string('description')->squish()->value() ?: null,
            'badge' => $this->enum('badge', BadgeVariant::class),
            'position' => $this->integer('position'),
            'template' => $this->template(),
        ];

        /* Yerleşik rolün slug'ı değişmez: kod ve şablonlar ona adıyla bakıyor. */
        if ($role === null || ! $role->is_system) {
            $data['slug'] = $this->string('slug')->lower()->trim()->value();
        }

        /* Yönetici rolü varsayılan OLAMAZ. */
        $data['is_default'] = $this->boolean('is_default') && ! ($role?->is_admin ?? false);

        return $data;
    }

    /**
     * Şablonu grup ADIYLA saklar.
     *
     * Kimlik yerine ad kullanılır: şablon bir veri dosyası gibi okunabilsin ve
     * başka bir kuruluma taşınabilsin diye. Adı bulunamayan satır uygulanırken
     * sessizce atılır (bkz. RolePermissions).
     *
     * @return array<string, array<string, mixed>>
     */
    private function template(): array
    {
        /* Ekran UUID gönderir: sıralı birincil anahtar DOM'a çıkmaz. */
        $names = CredentialGroup::query()->pluck('name', 'uuid');
        $groups = [];

        foreach ((array) $this->input('template_groups', []) as $uuid => $actions) {
            $name = $names[(string) $uuid] ?? null;
            $actions = array_values(array_filter((array) $actions, 'is_string'));

            if ($name !== null && $actions !== []) {
                $groups[$name] = $actions;
            }
        }

        return [
            CredentialPolicy::RESOURCE => [
                'all' => array_values(array_filter((array) $this->input('template_all', []), 'is_string')),
                'groups' => $groups,
            ],
        ];
    }
}
