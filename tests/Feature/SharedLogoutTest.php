<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserStatus;
use App\Models\User;
use App\Models\UserIdentity;
use App\Services\Identity\AccountMatcher;
use App\Services\Identity\SharedLogout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ORTAK ÇIKIŞ — panel tarafı: EYS'den çıkış yapınca merkezden (KYM) de
 * çıkış olur.
 *
 * Merkezdeki son çıkıştan önce açılmış oturum kapanır; an bildirimle, durum
 * sorgusuyla ya da bu panelden çıkışla gelir. Bu panelden çıkış merkeze de
 * bildirilir.
 */
final class SharedLogoutTest extends TestCase
{
    use RefreshDatabase;

    private const SIR = 'sinama-bildirim-sirri';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'identity.acik' => true,
            'identity.yapilandirildi' => true,
            'identity.adres' => 'https://merkez.ornek.test',
            'identity.istemci' => 'istemci',
            'identity.sir' => 'oauth-sirri',
            'identity.bildirim_sirri' => self::SIR,
        ]);
    }

    private function bag(): UserIdentity
    {
        $kullanici = User::factory()->create(['status' => UserStatus::Active]);

        return UserIdentity::create([
            'user_id' => $kullanici->id,
            'provider' => AccountMatcher::SAGLAYICI,
            'subject' => (string) Str::uuid(),
            'email' => $kullanici->email,
        ]);
    }

    private function cikisBildir(string $subject, int $an, ?string $imza = null): TestResponse
    {
        return $this->withHeaders(['X-Kimlik-Imza' => $imza ?? hash_hmac('sha256', 'cikis|'.$subject.'|'.$an, self::SIR)])
            ->postJson(route('api.kimlik.cikis'), ['subject' => $subject, 'an' => $an]);
    }

    #[Test]
    public function imzali_cikis_bildirimi_ani_yazar_eskisi_geri_almaz(): void
    {
        $bag = $this->bag();

        $this->cikisBildir($bag->subject, 5_000, 'yanlis')->assertOk();
        $this->assertNull($bag->fresh()->merkez_cikis_ms, 'imzasız bildirim uygulanmaz');

        /* İmza anı da kapsar: başka anla gönderilen imza tutmaz. */
        $this->withHeaders(['X-Kimlik-Imza' => hash_hmac('sha256', 'cikis|'.$bag->subject.'|1', self::SIR)])
            ->postJson(route('api.kimlik.cikis'), ['subject' => $bag->subject, 'an' => 9_999])->assertOk();
        $this->assertNull($bag->fresh()->merkez_cikis_ms);

        $this->cikisBildir($bag->subject, 5_000)->assertOk();
        $this->assertSame(5_000, $bag->fresh()->merkez_cikis_ms);

        $this->cikisBildir($bag->subject, 4_000)->assertOk();
        $this->assertSame(5_000, $bag->fresh()->merkez_cikis_ms, 'geç gelen eski an yeni girişi kapatmaz');
    }

    #[Test]
    public function cikistan_once_acilan_oturum_kapanir_sonraki_kalir(): void
    {
        $bag = $this->bag();
        $bag->forceFill(['merkez_cikis_ms' => 2_000])->save();
        Cache::put('kimlik:dogrulama:'.$bag->user_id, true, 60);

        $this->actingAs($bag->user)->withSession([SharedLogout::OTURUM_ANAHTARI => 1_000])
            ->get(route('credentials.index'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->actingAs($bag->user)->withSession([SharedLogout::OTURUM_ANAHTARI => 3_000])
            ->get(route('credentials.index'))
            ->assertOk();
    }

    #[Test]
    public function giris_oturumun_acilis_anini_yazar(): void
    {
        $bag = $this->bag();
        $once = SharedLogout::simdiMs();

        $this->get('/');
        Auth::login($bag->user);

        $this->assertGreaterThanOrEqual($once, (int) session(SharedLogout::OTURUM_ANAHTARI));
    }

    #[Test]
    public function panelden_cikis_merkeze_bildirilir(): void
    {
        Http::fake(['*/api/oturum-kapandi' => Http::response(['sonuc' => 'alındı'])]);
        $bag = $this->bag();

        $this->actingAs($bag->user)->post(route('logout'))->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertGreaterThan(0, (int) $bag->fresh()->merkez_cikis_ms, 'bu paneldeki öteki oturumlar da kapanır');
        Http::assertSent(fn ($istek): bool => $istek->url() === 'https://merkez.ornek.test/api/oturum-kapandi'
            && $istek['subject'] === $bag->subject && $istek['client_id'] === 'istemci');
    }

    #[Test]
    public function merkez_yanit_vermese_de_cikis_olur(): void
    {
        Http::fake(['*/api/oturum-kapandi' => Http::response('', 503)]);
        $bag = $this->bag();

        $this->actingAs($bag->user)->post(route('logout'))->assertRedirect(route('login'));

        $this->assertGuest();
    }

    #[Test]
    public function durum_sorgusundaki_son_cikis_uygulanir(): void
    {
        $bag = $this->bag();
        Http::fake(['*/api/kimlik-durumu' => Http::response(['kimlikler' => [$bag->subject => [
            'durum' => 'etkin', 'girebilir' => true, 'rol' => null, 'son_cikis' => 7_000,
        ]]])]);

        $this->withHeaders(['X-Kimlik-Imza' => hash_hmac('sha256', $bag->subject, self::SIR)])
            ->postJson(route('api.kimlik.tazele'), ['subject' => $bag->subject])->assertOk();

        $this->assertSame(7_000, $bag->fresh()->merkez_cikis_ms);
    }

    /** Ekran kilidinin çıkışı (süre doldu) yalnız bu oturumu kapatır; merkeze bildirilmez. */
    #[Test]
    public function zaman_asimi_cikisi_ortak_degildir(): void
    {
        Http::fake();
        $bag = $this->bag();

        $this->actingAs($bag->user)->withHeaders(['X-Oturum-Zaman-Asimi' => '1'])
            ->post(route('logout'))->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertNull($bag->fresh()->merkez_cikis_ms);
        Http::assertNotSent(fn ($istek): bool => str_ends_with($istek->url(), '/api/oturum-kapandi'));
    }
}
