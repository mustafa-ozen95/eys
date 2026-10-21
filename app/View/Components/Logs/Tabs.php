<?php

declare(strict_types=1);

namespace App\View\Components\Logs;

use App\Models\ActivityLog;
use App\Models\ErrorLog;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * Kayıt ekranlarının sekme şeridi.
 *
 * "Hatalar" üst menüde ayrı bir bölümdü; menüyü kalabalıklaştırıyordu ve
 * kavramsal olarak da oraya ait değil: ikisi de olup biteni okuyan, yalnızca
 * okunur ekranlar. Aynı şerit iki ekranda da durur ki kullanıcı aralarında
 * gidip gelirken nerede olduğunu kaybetmesin.
 *
 * Sekme yetkinin yansımasıdır: göremeyeceği ekranın sekmesi hiç basılmaz.
 */
class Tabs extends Component
{
    /** @var array<int, array{label: string, url: string, active: bool}> */
    public array $items;

    public function __construct()
    {
        $this->items = $this->visibleTabs();
    }

    /** @return array<int, array{label: string, url: string, active: bool}> */
    private function visibleTabs(): array
    {
        $candidates = [
            ['label' => 'Etkinlik', 'route' => 'logs.index', 'pattern' => 'logs.*', 'ability' => 'viewAny', 'model' => ActivityLog::class],
            ['label' => 'Hatalar', 'route' => 'errors.index', 'pattern' => 'errors.*', 'ability' => 'viewAny', 'model' => ErrorLog::class],
        ];

        $tabs = [];

        foreach ($candidates as $candidate) {
            if (! Gate::allows($candidate['ability'], $candidate['model'])) {
                continue;
            }

            $tabs[] = [
                'label' => $candidate['label'],
                'url' => route($candidate['route']),
                'active' => Route::is($candidate['pattern']),
            ];
        }

        /* Tek sekme şerit değildir: gösterecek bir seçim yoksa hiç basılmaz. */
        return count($tabs) > 1 ? $tabs : [];
    }

    public function render(): View
    {
        return view('components.logs.tabs');
    }
}
