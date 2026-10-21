<?php

declare(strict_types=1);

namespace App\Services\Authorization;

use App\Models\CredentialGroup;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Support\Collection;

/**
 * Yetki matrisi ekranının verisini hazırlar.
 *
 * Ekran İKİ KATMANI birden gösterir: rolün canlı yetkisi (bilgi olarak,
 * "rolde var") ve kişinin istisnası (seçilebilir). Kullanıcı "rolden gelsin"
 * dediğinde satır silinir ve rol ne diyorsa o geçerli olur.
 *
 * Blade yalnızca hazır dizileri basar.
 */
class PermissionMatrixService
{
    public function __construct(
        private RolePermissions $roles,
        private PermissionRegistry $registry,
        private UserPermissionResolver $resolver,
    ) {}

    /** @return array<string, mixed> */
    public function forUser(User $user, ?string $resource = null): array
    {
        $resource = $this->resolveResource($resource);
        $matrix = $this->resolver->matrixFor($user);
        $actions = $this->registry->actionsFor($resource);
        $recordActions = $this->recordActions($actions);

        return [
            'user' => $user,
            'resource' => $resource,
            'actions' => $actions,
            'recordActions' => $recordActions,
            'actionLabels' => $this->registry->actions(),
            'wildcard' => $this->wildcardState($matrix, $resource, $actions),

            /* Rolün "bütün gruplarda" verdikleri: seçilebilir değil, BİLGİ. */
            'roleWildcard' => $this->roleWildcard($user, $resource, $actions),
            'records' => $this->records($user, $matrix, $resource, $recordActions),
            'stateOptions' => [
                'inherit' => 'Rolden gelsin',
                'allow' => 'İzin',
                'deny' => 'Yasak',
            ],
        ];
    }

    /**
     * Kayıt bazında anlamı olan aksiyonlar.
     *
     * "Ekle" listede YOKTUR: yaratma bir kayda bağlı değildir, soru kayıt
     * daha ortada yokken sorulur. Sütun olarak gösterilseydi hiçbir zaman işe
     * yaramayan bir seçim sunulurdu.
     *
     * @param  array<int, string>  $actions
     * @return array<int, string>
     */
    private function recordActions(array $actions): array
    {
        return array_values(array_filter(
            $actions,
            static fn (string $action): bool => $action !== 'create',
        ));
    }

    private function resolveResource(?string $resource): string
    {
        $keys = $this->registry->resourceKeys();

        return in_array($resource, $keys, true) ? $resource : $keys[0];
    }

    /**
     * Kişinin GENEL istisnası: izin / yasak / rolden gelsin.
     *
     * @param  array<string, mixed>  $matrix
     * @param  array<int, string>  $actions
     * @return array<string, string>
     */
    private function wildcardState(array $matrix, string $resource, array $actions): array
    {
        return collect($actions)
            ->mapWithKeys(fn (string $action): array => [
                $action => $matrix[$resource][$action]['wildcard'] ?? 'inherit',
            ])
            ->all();
    }

    /**
     * Rolün bütün gruplarda verdiği yetkiler — ekranda "rolde var" yazısı.
     *
     * @param  array<int, string>  $actions
     * @return array<string, bool>
     */
    private function roleWildcard(User $user, string $resource, array $actions): array
    {
        return collect($actions)
            ->mapWithKeys(fn (string $action): array => [
                $action => isset($this->roles->map($user->role, $resource, $action)[UserPermission::ALL_RECORDS]),
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $matrix
     * @param  array<int, string>  $actions
     * @return Collection<int, array<string, mixed>>
     */
    private function records(User $user, array $matrix, string $resource, array $actions): Collection
    {
        return CredentialGroup::query()
            ->withCount('credentials')
            ->ordered()
            ->get()
            ->map(fn (CredentialGroup $group): array => [
                /* Adres ve form değeri UUID taşır: sıralı anahtar dışarı çıkmaz. */
                'id' => $group->uuid,
                'title' => $group->name,
                'subtitle' => $group->credentials_count.' kayıt',
                'states' => collect($actions)
                    ->mapWithKeys(fn (string $action): array => [
                        $action => $this->stateFor($matrix, $resource, $action, $group->getKey()),
                    ])
                    ->all(),

                /* Rolün o grupta verdikleri: bilgi olarak gösterilir. */
                'role' => collect($actions)
                    ->mapWithKeys(fn (string $action): array => [
                        $action => $this->roleGrants($user, $resource, $action, $group->getKey()),
                    ])
                    ->all(),
            ]);
    }

    /** Rol bu grupta bu yetkiyi veriyor mu (kayda özel ya da genel)? */
    private function roleGrants(User $user, string $resource, string $action, int $groupId): bool
    {
        $map = $this->roles->map($user->role, $resource, $action);

        return isset($map[$groupId]) || isset($map[UserPermission::ALL_RECORDS]);
    }

    /** @param  array<string, mixed>  $matrix */
    private function stateFor(array $matrix, string $resource, string $action, int $id): string
    {
        $bucket = $matrix[$resource][$action] ?? null;

        return match (true) {
            in_array($id, $bucket['denied'] ?? [], true) => 'deny',
            in_array($id, $bucket['allowed'] ?? [], true) => 'allow',
            default => 'inherit',
        };
    }
}
