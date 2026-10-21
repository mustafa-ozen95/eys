<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ActivityAction;
use App\Http\Requests\Settings\SettingsRequest;
use App\Services\ActivityRecorder;
use App\Services\Settings\SettingsCatalog;
use App\Services\Settings\SettingsRepository;
use App\Services\Settings\SettingsTabs;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * Ayarlar — yalnızca yönetici.
 *
 * Roller de bu ekranın bir sekmesidir (bkz. RoleController): rol tanımlamak
 * da bir ayardır, ayrı bir bölüm olarak durması menüyü gereksiz uzatıyordu.
 */
class SettingsController extends Controller implements HasMiddleware
{
    public function __construct(
        private SettingsCatalog $catalog,
        private SettingsTabs $tabs,
        private SettingsRepository $settings,
        private ActivityRecorder $activity,
    ) {}

    /** @return array<int, Middleware> */
    public static function middleware(): array
    {
        return [
            new Middleware('can:viewAny,App\Models\Setting', only: ['index']),
            new Middleware('can:update,App\Models\Setting', only: ['update']),
        ];
    }

    public function index(Request $request): View
    {
        $group = $request->string('grup')->value();
        $group = in_array($group, $this->catalog->groupKeys(), true)
            ? $group
            : $this->catalog->groupKeys()[0];

        return view('settings.index', [
            'group' => $group,
            'definition' => $this->catalog->group($group),
            'settings' => $this->catalog->definitionsFor($group),
            'values' => collect($this->catalog->definitionsFor($group))
                ->mapWithKeys(fn ($d): array => [$d->key => $this->settings->current($d->key)])
                ->all(),
            'tabs' => $this->tabs->all($group),
        ]);
    }

    public function update(SettingsRequest $request): RedirectResponse
    {
        $changes = $this->settings->put($request->payload(), $request->user());

        /*
         * Yalnızca DEĞİŞENLER kayda geçer ve eski/yeni değer birlikte yazılır:
         * "ayarlar güncellendi" satırı, denetimde hiçbir soruyu yanıtlamaz.
         */
        if ($changes !== []) {
            $this->activity->record(
                ActivityAction::Updated,
                'Ayar değişti: '.collect($changes)
                    ->map(fn (array $c, string $key): string => sprintf(
                        '%s (%s → %s)', $key, $this->readable($c['from']), $this->readable($c['to']),
                    ))
                    ->implode(', '),
            );
        }

        return redirect()
            ->route('settings.index', ['grup' => $request->group()])
            ->with('status', $changes === [] ? 'Değişiklik yok.' : count($changes).' ayar güncellendi.');
    }

    /**
     * Sekmeler. Roller son sekmedir ve kendi ekranına götürür.
     *
     * @return array<int, array{label: string, url: string, active: bool}>
     */
    private function readable(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'açık' : 'kapalı',
            $value === null => '—',
            default => (string) $value,
        };
    }
}
