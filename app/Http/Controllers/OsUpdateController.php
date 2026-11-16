<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\OsUpdate\ApplyOsUpdateRequest;
use App\Http\Requests\OsUpdate\CheckOsUpdateRequest;
use App\Http\Requests\OsUpdate\LockOsUpdateRequest;
use App\Models\Credential;
use App\Services\OsUpdate\OsUpdateLauncher;
use App\Services\OsUpdate\OsUpdateListing;
use App\Services\OsUpdate\OsUpdater;
use App\Services\OsUpdate\OsUpdateRound;
use App\Services\OsUpdate\OsUpdateTargets;
use App\Support\RequestLimit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * Linux sistem güncellemeleri: bekleyenleri görmek, denetlemek, kurmak, kilitlemek.
 *
 * Kurulum ve denetim ayrı yetki ister ('os_update'); kilit ayrı bir yetkidir
 * ('os_update_lock'). Kayıt başına yetki ve kilit OsUpdateLauncher'da sorulur.
 */
class OsUpdateController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:osUpdatePage,'.Credential::class, only: ['index']),
            new Middleware('can:osUpdateAny,'.Credential::class, only: ['check', 'apply', 'status', 'dismiss']),
            ...RequestLimit::icin('os_update', ['apply']),
            ...RequestLimit::icin('os_update_check', ['check']),
        ];
    }

    public function index(Request $request, OsUpdateListing $listing): View
    {
        /* Seçim adresten; yalnızca kullanıcının gördüğü gruplar/makineler arasında aranır. */
        return view('os-updates.index', $listing->build(
            $request->user(),
            $request->string('grup')->value() ?: null,
            $request->string('kayit')->value() ?: null,
        ));
    }

    public function check(CheckOsUpdateRequest $request, OsUpdateLauncher $launcher): RedirectResponse
    {
        $baslangic = now()->timestamp;
        $kimlikler = $launcher->denetle($request->credentials(), $request->user());

        if ($kimlikler === []) {
            return back()->with('error', 'Denetlenebilecek makine yok.');
        }

        OsUpdateRound::baslat($request->user(), $kimlikler, OsUpdateRound::DENETIM, $baslangic);

        return back()->with('status', count($kimlikler).' makinenin denetimi sıraya alındı; ilerleme yukarıdaki kartta.');
    }

    public function apply(ApplyOsUpdateRequest $request, OsUpdateLauncher $launcher): RedirectResponse
    {
        /* Sıraya almadan ÖNCEKİ an: sıraya alma bir saniyeyi aşarsa ilk makinelerin
         * apply_queued_at'i turdan eski sayılıp "başlamadı"ya düşüyordu. */
        $baslangic = now()->timestamp;
        $sonuc = $launcher->kur(
            $request->credentials(),
            $request->string('mod')->value(),
            $request->user(),
            $request->string('reason')->value(),
        );

        if ($sonuc['queued'] === []) {
            return back()->with('error', 'Hiçbir makineye kurulum başlatılmadı. '.OsUpdateLauncher::ozet($sonuc['skipped']));
        }

        OsUpdateRound::baslat($request->user(), $sonuc['queued'], OsUpdateRound::KURULUM, $baslangic);

        $not = $sonuc['skipped'] === [] ? '' : ' Atlanan: '.OsUpdateLauncher::ozet($sonuc['skipped']);

        return back()->with('status', count($sonuc['queued']).' makineye kurulum sıraya alındı.'.$not);
    }

    public function lock(LockOsUpdateRequest $request, Credential $credential, OsUpdateTargets $targets, OsUpdater $updater): RedirectResponse
    {
        abort_unless($targets->kullaniciIcin($request->user())->whereKey($credential->getKey())->exists(), 404);

        $kilitli = $request->boolean('kilit');
        $updater->kilitle($credential, $kilitli, $request->user(), $request->string('reason')->value());

        $ad = OsUpdateTargets::ad($credential->loadMissing('altGrup'));

        return back()->with('status', $kilitli
            ? "{$ad} kilitlendi: bu makineye güncelleme kurulmaz."
            : "{$ad} kilidi kaldırıldı.");
    }

    public function status(Request $request, string $parti): JsonResponse
    {
        $durum = OsUpdateRound::durum($request->user(), $parti);

        abort_if($durum === null, 404);

        return response()->json($durum);
    }

    public function dismiss(Request $request, string $parti): RedirectResponse
    {
        OsUpdateRound::kapat($request->user(), $parti);

        return back();
    }
}
