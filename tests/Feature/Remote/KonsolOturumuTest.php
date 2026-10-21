<?php

declare(strict_types=1);

namespace Tests\Feature\Remote;

use App\Enums\AccessType;
use App\Enums\UserStatus;
use App\Models\Credential;
use App\Models\CredentialGroup;
use App\Models\User;
use App\Services\Authorization\UserPermissionResolver;
use App\Services\Remote\RemoteSessionStore;
use App\Services\Remote\TtydManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Konsol: kasadaki parolayı GÖSTERMEDEN uzaktaki makineye oturum açar.
 *
 * Buradaki soruların hepsi aynı kapıya çıkıyor: bir terminal açıldıktan
 * sonra ona kim ulaşabilir? Cevap tek kişi olmalı — oturumu açan, o da
 * yetkisi sürdüğü ve hesabı etkin kaldığı sürece.
 */
final class KonsolOturumuTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array<string, mixed>> */
    private array $baslatilanlar = [];

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake([
            '*/api/tokens' => Http::response(['authToken' => 'sahte-jeton', 'dataSource' => 'json']),
        ]);

        /* Gerçek ttyd süreci başlatılmaz; port ayırma davranışı korunur. */
        $this->mock(TtydManager::class, function ($mock): void {
            $mock->shouldReceive('start')->andReturnUsing(function ($target, string $token): array {
                $port = 7500 + count($this->baslatilanlar);
                $this->baslatilanlar[] = ['port' => $port];

                app(RemoteSessionStore::class)->reservePort($port, $token);

                return ['port' => $port, 'path' => "/ssh-proxy/{$port}/"];
            });

            $mock->shouldReceive('stop')->andReturnNull();
        });
    }

    public function test_yetkili_kullanici_konsol_acar(): void
    {
        [$user, $credential] = $this->hazirla();

        $this->actingAs($user)
            ->postJson(route('ajax.remote.connect'), ['id' => $credential->uuid, 'reason' => 'bakim'])
            ->assertOk()
            ->assertJsonStructure(['token', 'label', 'protocol', 'url']);

        $this->assertDatabaseHas('activity_logs', ['action' => 'console_opened']);
    }

    /**
     * KONSOL AYRI BİR YETKİDİR.
     *
     * Kaydı görebilmek, o makineye oturum açabilmek demek değildir; kural
     * CLAUDE.md'de yazılı ve burada kilitleniyor.
     */
    public function test_goruntuleme_yetkisi_konsol_actirmaz(): void
    {
        [$user, $credential] = $this->hazirla(['view']);

        $this->actingAs($user)
            ->postJson(route('ajax.remote.connect'), ['id' => $credential->uuid, 'reason' => 'bakim'])
            ->assertForbidden();
    }

    /** Konsola uygun olmayan kayıt anlaşılır bir sebeple reddedilir. */
    public function test_web_kaydi_konsoldan_acilamaz(): void
    {
        [$user, $credential] = $this->hazirla();
        $credential->update(['access_type' => AccessType::Web]);

        $this->actingAs($user)
            ->postJson(route('ajax.remote.connect'), ['id' => $credential->uuid, 'reason' => 'bakim'])
            ->assertStatus(409)
            ->assertJsonPath('message', fn (string $mesaj): bool => str_contains($mesaj, 'konsol kaydı değil'));
    }

    /* ── Proxy bekçisi ── */

    public function test_oturum_sahibi_gecer(): void
    {
        [$user, $credential] = $this->hazirla();
        $port = $this->oturumAc($user, $credential);

        $this->actingAs($user)
            ->get(route('ajax.remote.guard', ['port' => $port]))
            ->assertNoContent();
    }

    public function test_baskasinin_oturumuna_erisilemez(): void
    {
        [$user, $credential] = $this->hazirla();
        $port = $this->oturumAc($user, $credential);

        [$baskasi] = $this->hazirla();

        $this->actingAs($baskasi)
            ->get(route('ajax.remote.guard', ['port' => $port]))
            ->assertForbidden();
    }

    public function test_oturumsuz_istek_401_alir(): void
    {
        /* nginx yalnızca 401/403 anlar; yönlendirme 500'e dönüşürdü. */
        $this->get(route('ajax.remote.guard', ['port' => 7500]))
            ->assertUnauthorized();
    }

    public function test_yetki_geri_alininca_acik_terminal_de_kapanir(): void
    {
        [$user, $credential] = $this->hazirla();
        $port = $this->oturumAc($user, $credential);

        app(UserPermissionResolver::class)->syncResource(
            $user,
            'credential_group',
            [],
            ['view' => [$credential->credential_group_id]],
        );

        $this->actingAs($user->fresh())
            ->get(route('ajax.remote.guard', ['port' => $port]))
            ->assertForbidden();
    }

    /**
     * Hesap kapatılınca AÇIK DURAN terminal de kapanmalı.
     *
     * Bu uç "auth" katmanının dışında olduğu için hesabın etkinliğini
     * bekçinin kendisi sorar.
     */
    public function test_pasif_hesap_acik_terminale_erisemez(): void
    {
        [$user, $credential] = $this->hazirla();
        $port = $this->oturumAc($user, $credential);

        $user->update(['status' => UserStatus::Passive]);

        $this->actingAs($user->fresh())
            ->get(route('ajax.remote.guard', ['port' => $port]))
            ->assertForbidden();
    }

    /** Oturumu yalnızca sahibi kapatır. */
    public function test_oturumu_baskasi_kapatamaz(): void
    {
        [$user, $credential] = $this->hazirla();
        $jeton = $this->jetonAl($user, $credential);

        [$baskasi] = $this->hazirla();

        $this->actingAs($baskasi)
            ->postJson(route('ajax.remote.disconnect'), ['token' => $jeton])
            ->assertNotFound();

        $this->actingAs($user)
            ->postJson(route('ajax.remote.disconnect'), ['token' => $jeton])
            ->assertOk();

        $this->assertDatabaseHas('activity_logs', ['action' => 'console_closed']);
    }

    /**
     * @param  array<int, string>  $aksiyonlar
     * @return array{0: User, 1: Credential}
     */
    private function hazirla(array $aksiyonlar = ['view', 'connect']): array
    {
        $user = User::factory()->create(['status' => UserStatus::Active]);
        $group = CredentialGroup::factory()->create();

        $credential = Credential::factory()->for($group, 'group')->create([
            'access_type' => AccessType::Ssh,
            'host' => '192.0.2.40',
            'port' => 22,
            'username' => 'yonetici',
            'password' => 'gizli-parola',
        ]);

        app(UserPermissionResolver::class)->syncResource(
            $user,
            'credential_group',
            [],
            array_combine($aksiyonlar, array_fill(0, count($aksiyonlar), [$group->getKey()])),
        );

        return [$user->fresh(), $credential];
    }

    private function jetonAl(User $user, Credential $credential): string
    {
        return $this->actingAs($user)
            ->postJson(route('ajax.remote.connect'), ['id' => $credential->uuid, 'reason' => 'bakim'])
            ->json('token');
    }

    private function oturumAc(User $user, Credential $credential): int
    {
        $this->jetonAl($user, $credential);

        return $this->baslatilanlar[count($this->baslatilanlar) - 1]['port'];
    }

    /**
     * KRİTİK KAYITTA GEREKÇESİZ KONSOL AÇILMAZ.
     *
     * Denetimde sorulan soru "kim ne yaptı" kadar "neden yaptı"dır da.
     * Gerekçe yalnız kritik işaretli kayıtta zorunludur (bkz. GerekceKurali);
     * konsol ucu da bu kuralın dışında kalmamalı.
     */
    #[Test]
    public function kritik_kayitta_gerekcesiz_konsol_acilmaz(): void
    {
        [$user, $credential] = $this->hazirla();
        $credential->forceFill(['is_critical' => true])->save();

        $this->actingAs($user)
            ->postJson(route('ajax.remote.connect'), ['id' => $credential->uuid])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');
    }
}
