<?php

declare(strict_types=1);

namespace Tests\Feature\Rotation;

use App\Enums\AccessType;
use App\Models\Credential;
use App\Services\Rotation\DriverRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ELLE GÜNCELLEME, "OTOMATİK" KUTUSUNA BAĞLI DEĞİLDİR.
 *
 * `rotation_enabled` kapalıyken kullanıcının KENDİ BAŞLATTIĞI tur da
 * reddediliyordu: "Bu kayıt için otomatik güncelleme açık değil". Kutunun
 * adı "otomatik"ti ve panelde zamanlanmış tur zaten yok — yani tek yolu
 * kapatıyordu. Elle tur otomatik değildir; otomatik bayrağına bağlanmamalı.
 *
 * Elle turun kapısı ZATEN AYRI: `rotate` yetkisi olmayan kimse başlatamaz
 * (bkz. RotationLauncher::reasonToSkip). Bayrak yalnızca zamanlanmış turlar
 * içindir.
 */
class ManualUpdateNotTiedToAutoFlagTest extends TestCase
{
    use RefreshDatabase;

    private function kayit(bool $otoAcik): Credential
    {
        $kayit = Credential::factory()->create([
            'host' => '192.0.2.70',
            'access_type' => AccessType::Panel,
            'username' => 'admin',
            'password' => 'panel-parolasi',
            'rotation_driver' => 'plesk',
            'rotation_enabled' => $otoAcik,
        ]);

        /*
         * Plesk sürücüsü makinenin SSH kimliğini ister ve o kimlik SEÇİLİR:
         * aynı adresli kayıt kendiliğinden kullanılmıyor (bkz.
         * MachineAccount). Buradaki soru "oto kutusu elle turu kapatıyor
         * mu" olduğu için kimlik hazır kuruluyor.
         */
        $ssh = Credential::factory()->create([
            'host' => '192.0.2.70',
            'access_type' => AccessType::Ssh,
            'username' => 'root',
            'password' => 'ssh-parolasi',
        ]);

        $kayit->forceFill(['rotation_credential_id' => $ssh->id])->save();

        return $kayit->fresh();
    }

    #[Test]
    public function oto_kapaliyken_elle_guncelleme_engellenmez(): void
    {
        $kayit = $this->kayit(otoAcik: false);

        $this->assertNull(
            app(DriverRegistry::class)->blockedReason($kayit),
            'Kutu kapalıyken de elle güncelleme yapılabilmeli.',
        );
    }

    /** Zamanlanmış tur ise kutuya bakar. */
    #[Test]
    public function otomatik_tur_kutuya_bakar(): void
    {
        $kapali = $this->kayit(otoAcik: false);

        $sebep = app(DriverRegistry::class)->autoBlockedReason($kapali);

        $this->assertNotNull($sebep);
        $this->assertStringContainsString('otomatik', mb_strtolower((string) $sebep));
    }

    #[Test]
    public function oto_acikken_otomatik_tur_da_gecer(): void
    {
        $acik = $this->kayit(otoAcik: true);

        $this->assertNull(app(DriverRegistry::class)->autoBlockedReason($acik));
    }
}
