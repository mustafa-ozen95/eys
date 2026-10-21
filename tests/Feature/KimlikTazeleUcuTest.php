<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserStatus;
use App\Models\User;
use App\Models\UserIdentity;
use App\Services\Kimlik\HesapEslestirici;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Merkezin "bu kimlik değişti" bildirimi.
 *
 * Uç OTURUM TAŞIMAZ: çağıran bir insan değil, merkez. Güvenliği iki katmanlı:
 * imza (bildirim sırrıyla HMAC) ve gelen gövdeye hiç güvenmemek — yeni rol ya
 * da durum gövdeden okunmaz, panel doğruyu merkezden kendi sorar.
 */
final class KimlikTazeleUcuTest extends TestCase
{
    use RefreshDatabase;

    private const SIR = 'sinama-bildirim-sirri';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'kimlik.acik' => true,
            /* Ayar env()'den hesaplanıyor; testte elle açılır. */
            'kimlik.yapilandirildi' => true,
            'kimlik.adres' => 'https://merkez.ornek.test',
            'kimlik.istemci' => 'istemci',
            'kimlik.sir' => 'oauth-sirri',
            'kimlik.bildirim_sirri' => self::SIR,
        ]);
    }

    private function kimlik(UserStatus $durum = UserStatus::Active): UserIdentity
    {
        $kullanici = User::factory()->create(['status' => $durum]);

        return UserIdentity::create([
            'user_id' => $kullanici->id,
            'provider' => HesapEslestirici::SAGLAYICI,
            'subject' => (string) Str::uuid(),
            'email' => $kullanici->email,
        ]);
    }

    /** @param  array<string, mixed>  $durum */
    private function merkezSoyluyor(string $subject, array $durum): void
    {
        Http::fake(['*/api/kimlik-durumu' => Http::response(['kimlikler' => [$subject => $durum]])]);
    }

    private function bildir(string $subject, ?string $imza = null): TestResponse
    {
        return $this->withHeaders(['X-Kimlik-Imza' => $imza ?? hash_hmac('sha256', $subject, self::SIR)])
            ->postJson(route('api.kimlik.tazele'), ['subject' => $subject]);
    }

    #[Test]
    public function imzali_bildirim_merkezdeki_durumu_uygular(): void
    {
        $bag = $this->kimlik(UserStatus::Passive);
        $this->merkezSoyluyor($bag->subject, ['durum' => 'etkin', 'girebilir' => true, 'rol' => null]);

        $this->bildir($bag->subject)->assertOk();

        $this->assertSame(UserStatus::Active, $bag->user->fresh()->status);
    }

    #[Test]
    public function imzasiz_bildirim_hicbir_sey_yapmaz(): void
    {
        Http::fake();

        $bag = $this->kimlik(UserStatus::Passive);

        $this->bildir($bag->subject, 'yanlis-imza')->assertOk();

        $this->assertSame(UserStatus::Passive, $bag->user->fresh()->status);
        Http::assertNothingSent();
    }

    /**
     * SIR TANIMSIZSA bildirim kabul edilmez.
     *
     * İmzasız çalışan bir uç, ayarın eksik olduğunu kimseye söylemeden açık
     * kalırdı. O panel değişikliği beş dakikalık turla öğrenir.
     */
    #[Test]
    public function sir_tanimsizken_bildirim_kabul_edilmez(): void
    {
        config(['kimlik.bildirim_sirri' => null]);
        Http::fake();

        $bag = $this->kimlik(UserStatus::Passive);

        $this->bildir($bag->subject, hash_hmac('sha256', $bag->subject, ''))->assertOk();

        $this->assertSame(UserStatus::Passive, $bag->user->fresh()->status);
        Http::assertNothingSent();
    }

    /**
     * YEREL KARŞILIĞI OLMAYAN kimlik için merkeze GİDİLMEZ.
     *
     * Bildirim bütün panellere gidiyor ve her panelde her kullanıcının hesabı
     * olmak zorunda değil; bilinmeyen kimlik için merkeze soru sormak, bu ucu
     * merkeze yük bindiren bir kaldıraca çevirirdi.
     */
    #[Test]
    public function bilinmeyen_kimlik_icin_merkeze_sorulmaz(): void
    {
        Http::fake();

        $this->bildir((string) Str::uuid())->assertOk();

        Http::assertNothingSent();
    }

    /** Yanıt HER ZAMAN aynı: "bu kimlik burada var mı" sorusu buradan öğrenilemez. */
    #[Test]
    public function yanit_bilgi_sizdirmaz(): void
    {
        $bag = $this->kimlik();
        $this->merkezSoyluyor($bag->subject, ['durum' => 'etkin', 'girebilir' => true, 'rol' => null]);

        $varOlan = $this->bildir($bag->subject)->getContent();

        Http::fake();
        $olmayan = $this->bildir((string) Str::uuid())->getContent();
        $imzasiz = $this->bildir($bag->subject, 'yanlis')->getContent();

        $this->assertSame($varOlan, $olmayan);
        $this->assertSame($varOlan, $imzasiz);
    }
}
