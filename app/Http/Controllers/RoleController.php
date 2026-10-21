<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ActivityAction;
use App\Enums\BadgeVariant;
use App\Http\Requests\Role\StoreRoleRequest;
use App\Http\Requests\Role\UpdateRoleRequest;
use App\Models\CredentialGroup;
use App\Models\Role;
use App\Policies\CredentialPolicy;
use App\Services\ActivityRecorder;
use App\Services\Authorization\PermissionRegistry;
use App\Services\Settings\SettingsTabs;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Rol yönetimi — yalnızca yönetici (bkz. RolePolicy, Gate::before).
 */
class RoleController extends Controller implements HasMiddleware
{
    public function __construct(
        private ActivityRecorder $activity,
        private PermissionRegistry $registry,
        private SettingsTabs $tabs,
    ) {}

    /** @return array<int, Middleware> */
    public static function middleware(): array
    {
        return [
            new Middleware('can:viewAny,App\Models\Role', only: ['index']),
            new Middleware('can:create,App\Models\Role', only: ['create', 'store']),
            new Middleware('can:update,role', only: ['edit', 'update']),
            new Middleware('can:delete,role', only: ['destroy']),
        ];
    }

    public function index(): View
    {
        return view('roles.index', [
            'roles' => Role::query()->withCount('users')->ordered()->get(),

            /*
             * Şerit ORTAK servisten gelir (SettingsTabs). Burada ayrıca
             * kurulduğunda "API jetonları" sekmesi eksik kalıyordu: kullanıcı
             * Ayarlar'da gördüğü sekmeyi Roller'a geçince kaybediyordu.
             */
            'tabs' => $this->tabs->all('roller'),
        ]);
    }

    public function create(): View
    {
        return view('roles.create', $this->formData());
    }

    public function store(StoreRoleRequest $request): RedirectResponse
    {
        $role = DB::transaction(function () use ($request): Role {
            $role = Role::create($request->payload());
            $this->clearOtherDefaults($role);

            return $role;
        });

        $this->activity->record(ActivityAction::Created, "Rol açıldı: {$role->label}", $role);

        return redirect()->route('roles.index')->with('status', "{$role->label} eklendi.");
    }

    /**
     * SIRA ÖNEMLİ: rolün şablonu formData'nın BOŞ varsayılanının ÜSTÜNE
     * yazılır.
     *
     * Ters sırada duruyordu ve boş varsayılan kazanıyordu: ekran bütün
     * kutuları işaretsiz çiziyor, kullanıcı "kaydet" deyince gördüğü şey —
     * yani hiçbir yetki — yazılıyordu. Kayıtlı yetkiler her düzenlemede
     * sessizce siliniyor, ikinci turda yeniden işaretlemek gerekiyordu.
     */
    public function edit(Role $role): View
    {
        return view('roles.edit', [
            ...$this->formData(),
            'role' => $role,
            'template' => $role->normalizedTemplate(),
        ]);
    }

    /**
     * Rolün yetkileri CANLI: kaydedildiği an o roldeki herkes için geçerli.
     *
     * Yetkiler kullanıcıya kopyalanmaz; kopya olsaydı rolü düzenlemek
     * kimseyi etkilemez, tek tek "şablonu uygula" demek gerekirdi.
     */
    public function update(UpdateRoleRequest $request, Role $role): RedirectResponse
    {
        DB::transaction(function () use ($request, $role): void {
            $role->update($request->payload());
            $this->clearOtherDefaults($role);
        });

        $sayi = $role->users()->count();

        $this->activity->record(
            ActivityAction::Updated,
            "Rol güncellendi: {$role->label}".($sayi > 0 ? " · roldeki {$sayi} kullanıcıyı etkiler" : ''),
            $role,
        );

        return redirect()->route('roles.index')->with('status', $sayi > 0
            ? "{$role->label} güncellendi; roldeki {$sayi} kullanıcı için hemen geçerli."
            : "{$role->label} güncellendi.");
    }

    public function destroy(Role $role): RedirectResponse
    {
        $label = $role->label;

        $role->delete();

        $this->activity->record(ActivityAction::Deleted, "Rol silindi: {$label}");

        return redirect()->route('roles.index')->with('status', "{$label} silindi.");
    }

    /**
     * Varsayılan rol TEK olabilir.
     *
     * İki varsayılan bırakılsaydı yeni kullanıcının hangisini alacağı sıraya
     * kalırdı; sıra değişince kimse fark etmeden başka bir rol dağıtılırdı.
     */
    private function clearOtherDefaults(Role $role): void
    {
        if (! $role->is_default) {
            return;
        }

        Role::query()->whereKeyNot($role->getKey())->update(['is_default' => false]);
    }

    /** @return array<string, mixed> */
    private function formData(): array
    {
        return [
            'actions' => $this->registry->actionsFor(CredentialPolicy::RESOURCE),
            'actionLabels' => $this->registry->actions(),
            'groups' => CredentialGroup::query()->withCount('credentials')->ordered()->get(),
            'template' => ['all' => [], 'groups' => []],
            'badges' => collect(BadgeVariant::cases())
                ->mapWithKeys(fn (BadgeVariant $b): array => [$b->value => ucfirst($b->value)])
                ->all(),
        ];
    }
}
