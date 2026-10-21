<?php

declare(strict_types=1);

namespace App\Services\Settings;

use App\Models\ApiToken;
use App\Models\Role;
use App\Models\Setting;
use Illuminate\Support\Facades\Gate;

/**
 * Ayarlar şeridi.
 *
 * TEK LİSTE: ayar sayfası, roller ve API jetonları aynı şeritte duruyor ve
 * üçü de buradan besleniyor. Şerit her ekranda ayrı yazılsaydı biri
 * değişince diğerleri sessizce eski kalır, kullanıcı da bir sekmeyi bir
 * ekranda görüp diğerinde göremezdi.
 *
 * Göremeyeceği sekme ŞERİTTE DE DURMAZ: erişemeyeceği bir bağlantı 403
 * üretmekten başka işe yaramaz.
 */
class SettingsTabs
{
    public function __construct(private SettingsCatalog $catalog) {}

    /**
     * @return list<array{label: string, url: string, active: bool}>
     */
    public function all(?string $current = null): array
    {
        $tabs = [];

        foreach ($this->catalog->groups() as $key => $group) {
            $tabs[] = [
                'label' => $group['label'],
                'url' => route('settings.index', ['grup' => $key]),
                'active' => $key === $current,
            ];
        }

        if (Gate::allows('viewAny', Role::class)) {
            $tabs[] = [
                'label' => 'Roller',
                'url' => route('roles.index'),
                'active' => $current === 'roller',
            ];
        }

        if (Gate::allows('viewAny', ApiToken::class)) {
            $tabs[] = [
                'label' => 'API jetonları',
                'url' => route('api-tokens.index'),
                'active' => $current === 'api',
            ];
        }

        /*
         * Sertifika: yenileme turu panellerin dışında, ortak yürütülür;
         * ekran durumu gösterir ve turu başlatır.
         */
        if (Gate::allows('viewAny', Setting::class)) {
            $tabs[] = [
                'label' => 'Sertifika',
                'url' => route('sertifika.index'),
                'active' => $current === 'sertifika',
            ];
        }

        /* Veritabanı: nerede duruyor ve uzak bir sunucuya taşınması. */
        if (Gate::allows('viewAny', Setting::class)) {
            $tabs[] = [
                'label' => 'Veritabanı',
                'url' => route('veritabani.index'),
                'active' => $current === 'veritabani',
            ];
            $tabs[] = [
                'label' => 'Güncelleme',
                'url' => route('guncelleme.index'),
                'active' => $current === 'guncelleme',
            ];
        }

        return $tabs;
    }
}
