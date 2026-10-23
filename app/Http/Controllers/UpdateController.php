<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ActivityAction;
use App\Models\Setting;
use App\Services\ActivityRecorder;
use App\Services\Settings\SettingsTabs;
use App\Services\Update\UpdateApply;
use App\Services\Update\UpdateCheck;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

/**
 * Güncelleme kontrolü: çalışan sürüm ve GitHub'daki son başarılı derleme.
 *
 * Kontrol her sabah kendiliğinden yapılır (update:check); düğme aynı
 * kontrolü hemen yapar. Yeni sürüm varsa "Şimdi güncelle" platformun deploy
 * hook'unu tetikler (UpdateApply); kurulumu platform yapar.
 *
 * Yetkilendirme: ayarları görebilen ekranı görür, değiştirebilen kontrol
 * ya da güncelleme başlatır (Setting politikası; Veritabanı sekmesiyle aynı).
 */
class UpdateController extends Controller implements HasMiddleware
{
    /** @var array<string, array{etiket: string, renk: string}> */
    private const DURUMLAR = [
        'guncel' => ['etiket' => 'güncel', 'renk' => 'green'],
        'yeni' => ['etiket' => 'yeni sürüm var', 'renk' => 'blue'],
        'ileride' => ['etiket' => 'son derlemeden ileride', 'renk' => 'yellow'],
        'ayrisik' => ['etiket' => 'main dalında değil', 'renk' => 'yellow'],
        'bilinmiyor' => ['etiket' => 'çalışan sürüm bilinmiyor', 'renk' => 'yellow'],
        'hata' => ['etiket' => 'kontrol edilemedi', 'renk' => 'red'],
    ];

    public function __construct(
        private SettingsTabs $sekmeler,
        private UpdateCheck $denetim,
        private UpdateApply $guncelleme,
        private ActivityRecorder $kayit,
    ) {}

    /** @return array<int, Middleware> */
    public static function middleware(): array
    {
        return [
            new Middleware('can:viewAny,'.Setting::class, only: ['index']),
            new Middleware('can:update,'.Setting::class, only: ['check', 'apply']),
        ];
    }

    public function index(): View
    {
        $son = $this->denetim->son();
        $calisan = $this->denetim->surum();

        return view('settings.update', [
            'sections' => $this->sekmeler->all('guncelleme'),
            'depo' => $this->denetim->depo(),
            'calisan' => $calisan,
            'calisanKisa' => $calisan === null ? 'bilinmiyor' : substr($calisan, 0, 7),
            'son' => $son === null ? null : [
                ...$son,
                'zaman' => self::zaman($son['zaman'] ?? null),
                'son_kisa' => substr((string) ($son['son'] ?? ''), 0, 7),
                'son_tarih' => self::zaman($son['son_tarih'] ?? null),
                'commitler' => array_map(fn (array $c): array => [
                    ...$c,
                    'kisa' => substr((string) $c['sha'], 0, 7),
                    'tarih' => self::zaman($c['tarih'] ?? null),
                ], $son['commitler'] ?? []),
                // Listede gösterilmeyen yeni commit sayısı ("ve N commit daha").
                'daha' => max(0, (int) ($son['geride'] ?? 0) - count($son['commitler'] ?? [])),
                // Panel güncellendiyse eski sonuç bu sürümü anlatmıyor.
                'eski' => ($son['calisan'] ?? null) !== $calisan,
            ],
            'durum' => $son === null ? null : self::DURUMLAR[$son['durum']],
            'guncellenebilir' => $this->guncelleme->mumkun(),
            'hookYok' => ! $this->guncelleme->hazir(),
        ]);
    }

    public function check(Request $istek): RedirectResponse
    {
        $sonuc = $this->denetim->denetle((string) $istek->user()->name);

        return back()->with($sonuc['durum'] === 'hata' ? 'error' : 'status', match ($sonuc['durum']) {
            'guncel' => 'Kontrol edildi: panel son sürümde.',
            'yeni' => 'Kontrol edildi: yeni sürüm var ('.($sonuc['aciklama'] ?? '').')',
            'hata' => 'Kontrol edilemedi: '.($sonuc['aciklama'] ?? ''),
            default => 'Kontrol edildi: '.($sonuc['aciklama'] ?? ''),
        });
    }

    public function apply(): RedirectResponse
    {
        try {
            $sonuc = $this->guncelleme->baslat();
        } catch (RuntimeException $e) {
            return back()->with('error', 'Güncelleme başlatılamadı: '.$e->getMessage());
        }

        $this->kayit->record(ActivityAction::UpdateStarted, 'Panel güncellemesi başlatıldı: '.$sonuc);

        return back()->with('status', 'Güncelleme başlatıldı ('.$sonuc.'). Panel birkaç dakika içinde yeni sürümle yeniden açılır; bu sırada sayfa kısa bir süre yanıt vermeyebilir.');
    }

    private static function zaman(?string $iso): ?string
    {
        try {
            return $iso === null ? null : Carbon::parse($iso)->setTimezone(config('app.timezone'))->format('d.m.Y H:i');
        } catch (Throwable) {
            return $iso;
        }
    }
}
