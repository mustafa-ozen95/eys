<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * API jetonlarının yönetim ekranı.
 *
 * Jeton bir KİMLİK BİLGİSİDİR: sahibi adına konuşur ve onun yetkilerini
 * kalıcı bir anahtara dönüştürür. Bu yüzden buradaki testler ekranın
 * çalıştığını değil, KAPININ KAPALI olduğunu kilitler: yalnızca yönetici
 * jeton çıkarabilir, sır bir kez görünür ve sahibi sonradan değişemez.
 */
class ApiTokenScreenTest extends TestCase
{
    use RefreshDatabase;

    private function jeton(User $sahip, string $ad = 'NYS'): ApiToken
    {
        $yeni = ApiToken::mint();

        return ApiToken::create([
            'name' => $ad,
            'user_id' => $sahip->id,
            'token_hash' => $yeni['hash'],
            'prefix' => $yeni['prefix'],
            'abilities' => ['credential_group' => ['view']],
        ]);
    }

    #[Test]
    public function ekran_yalnizca_yoneticide(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('api-tokens.index'))
            ->assertForbidden();

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('api-tokens.index'))
            ->assertOk();
    }

    /**
     * Jeton çıkarmak, seçilen kişinin yetkisini kalıcı bir anahtara
     * çevirmektir; yönetici olmayan bunu yapamaz.
     */
    #[Test]
    public function yonetici_olmayan_jeton_cikaramaz(): void
    {
        $sahip = User::factory()->create();

        $this->actingAs(User::factory()->create())
            ->post(route('api-tokens.store'), [
                'name' => 'Kaçak',
                'user' => $sahip->uuid,
            ])
            ->assertForbidden();

        $this->assertSame(0, ApiToken::query()->count());
    }

    /**
     * Sır YALNIZCA çıkarıldığı anda görünür ve veritabanında ÖZET olarak
     * durur; ekran onu bir daha gösteremez.
     */
    #[Test]
    public function sir_bir_kez_gosterilir_ve_ozet_olarak_saklanir(): void
    {
        $admin = User::factory()->admin()->create();
        $sahip = User::factory()->create();

        $yanit = $this->actingAs($admin)
            ->post(route('api-tokens.store'), [
                'name' => 'NYS',
                'user' => $sahip->uuid,
                'abilities' => ['credential_group' => ['view']],
            ])
            ->assertSessionHas('token_plain');

        $sir = session('token_plain');
        $jeton = ApiToken::query()->firstOrFail();

        $yanit->assertRedirect(route('api-tokens.show', $jeton));

        // Sır ham hâliyle HİÇBİR YERDE durmaz.
        $this->assertNotSame($sir, $jeton->token_hash);
        $this->assertSame(hash('sha256', $sir), $jeton->token_hash);
        $this->assertStringStartsWith((string) config('api.prefix'), $sir);

        /*
         * Sır oturumda TEK SEFERLİK durur: yönlendirmenin götürdüğü sayfada
         * görünür, o sayfa bir daha açıldığında yoktur. Kaybolursa geri
         * gösterilmez; yenisi üretilir.
         */
        $this->actingAs($admin)
            ->get(route('api-tokens.show', $jeton))
            ->assertOk()
            ->assertSee($sir);

        $this->actingAs($admin)
            ->get(route('api-tokens.show', $jeton))
            ->assertOk()
            ->assertDontSee($sir);
    }

    /**
     * Sahibi DEĞİŞMEZ: aynı sır başka birinin yetkilerine açılan bir kapı
     * olurdu.
     */
    #[Test]
    public function jetonun_sahibi_sonradan_degistirilemez(): void
    {
        $admin = User::factory()->admin()->create();
        $sahip = User::factory()->create();
        $baskasi = User::factory()->create();
        $jeton = $this->jeton($sahip);

        $this->actingAs($admin)
            ->from(route('api-tokens.show', $jeton))
            ->put(route('api-tokens.update', $jeton), [
                'name' => 'NYS',
                'user' => $baskasi->uuid,
            ])
            ->assertSessionHasErrors('user');

        $this->assertSame($sahip->id, $jeton->fresh()->user_id);
    }

    /**
     * Tanımsız yetki satırı sessizce ATILIR; jeton yeni bir yetki türü icat
     * edemez.
     */
    #[Test]
    public function tanimsiz_yetki_kaydedilmez(): void
    {
        $admin = User::factory()->admin()->create();
        $jeton = $this->jeton(User::factory()->create());

        $this->actingAs($admin)
            ->put(route('api-tokens.update', $jeton), [
                'name' => 'NYS',
                'abilities' => [
                    'credential_group' => ['view', 'uydurma'],
                    'uydurma_kaynak' => ['view'],
                ],
            ])
            ->assertRedirect();

        $this->assertSame(['credential_group' => ['view']], $jeton->fresh()->abilityMap());
    }

    /**
     * Sır değişince ESKİSİ anında geçersiz olur.
     */
    #[Test]
    public function sir_degisince_eski_jeton_gecersizdir(): void
    {
        $admin = User::factory()->admin()->create();
        $jeton = $this->jeton(User::factory()->create());
        $eskiOzet = $jeton->token_hash;

        $this->actingAs($admin)
            ->put(route('api-tokens.rotate', $jeton))
            ->assertSessionHas('token_plain');

        $this->assertNotSame($eskiOzet, $jeton->fresh()->token_hash);
    }

    /**
     * İptal SİLMEZ: kimin hangi jetonla ne yaptığı kayıtta durmalı.
     */
    #[Test]
    public function iptal_kaydi_silmez(): void
    {
        $admin = User::factory()->admin()->create();
        $jeton = $this->jeton(User::factory()->create());

        $this->actingAs($admin)
            ->put(route('api-tokens.revoke', $jeton))
            ->assertRedirect();

        $this->assertNotNull($jeton->fresh()->revoked_at);
    }

    /**
     * Sıralı birincil anahtar dışarı çıkmaz; adres uuid taşır.
     */
    #[Test]
    public function sirali_anahtarla_adreslenemez(): void
    {
        $admin = User::factory()->admin()->create();
        $jeton = $this->jeton(User::factory()->create());

        $this->actingAs($admin)
            ->get(route('api-tokens.index'))
            ->assertOk()
            ->assertSee($jeton->uuid)
            ->assertDontSee('/api-jetonlari/'.$jeton->id);
    }
}
