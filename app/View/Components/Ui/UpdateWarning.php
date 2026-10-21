<?php

declare(strict_types=1);

namespace App\View\Components\Ui;

use App\Services\Update\UpdateCheck;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * Yeni sürüm varken yöneticiye uyarı. Karar burada; şablon yalnızca hazır
 * durumu çizer.
 */
class UpdateWarning extends Component
{
    public bool $goster;

    public int $geride = 0;

    public function __construct(UpdateCheck $denetim)
    {
        $this->goster = (Auth::user()?->isAdmin() ?? false) && $denetim->yeniVar();

        if ($this->goster) {
            $this->geride = (int) ($denetim->son()['geride'] ?? 0);
        }
    }

    public function render(): View
    {
        return view('components.ui.update-warning');
    }
}
