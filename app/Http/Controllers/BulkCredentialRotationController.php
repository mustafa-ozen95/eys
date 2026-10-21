<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Credential\BulkRotateCredentialRequest;
use App\Services\Rotation\BulkRound;
use App\Services\Rotation\RotationLauncher;
use App\Support\RequestLimit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Seçilen kayıtların parolasını topluca döndürür.
 *
 * Tek kayıtla AYNI motoru kullanır (RotationLauncher): her kayıt için ayrı
 * yetki, ayrı uygunluk kontrolü ve ayrı iş. Bir kaydın patlaması diğerlerini
 * etkilemez; hiçbiri "seçildiği için" yetki kazanmaz.
 *
 * Dakikada 3 istek: tek istek onlarca makineye dokunabilir.
 */
class BulkCredentialRotationController extends Controller implements HasMiddleware
{
    /** @return array<int, Middleware> */
    public static function middleware(): array
    {
        return [
            new Middleware('can:rotateAny,App\Models\Credential'),
            ...RequestLimit::icin('rotate_bulk'),
        ];
    }

    public function __invoke(BulkRotateCredentialRequest $request, RotationLauncher $launcher): RedirectResponse
    {
        $sonuc = $launcher->launch(
            $request->credentials(),
            $request->user(),
            $request->string('reason')->value(),
            toplu: true,
        );

        /*
         * Parti açılır: ekrandaki TEK gösterge bu partinin kayıtlarını izler
         * (bkz. BulkRound). Kuyruğa hiçbir şey girmediyse izlenecek bir şey
         * de yoktur.
         */
        if ($sonuc['queuedIds'] !== []) {
            BulkRound::baslat($request->user(), $sonuc['queuedIds']);
        }

        $kuyruga = count($sonuc['queued']);
        $atlanan = count($sonuc['skipped']);

        if ($kuyruga === 0) {
            return back()->with(
                'error',
                'Hiçbir kaydın parolası güncellenemedi. '.RotationLauncher::summarize($sonuc['skipped']),
            );
        }

        $kuyruk = sprintf('%d kayıt sıraya alındı.', $kuyruga);
        $not = $atlanan === 0 ? '' : "{$atlanan} kayıt atlandı: ".RotationLauncher::summarize($sonuc['skipped']);

        return back()->with('status', trim("{$kuyruk} İlerleme listenin üstünde. {$not}"));
    }
}
