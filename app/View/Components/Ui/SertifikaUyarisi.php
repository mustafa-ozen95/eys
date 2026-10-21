<?php

declare(strict_types=1);

namespace App\View\Components\Ui;

use App\Services\SertifikaDurumu;
use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * TLS sertifikası bitmek üzereyken yöneticiye uyarı. Karar burada;
 * şablon yalnızca hazır durumu çizer.
 */
class SertifikaUyarisi extends Component
{
    /** @var array<string, mixed>|null */
    public ?array $durum;

    public bool $goster;

    public function __construct()
    {
        $this->durum = SertifikaDurumu::oku();
        $this->goster = SertifikaDurumu::uyarilmali() && (auth()->user()?->role?->is_admin);
    }

    public function render(): View
    {
        return view('components.ui.sertifika-uyarisi');
    }
}
