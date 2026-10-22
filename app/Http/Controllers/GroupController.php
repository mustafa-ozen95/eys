<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ActivityAction;
use App\Enums\GroupKind;
use App\Http\Requests\Group\DestroyGroupRequest;
use App\Http\Requests\Group\StoreGroupRequest;
use App\Http\Requests\Group\UpdateGroupRequest;
use App\Models\CredentialGroup;
use App\Models\SubGroup;
use App\Models\User;
use App\Policies\CredentialPolicy;
use App\Services\ActivityRecorder;
use App\Services\Authorization\RolePermissions;
use App\Services\Authorization\UserPermissionResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Grup yönetimi — yalnızca yönetici (bkz. CredentialGroupPolicy).
 */
class GroupController extends Controller implements HasMiddleware
{
    public function __construct(
        private ActivityRecorder $activity,
        private UserPermissionResolver $resolver,
        private RolePermissions $roles,
    ) {}

    /** @return array<int, Middleware> */
    public static function middleware(): array
    {
        return [
            new Middleware('can:create,App\Models\CredentialGroup', only: ['index', 'create', 'store', 'reorder']),
            new Middleware('can:update,group', only: ['edit', 'update']),
        ];
    }

    public function index(Request $request): View
    {
        /*
         * SAYFALAMA YOK: gruplar sürüklenerek sıralanıyor ve sıra tek bir
         * listede anlamlı; iki sayfaya bölünmüş liste sürüklenemez. Grup
         * sayısı zaten onlarla ölçülüyor.
         */
        $groups = CredentialGroup::query()
            ->search($request->string('q')->squish()->value() ?: null)
            ->withCount('credentials')
            ->with(['altGruplar' => fn ($q) => $q->withCount('credentials')])
            ->ordered()
            ->get();

        return view('groups.index', [
            'groups' => $groups,
            'viewerCounts' => $this->viewerCounts($groups),
            'query' => $request->string('q')->value(),
            /* Hepsi KAPALI gelir; yalnız ?acik= ile istenen açık: alt grup işinden dönüşte. */
            'acikGrup' => $request->string('acik')->value() ?: null,
            'pencere' => $this->subGroupWindow($request, $groups),
        ]);
    }

    /**
     * Alt grup penceresi: ekleme (?pencere=ekle&grup=) ya da düzenleme
     * (?pencere=duzenle&alt=). Pencere sunucudan açık gelir, doğrulama
     * hatasında da öyle.
     *
     * @param  Collection<int, CredentialGroup>  $groups
     * @return array{baslik: string, action: string, method: string, ad: ?string, grupAdi: string}|null
     */
    private function subGroupWindow(Request $request, Collection $groups): ?array
    {
        $pencere = $request->string('pencere')->value();

        if ($pencere === 'ekle') {
            $group = $groups->firstWhere('uuid', $request->string('grup')->value());

            return $group === null ? null : [
                'baslik' => "{$group->name} · yeni alt grup",
                'action' => route('groups.sub.store', $group),
                'method' => 'POST',
                'ad' => null,
                'grupAdi' => $group->name,
            ];
        }

        if ($pencere === 'duzenle') {
            $altGrup = SubGroup::query()->with('group')->where('uuid', $request->string('alt')->value())->first();

            return $altGrup === null ? null : [
                'baslik' => "{$altGrup->group->name} · {$altGrup->name}",
                'action' => route('groups.sub.update', [$altGrup->group, $altGrup]),
                'method' => 'PUT',
                'ad' => $altGrup->name,
                'grupAdi' => $altGrup->group->name,
            ];
        }

        return null;
    }

    /**
     * "Kaç kullanıcı görebiliyor" — sayaçla değil çözümleyiciyle.
     *
     * İzin genel de olabilir, gruba özel de, ve bir yasak satırı ikisini de
     * keser; karar çözümleyicinin işi. Hesap Blade'de yapılıyordu: sayfa,
     * satır başına bir closure çağırıyordu. Görüntü hazır veri gösterir.
     *
     * Dizi UUID ile anahtarlanır: görüntüye giden hiçbir yapı sıralı
     * birincil anahtar taşımaz, hesapta bile. Karar iç anahtarla verilir.
     *
     * @param  Collection<int, CredentialGroup>  $groups
     * @return array<string, int> grup uuid => görebilen kullanıcı sayısı
     */
    private function viewerCounts(Collection $groups): array
    {
        if ($groups->isEmpty()) {
            return [];
        }

        $users = User::query()->with('permissions', 'role')->get();

        return $groups
            ->mapWithKeys(fn (CredentialGroup $group): array => [
                $group->uuid => $users->filter(fn (User $user): bool => $this->resolver->allows(
                    $user, CredentialPolicy::RESOURCE, 'view', $group->getKey(),
                ))->count(),
            ])
            ->all();
    }

    public function create(): View
    {
        return view('groups.create', ['kinds' => GroupKind::options()]);
    }

    public function store(StoreGroupRequest $request): RedirectResponse
    {
        /* Yeni grup listenin SONUNA girer; yerini sürükleyerek verirsiniz. */
        $group = CredentialGroup::create([
            ...$request->payload(),
            'position' => (int) CredentialGroup::query()->where('is_personal', false)->max('position') + 1,
        ]);

        $this->activity->record(ActivityAction::Created, "Grup açıldı: {$group->name}", $group);

        return redirect()->route('groups.index')->with('status', "{$group->name} eklendi.");
    }

    public function edit(CredentialGroup $group): View
    {
        return view('groups.edit', ['group' => $group, 'kinds' => GroupKind::options()]);
    }

    public function update(UpdateGroupRequest $request, CredentialGroup $group): RedirectResponse
    {
        $eskiAd = $group->name;

        $group->update($request->payload());

        /*
         * AD DEĞİŞTİYSE ROL ŞABLONLARI DA DEĞİŞİR. Şablon grubu adıyla
         * tanıyor; adı güncelleyip şablonu bırakmak, o gruptaki rol
         * yetkisini sessizce düşürmek olurdu (bkz. RolePermissions).
         */
        $this->roles->grupYenidenAdlandirildi($eskiAd, $group->name);

        $this->activity->record(
            ActivityAction::Updated,
            $eskiAd === $group->name
                ? "Grup güncellendi: {$group->name}"
                : "Grup yeniden adlandırıldı: {$eskiAd} → {$group->name}",
            $group,
        );

        return redirect()->route('groups.index')->with('status', "{$group->name} güncellendi.");
    }

    /**
     * Grupları sürükleyerek sıralar (bkz. sorting.js).
     *
     * Gelen dizideki uuid'ler 1'den başlayarak numaralanır; listede olmayan
     * grup (kişisel taşıyıcı, başka sayfadaki) olduğu gibi kalır.
     */
    public function reorder(Request $request): RedirectResponse
    {
        $veri = $request->validate([
            'sira' => ['required', 'array'],
            'sira.*' => ['string', 'uuid'],
        ]);

        $gruplar = CredentialGroup::query()->where('is_personal', false)->pluck('id', 'uuid');
        $numara = 1;

        DB::transaction(function () use ($veri, $gruplar, &$numara): void {
            foreach ($veri['sira'] as $uuid) {
                if ($gruplar->has($uuid)) {
                    CredentialGroup::query()->whereKey($gruplar[$uuid])->update(['position' => $numara++]);
                }
            }
        });

        $this->activity->record(ActivityAction::Updated, 'Gruplar yeniden sıralandı');

        return back()->with('status', 'Grup sırası güncellendi.');
    }

    public function destroy(DestroyGroupRequest $request, CredentialGroup $group): RedirectResponse
    {
        $name = $group->name;
        $count = $group->credentials()->count();

        $group->delete();

        /* Şablonda adı kalmasın: yetki vermiyor ama her istekte uyarı yazıyordu. */
        $this->roles->grupSilindi($name);

        $this->activity->record(
            ActivityAction::Deleted,
            "Grup silindi: {$name} ({$count} kayıt)",
            $group,
            $request->string('reason')->value(),
        );

        return redirect()->route('groups.index')->with('status', "{$name} silindi.");
    }
}
