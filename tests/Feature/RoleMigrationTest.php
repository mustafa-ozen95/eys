<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Rollerin veriye taşındığı göç, ÖRNEK VERİ KAPALIYKEN.
 *
 * Öbür sınamalar örnek veri açık koşuyor (SAMPLE_DATA'nin varsayılanı true),
 * üretim kurulumunda ise kapalı tutulur. Göçün kapalı hâli sınanmazsa
 * sıfırdan kurulum o hâlde "Undefined array key network" gibi bir hatayla
 * çökebilir.
 *
 * RefreshDatabase KULLANILMAZ: göçlerin kendisi sınanıyor. Bellekteki
 * veritabanı her sınamada boş başlar.
 */
class RoleMigrationTest extends TestCase
{
    private const GOC = '2026_01_03_000100_create_roles_table';

    #[Test]
    public function ornek_verisiz_sifirdan_kurulum_tamamlanir(): void
    {
        config(['app.sample_data' => false]);

        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));

        $this->assertSame(['admin'], DB::table('roles')->where('is_admin', true)->pluck('slug')->all());
        $this->assertFalse(DB::table('roles')->whereIn('slug', ['network', 'teknik'])->exists());
    }

    #[Test]
    public function kullanicili_kurulum_yukseltilirken_kimse_yonetici_yapilmaz(): void
    {
        config(['app.sample_data' => false]);

        $oncekiler = collect(glob(database_path('migrations/*.php')))
            ->filter(fn (string $dosya): bool => strcmp(basename($dosya, '.php'), self::GOC) < 0)
            ->sort()
            ->values()
            ->all();

        $this->assertSame(0, Artisan::call('migrate', ['--path' => $oncekiler, '--realpath' => true, '--force' => true]));

        /* Eski enum'un değerleri ve tanınmayan bir değer. */
        foreach (['admin', 'network', 'teknik', 'editor', 'viewer', 'tanimsiz'] as $eski) {
            DB::table('users')->insert([
                'uuid' => (string) Str::uuid7(),
                'name' => $eski,
                'email' => $eski.'@ornek.test',
                'password' => 'x',
                'role' => $eski,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));

        $roller = DB::table('users')
            ->join('roles', 'roles.id', '=', 'users.role_id')
            ->pluck('roles.slug', 'users.name')
            ->all();
        ksort($roller);

        /* Yönetici yalnızca zaten yönetici olan; tanınmayan en dar role düşer. */
        $this->assertSame([
            'admin' => 'admin',
            'editor' => 'network',
            'network' => 'network',
            'tanimsiz' => 'teknik',
            'teknik' => 'teknik',
            'viewer' => 'teknik',
        ], $roller);
    }
}
