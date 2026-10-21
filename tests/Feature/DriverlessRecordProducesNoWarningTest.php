<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OperatingSystem;
use App\Models\Credential;
use App\Services\Rotation\DriverRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Sürücüsü olmayan kayıt, sessizce "sürücü yok" der — uyarı üretmez.
 *
 * `rotationDriver()` uygulama hesabı, dış portal ve gömülü cihaz için null
 * döner. Anahtar null iken doğrudan `isset($dizi[$key])` yazmak PHP 8.5'te
 * "Using null as an array offset" uyarısı üretiyordu ve kasadaki her
 * sürücüsüz kayıt için bir tane çıkıyordu. PHP 9'da hataya dönüşecek.
 */
class DriverlessRecordProducesNoWarningTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function surucusuz_turlerde_anahtar_null_doner_ve_uyari_cikmaz(): void
    {
        $registry = app(DriverRegistry::class);

        $uyarilar = [];
        set_error_handler(function (int $no, string $mesaj) use (&$uyarilar): bool {
            $uyarilar[] = $mesaj;

            return true;
        }, E_ALL);

        try {
            foreach ([OperatingSystem::Application, OperatingSystem::Portal, OperatingSystem::Embedded] as $os) {
                $credential = Credential::factory()->create([
                    'operating_system' => $os,
                    'rotation_driver' => null,
                ]);

                $this->assertNull($registry->keyFor($credential), $os->value.' için sürücü olmamalı.');
                $this->assertNull($registry->for($credential));
            }
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $uyarilar, "Uyarı üretildi:\n  ".implode("\n  ", $uyarilar));
    }

    /** Sürücüsü olan tür etkilenmemeli. */
    #[Test]
    public function surucusu_olan_tur_bulunmaya_devam_eder(): void
    {
        $credential = Credential::factory()->create([
            'operating_system' => OperatingSystem::Linux,
            'rotation_driver' => null,
        ]);

        $this->assertSame('ssh', app(DriverRegistry::class)->keyFor($credential));
    }
}
