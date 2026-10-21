<?php

declare(strict_types=1);

namespace App\View\Components\Nav;

use App\Models\ActivityLog;
use App\Models\Credential;
use App\Models\CredentialGroup;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\View\Component;
use Illuminate\View\View;

class Topbar extends Component
{
    public string $initials;

    /** @var array<int, array{label: string, url: string, active: bool}> */
    public array $sections;

    public function __construct()
    {
        $this->initials = self::initialsFor(auth()->user()?->name);
        $this->sections = $this->visibleSections();
    }

    /**
     * Menüde yalnızca kullanıcının erişebildiği bölümler görünür.
     *
     * Erişemeyeceği bir bağlantıyı göstermek 403 üretmekten başka bir işe
     * yaramaz; menü de yetkinin bir yansımasıdır.
     *
     * @return array<int, array{label: string, url: string, active: bool}>
     */
    private function visibleSections(): array
    {
        $candidates = [
            ['label' => 'Erişim bilgileri', 'route' => 'credentials.index', 'ability' => 'viewAny', 'model' => Credential::class],
            ['label' => 'Gruplar', 'route' => 'groups.index', 'ability' => 'create', 'model' => CredentialGroup::class],
            ['label' => 'Kullanıcılar', 'route' => 'users.index', 'ability' => 'viewAny', 'model' => User::class],
            /*
             * Hata kayıtları menüde AYRI bir bölüm değil: "Kayıtlar"ın içinde
             * sekme olarak duruyor (bkz. App\View\Components\Logs\Tabs).
             * Menü altı bölümle kalabalıklaşıyordu; ikisi de aynı işi yapan,
             * yalnızca okunur ekranlar.
             */
            ['label' => 'Kayıtlar', 'route' => 'logs.index', 'pattern' => 'logs.*|errors.*', 'ability' => 'viewAny', 'model' => ActivityLog::class],

            /*
             * Ayarlar en sonda: günlük iş bölümleri önce, yapılandırma sonra.
             *
             * Kalıp, Ayarlar şeridindeki (SettingsTabs) BÜTÜN sayfaları
             * kapsamalıdır; biri unutulursa o sayfada üstteki "Ayarlar"
             * vurgusu sönüyor ve kullanıcı nerede olduğunu kaybediyor.
             * `api-tokens.*` tam olarak bu yüzden eksikti.
             */
            ['label' => 'Ayarlar', 'route' => 'settings.index', 'pattern' => 'settings.*|roles.*|api-tokens.*', 'ability' => 'viewAny', 'model' => Setting::class],
        ];

        $sections = [];

        foreach ($candidates as $candidate) {
            if (! Gate::allows($candidate['ability'], $candidate['model'])) {
                continue;
            }

            $sections[] = [
                'label' => $candidate['label'],
                'url' => route($candidate['route']),
                'active' => Route::is(...explode('|', $candidate['pattern'] ?? Str::before($candidate['route'], '.').'.*')),
            ];
        }

        return $sections;
    }

    public static function initialsFor(?string $name): string
    {
        if ($name === null || trim($name) === '') {
            return '?';
        }

        return Str::of($name)
            ->squish()
            ->explode(' ')
            ->take(2)
            ->map(fn (string $part): string => Str::upper(Str::substr($part, 0, 1)))
            ->implode('');
    }

    public function render(): View
    {
        return view('components.nav.topbar');
    }
}
