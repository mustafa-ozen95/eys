<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ActivityAction;
use App\Models\ErrorLog;
use App\Services\ActivityRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * Hata kayıtları — yalnızca yönetici.
 *
 * Kayıt SİLİNMEZ, yalnızca "çözüldü" işaretlenir. Silme ucu olsaydı bir
 * hatayı görmezden gelmenin en kolay yolu onu yok etmek olurdu.
 */
class ErrorLogController extends Controller implements HasMiddleware
{
    /** @return array<int, Middleware> */
    public static function middleware(): array
    {
        return [
            new Middleware('can:viewAny,App\Models\ErrorLog', only: ['index']),
            new Middleware('can:resolve,error', only: ['resolve']),
        ];
    }

    public function index(Request $request): View
    {
        $durum = $request->string('durum')->value() ?: 'acik';

        return view('errors-log.index', [
            'errors' => ErrorLog::query()
                ->with(['user', 'resolver'])
                ->when($durum === 'acik', fn ($q) => $q->open())
                ->search($request->string('q')->squish()->value() ?: null)
                ->latestFirst()
                ->paginate(30)
                ->withQueryString(),
            'query' => $request->string('q')->value(),
            'durum' => $durum,
            'durumlar' => ['acik' => 'Açık olanlar', 'hepsi' => 'Tümü'],
            'openCount' => ErrorLog::query()->open()->count(),
        ]);
    }

    public function resolve(ErrorLog $error, ActivityRecorder $activity): RedirectResponse
    {
        $error->forceFill([
            'resolved_at' => now(),
            'resolved_by' => request()->user()->getKey(),
        ])->save();

        $activity->record(
            ActivityAction::Updated,
            'Hata çözüldü olarak işaretlendi: '.str($error->type)->afterLast('\\'),
        );

        return back()->with('status', 'Hata çözüldü olarak işaretlendi.');
    }
}
