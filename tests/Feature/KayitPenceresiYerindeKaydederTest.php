<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Credential;
use App\Models\CredentialGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * KAYIT PENCERESİ SAYFAYI YENİLEMEDEN KAYDEDER.
 *
 * Form normal gönderiliyordu ve tarayıcı tam sayfa yenilemesi yapıyordu:
 * ekran beyaza düşüyor, tema ve betikler baştan yükleniyor, açık olan sağ
 * panel ile kaydırma konumu kayboluyordu.
 *
 * Panelde bunun karşılığı zaten vardı: `data-yerinde` formu fetch ile
 * gönderip dönen sayfayı gövdede değiştiriyor (bkz. navigation.js). Burada
 * kilitlenen şey, kayıt penceresinin o yolu kullandığı — işaret düşerse
 * davranış sessizce eskisine döner.
 */
class KayitPenceresiYerindeKaydederTest extends TestCase
{
    use RefreshDatabase;

    public function test_ekleme_penceresi_yerinde_gonderir(): void
    {
        $grup = CredentialGroup::factory()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('credentials.index', ['grup' => $grup->uuid, 'pencere' => 'ekle']))
            ->assertOk()
            ->assertSee('id="kayit-ekle"', false)
            ->assertSee('data-yerinde', false);
    }

    public function test_duzenleme_penceresi_yerinde_gonderir(): void
    {
        $grup = CredentialGroup::factory()->create();
        $kayit = Credential::factory()->for($grup, 'group')->create();

        $html = (string) $this->actingAs(User::factory()->admin()->create())
            ->get(route('credentials.index', [
                'grup' => $grup->uuid,
                'kayit' => $kayit->uuid,
                'pencere' => 'duzenle',
            ]))
            ->assertOk()
            ->getContent();

        $konum = strpos($html, 'id="kayit-duzenle"');

        $this->assertNotFalse($konum);
        $this->assertStringContainsString('data-yerinde', substr($html, $konum, 900));
    }
}
