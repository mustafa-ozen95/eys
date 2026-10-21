<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Credential\RotateCredentialRequest;
use App\Jobs\RepairRotationJob;
use App\Models\Credential;
use App\Models\PasswordRotation;
use App\Services\Rotation\RotationLauncher;
use App\Support\RequestLimit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Yarıda kalmış bir turu kapatır: makinede duran kurtarma hesabını siler ve
 * gerekiyorsa kasadaki parolayı makinedekine hizalar.
 *
 * Döndürmeyle aynı yetkiyi ister: ikisi de makineyi değiştiriyor. İş de
 * döndürme gibi KUYRUKTA çalışır — uzak makineye bağlanmak dakikalar
 * sürebilir.
 */
class RotationRepairController extends Controller implements HasMiddleware
{
    /** @return array<int, Middleware> */
    public static function middleware(): array
    {
        return [
            new Middleware('can:rotate,credential'),
            ...RequestLimit::icin('rotate_repair'),
        ];
    }

    public function __invoke(
        RotateCredentialRequest $request,
        Credential $credential,
        PasswordRotation $rotation,
    ): RedirectResponse {
        /*
         * Tur, adres çubuğundaki kayda AİT olmalı: iki kimlik ayrı ayrı
         * çözülüyor ve ilişkileri doğrulanmazsa başka bir kaydın turu
         * kapatılabilirdi.
         */
        abort_unless($rotation->credential_id === $credential->getKey(), 404);

        if (! $rotation->recovery_active) {
            return back()->with('status', 'Bu turda makinede duran kurtarma hesabı yok.');
        }

        /* Uzak makineye bağlanır; tarayıcı bunu beklemez (bkz. RepairRotationJob). */
        RepairRotationJob::dispatch($rotation, $request->user());
        RotationLauncher::markPending($credential);

        return back()->with(
            'status',
            'Turu kapatma işi sıraya alındı; ekran bitince kendiliğinden güncellenir.',
        );
    }
}
