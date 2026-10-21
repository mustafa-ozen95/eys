<?php

declare(strict_types=1);

namespace App\Services\Settings;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Ayarları okur, yazar ve config üzerine bindirir.
 *
 * Satırı olmayan ayar VARSAYILANINDA kalır; bir ayarı sıfırlamak satırı
 * silmektir. Böylece config dosyası her zaman geçerli bir taban olur ve
 * veritabanı yalnızca farkları taşır.
 */
class SettingsRepository
{
    private const CACHE_KEY = 'settings:overrides';

    public function __construct(private SettingsCatalog $catalog) {}

    /** @return array<string, mixed> */
    public function all(): array
    {
        /*
         * Önbellek erişilemezse ayarlar doğrudan veritabanından okunur.
         * Önbelleğin düşmesi, panelin yapılandırmasını sessizce varsayılana
         * döndürmemeli — döndürme kapatılmışsa kapalı kalmalı.
         */
        try {
            return Cache::rememberForever(self::CACHE_KEY, fn (): array => $this->read());
        } catch (Throwable) {
            return $this->read();
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    /** Ekranda gösterilecek değer: bindirme varsa o, yoksa config varsayılanı. */
    public function current(string $key): mixed
    {
        return $this->all()[$key] ?? config($key);
    }

    /**
     * Bindirmeleri config'e uygular.
     *
     * config:cache sırasında ATLANIR: o komut config dizisini dosyaya yazıyor
     * ve bindirme o an çalışırsa veritabanındaki değerler dosyaya gömülür —
     * ayar silindiğinde varsayılana dönmez. Çalışma anında zaten her istekte
     * uygulanıyor.
     */
    public function apply(): void
    {
        if (app()->runningConsoleCommand('config:cache', 'optimize')) {
            return;
        }

        foreach ($this->all() as $key => $value) {
            if ($this->catalog->has($key)) {
                config([$key => $value]);

                /*
                 * Saat dilimi config'e yazmakla değişmez: Laravel PHP'nin varsayılan
                 * dilimini AÇILIŞTA bir kez kuruyor ve sonraki config değişikliği ona
                 * ulaşmıyor. Ayarlanmış olsa bile bütün tarihler eski dilimde kalırdı.
                 */
                $dilim = config('app.timezone');

                if (is_string($dilim) && $dilim !== '') {
                    if ($dilim !== date_default_timezone_get()) {
                        date_default_timezone_set($dilim);
                    }

                    /*
                     * Gösterim dilimi de birlikte gider; APP_DISPLAY_TIMEZONE ile
                     * bilerek ayrılmadıysa iki ayrı dilim ortaya çıkmasın.
                     */
                    if (env('APP_DISPLAY_TIMEZONE') === null) {
                        config(['app.display_timezone' => $dilim]);
                    }
                }

            }
        }
    }

    /**
     * Verilen ayarları yazar ve DEĞİŞENLERİ döndürür.
     *
     * Değişmeyen alan yazılmaz: her kaydedişte bütün satırlara dokunmak,
     * denetim kaydını "kim neyi değiştirdi" sorusuna cevap veremez hâle
     * getirirdi.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, array{from: mixed, to: mixed}>
     */
    public function put(array $values, ?User $actor = null): array
    {
        $changes = [];

        DB::transaction(function () use ($values, $actor, &$changes): void {
            foreach ($values as $key => $value) {
                $definition = $this->catalog->definition($key);

                if ($definition === null) {
                    continue;
                }

                $value = $definition->cast($value);
                $before = $this->current($key);

                if ($before === $value) {
                    continue;
                }

                Setting::updateOrCreate(
                    ['key' => $key],
                    ['value' => json_encode($value, JSON_UNESCAPED_UNICODE), 'updated_by' => $actor?->getKey()],
                );

                $changes[$key] = ['from' => $before, 'to' => $value];
            }
        });

        $this->flush();

        return $changes;
    }

    public function flush(): void
    {
        try {
            Cache::forget(self::CACHE_KEY);
        } catch (Throwable) {
            // Önbellek yoksa yapacak bir şey de yok.
        }
    }

    /** @return array<string, mixed> */
    private function read(): array
    {
        try {
            $rows = Setting::query()->pluck('value', 'key');
        } catch (Throwable) {
            /* Tablo henüz yoksa (ilk migration öncesi) varsayılanlar geçerli. */
            return [];
        }

        return $rows
            ->map(fn (?string $value): mixed => $value === null ? null : json_decode($value, true))
            ->all();
    }
}
