<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Rotation\BulkRound;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Toplu turun göstergesi: durumu okur ve göstergeyi kapatır.
 *
 * Uç yalnızca SAYI ve KAYIT ADI döndürür; parola, denenen parola ve
 * kurtarma parolası hiç okunmaz. Parti başkasınınsa 404 — varlığı da
 * söylenmez (bkz. BulkRound::durum).
 */
class BulkRoundController extends Controller implements HasMiddleware
{
    /** @return array<int, Middleware> */
    public static function middleware(): array
    {
        return [new Middleware('can:rotateAny,App\Models\Credential')];
    }

    public function durum(Request $request, string $parti): JsonResponse
    {
        $durum = BulkRound::durum($request->user(), $parti);

        abort_if($durum === null, 404);

        return response()->json($durum);
    }

    /** Göstergeyi ekrandan kaldırır; turlara dokunmaz. */
    public function kapat(Request $request, string $parti): RedirectResponse
    {
        BulkRound::kapat($request->user(), $parti);

        return back();
    }
}
