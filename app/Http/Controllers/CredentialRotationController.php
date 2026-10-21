<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Credential\RotateCredentialRequest;
use App\Models\Credential;
use App\Services\Rotation\RotationLauncher;
use App\Support\IstekSiniri;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Parola döndürmeyi başlatır.
 *
 * Ayrı bir yetki ister ('rotate'), gerekçe ister ve işi kuyruğa alır.
 * Dakikada 5 istekle sınırlıdır: bu uç uzaktaki makineleri değiştiriyor,
 * kazayla tekrar tekrar tetiklenmemeli.
 */
class CredentialRotationController extends Controller implements HasMiddleware
{
    /** @return array<int, Middleware> */
    public static function middleware(): array
    {
        return [
            new Middleware('can:rotate,credential'),
            ...IstekSiniri::icin('rotate'),
        ];
    }

    public function __invoke(
        RotateCredentialRequest $request,
        Credential $credential,
        RotationLauncher $launcher,
    ): RedirectResponse {
        /* Toplu uçla AYNI motor: uygunluk ve yetki tek yerde sorulur. */
        $sonuc = $launcher->launch([$credential], $request->user(), $request->string('reason')->value());

        if ($sonuc['queued'] === []) {
            return back()->with(
                'error',
                'Parola güncelleme başlatılamadı: '.RotationLauncher::summarize($sonuc['skipped']),
            );
        }

        return back()->with(
            'status',
            "{$credential->name} için parola güncelleme sıraya alındı. Sonucu kayıtlar ekranından izleyin.",
        );
    }
}
