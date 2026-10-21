<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ActivityAction;
use App\Enums\UserStatus;
use App\Http\Requests\User\StoreUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\ActivityRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * Kullanıcı yönetimi — yalnızca yönetici (bkz. UserPolicy, Gate::before).
 */
class UserController extends Controller implements HasMiddleware
{
    public function __construct(private ActivityRecorder $activity) {}

    /** @return array<int, Middleware> */
    public static function middleware(): array
    {
        return [
            new Middleware('can:viewAny,App\Models\User', only: ['index']),
            new Middleware('can:create,App\Models\User', only: ['create', 'store']),
            new Middleware('can:update,user', only: ['edit', 'update']),
            new Middleware('can:delete,user', only: ['destroy']),
        ];
    }

    public function index(Request $request): View
    {
        return view('users.index', [
            'users' => User::query()
                ->search($request->string('q')->squish()->value() ?: null)
                /*
                 * Yetki sayısı satır satır basılıyor; ilişki yüklenmezse
                 * listedeki her kullanıcı için ayrı bir sorgu çıkardı.
                 */
                ->with(['role', 'permissions'])
                ->orderBy('name')
                ->paginate(30)
                ->withQueryString(),
            'query' => $request->string('q')->value(),
        ]);
    }

    public function create(): View
    {
        return view('users.create', $this->options());
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $user = User::create($request->payload());

        /*
         * YETKİ KOPYALANMAZ. Rol canlı okunuyor (bkz. RolePermissions):
         * kullanıcı rolünün yetkileriyle doğar ve rol değiştiğinde onunla
         * birlikte değişir. Buradaki satırlar yalnızca istisna tutar.
         */

        $this->activity->record(
            ActivityAction::Created,
            "Kullanıcı açıldı: {$user->name} ({$user->role?->label})",
            $user,
        );

        return redirect()
            ->route('users.permissions.edit', $user)
            ->with('status', "{$user->name} eklendi. Şimdi grup izinlerini verin.");
    }

    public function edit(User $user): View
    {
        return view('users.edit', ['user' => $user, ...$this->options()]);
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $user->update($request->payload());

        /*
         * Yönetici KENDİ parolasını değiştirdiyse kendi oturumu düşmemeli:
         * AuthenticateSession oturumdaki parola özetini karşılaştırıyor.
         * Başkasının parolası değiştiyse onun oturumları düşer (istenen bu).
         */
        if ($user->wasChanged('password') && $user->is($request->user())) {
            $request->session()->put('password_hash_'.Auth::getDefaultDriver(), $user->getAuthPassword());
        }

        /*
         * ROL DEĞİŞİNCE YETKİLER KENDİLİĞİNDEN DEĞİŞİR: rol canlı okunuyor,
         * kullanıcıya kopyalanmıyor. Kişiye özel istisnalar ise yerinde
         * kalır — onlar bu kişiye verilmişti, role değil.
         */
        $this->activity->record(ActivityAction::Updated, "Kullanıcı güncellendi: {$user->name}", $user);

        return redirect()->route('users.index')->with('status', "{$user->name} güncellendi.");
    }

    public function destroy(User $user): RedirectResponse
    {
        $name = $user->name;

        $user->delete();

        $this->activity->record(ActivityAction::Deleted, "Kullanıcı silindi: {$name}");

        return redirect()->route('users.index')->with('status', "{$name} silindi.");
    }

    /** @return array<string, mixed> */
    private function options(): array
    {
        return [
            'roles' => Role::query()->ordered()->pluck('label', 'uuid')->all(),
            'defaultRole' => Role::default()?->uuid,
            'statuses' => UserStatus::options(),
        ];
    }
}
