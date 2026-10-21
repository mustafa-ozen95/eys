<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CredentialGroup;
use App\Models\User;
use App\Services\Authorization\UserPermissionResolver;

/**
 * Grup yetkileri.
 *
 * Grup AÇMAK, adını değiştirmek ve silmek yönetici işidir: grup aynı zamanda
 * yetki sınırıdır ve sınırı çizen, sınırın içine kimin gireceğini de belirler.
 * Bu yüzden yazma uçları yalnızca Gate::before ile geçilir.
 */
class CredentialGroupPolicy
{
    public function __construct(private UserPermissionResolver $resolver) {}

    public function viewAny(User $user): bool
    {
        return $this->resolver->allowsAny($user, CredentialPolicy::RESOURCE, 'view');
    }

    public function view(User $user, CredentialGroup $group): bool
    {
        return $this->resolver->allows($user, CredentialPolicy::RESOURCE, 'view', $group->getKey());
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, CredentialGroup $group): bool
    {
        return false;
    }

    public function delete(User $user, CredentialGroup $group): bool
    {
        return false;
    }
}
