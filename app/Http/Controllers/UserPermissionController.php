<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ActivityAction;
use App\Http\Requests\User\UpdatePermissionsRequest;
use App\Models\User;
use App\Services\ActivityRecorder;
use App\Services\Authorization\PermissionMatrixService;
use App\Services\Authorization\UserPermissionResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * Kullanıcının yetki İSTİSNALARI — yalnızca yönetici.
 *
 * Bu ekran rolün yetkisini göstermez, ONUN ÜSTÜNE yazılanı tutar: rolün
 * vermediğini ekler ("izin"), verdiğini keser ("yasak"). Dokunulmayan her
 * satırda rol geçerlidir.
 *
 * Yönetici için bilgi amaçlıdır: yönetici zaten bütün kontrolleri geçer
 * (Gate::before), matris ona bir şey eklemez.
 */
class UserPermissionController extends Controller implements HasMiddleware
{
    public function __construct(
        private ActivityRecorder $activity,
        private UserPermissionResolver $resolver,
    ) {}

    /** @return array<int, Middleware> */
    public static function middleware(): array
    {
        return [new Middleware('can:update,user')];
    }

    public function edit(Request $request, User $user, PermissionMatrixService $matrix): View
    {
        return view('users.permissions', $matrix->forUser($user, $request->string('kaynak')->value() ?: null));
    }

    public function update(UpdatePermissionsRequest $request, User $user): RedirectResponse
    {
        $matrix = $request->matrix();
        $resource = $request->string('resource')->value();

        $this->resolver->syncResource(
            $user,
            $resource,
            $matrix['wildcard'],
            $matrix['allowed'],
            $matrix['denied'],
            $matrix['wildcardDenied'],
        );

        $this->activity->record(
            ActivityAction::Updated,
            sprintf(
                '%s yetki istisnaları güncellendi: %d genel, %d kayıt bazlı',
                $user->name,
                count($matrix['wildcard']) + count($matrix['wildcardDenied']),
                collect($matrix['allowed'])->flatten()->count() + collect($matrix['denied'])->flatten()->count(),
            ),
            $user,
        );

        return redirect()
            ->route('users.permissions.edit', $user)
            ->with('status', "{$user->name} yetkileri güncellendi.");
    }
}
