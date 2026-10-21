<?php

declare(strict_types=1);

namespace App\View\Components\Ui;

use App\Enums\BadgeVariant;
use Illuminate\View\Component;
use Illuminate\View\View;

class Badge extends Component
{
    public BadgeVariant $variant;

    public function __construct(BadgeVariant|string $variant = BadgeVariant::Gray)
    {
        $this->variant = is_string($variant) ? BadgeVariant::from($variant) : $variant;
    }

    public function render(): View
    {
        return view('components.ui.badge');
    }
}
