<?php

declare(strict_types=1);

namespace Tests\Feature\Remote;

use App\Enums\AccessType;
use App\Enums\UserStatus;
use App\Models\Credential;
use App\Models\CredentialGroup;
use App\Models\KonsolOturumu;
use App\Models\User;
use App\Services\Authorization\UserPermissionResolver;
use App\Services\Remote\RemoteSessionStore;
use App\Services\Remote\TtydManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * KAPANIŞ HABERİ HER ZAMAN GELMEZ.
 *
 * Kullanıcı düğmeye basarsa, pencereyi kapatırsa ya da sekmeden çıkarsa
 * tarayıcı haber veriyor. Ama tarayıcı ÇÖKERSE, makine uykuya girerse ya da
 * ağ koparsa kimse haber veremez; o zamana kadar kayıtta "açıldı" satırı
 * olup "kapandı" satırı olmuyordu — bir oturumun ne zaman bittiği denetimde
 * hiç yazmıyordu.
 *
 * Bu testler izin her iki yoldan da kapandığını kanıtlar.
 */
final class KonsolIziTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['*/api/tokens' => Http::response(['authToken' => 'sahte', 'dataSource' => 'json'])]);

        $this->mock(TtydManager::class, function ($mock): void {
            $mock->shouldReceive('start')->andReturnUsing(function ($target, string $token): array {
                app(RemoteSessionStore::class)->reservePort(7501, $token);

                return ['port' => 7501, 'path' => '/ssh-proxy/7501/'];
            });

            $mock->shouldReceive('stop')->andReturnNull();
        });
    }

    public function test_acilan_oturum_ize_yazilir(): void
    {
        [$user, $credential] = $this->hazirla();
        $jeton = $this->ac($user, $credential);

        $iz = KonsolOturumu::query()->where('token', $jeton)->firstOrFail();

        $this->assertSame($user->id, $iz->user_id);
        $this->assertSame($credential->id, $iz->credential_id);
        $this->assertSame(7501, $iz->port);
        $this->assertNull($iz->closed_at);
    }

    public function test_kullanici_kapatinca_iz_kapanir(): void
    {
        [$user, $credential] = $this->hazirla();
        $jeton = $this->ac($user, $credential);

        $this->actingAs($user)
            ->postJson(route('ajax.remote.disconnect'), ['token' => $jeton])
            ->assertOk();

        $iz = KonsolOturumu::query()->where('token', $jeton)->firstOrFail();

        $this->assertNotNull($iz->closed_at);
        $this->assertSame('kullanici', $iz->closed_reason);
    }

    public function test_canli_tutma_izin_sesini_tazeler(): void
    {
        [$user, $credential] = $this->hazirla();
        $jeton = $this->ac($user, $credential);

        KonsolOturumu::query()->where('token', $jeton)->update(['last_seen_at' => now()->subMinutes(5)]);

        $this->actingAs($user)
            ->postJson(route('ajax.remote.keep-alive'), ['token' => $jeton])
            ->assertJsonPath('alive', true);

        $iz = KonsolOturumu::query()->where('token', $jeton)->firstOrFail();

        $this->assertTrue($iz->last_seen_at->greaterThan(now()->subMinute()));
    }

    /**
     * TARAYICI ÇÖKERSE: kimse haber vermez, izi zamanlayıcı kapatır.
     */
    public function test_sesi_kesilen_oturum_zamanlayiciyla_kapanir(): void
    {
        [$user, $credential] = $this->hazirla();
        $jeton = $this->ac($user, $credential);

        /* Canlı tutma kesileli çok oldu: tarayıcı gitmiş. */
        KonsolOturumu::query()->where('token', $jeton)->update(['last_seen_at' => now()->subMinutes(30)]);

        $this->artisan('erisim:konsol-temizle')->assertSuccessful();

        $iz = KonsolOturumu::query()->where('token', $jeton)->firstOrFail();

        $this->assertNotNull($iz->closed_at);
        $this->assertSame('dustu', $iz->closed_reason);

        /* Kayıt da düşer ve DÜŞEN kişinin adına yazılır. */
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'console_closed',
            'user_id' => $user->id,
        ]);
    }

    /** Süren oturuma dokunulmaz: bir saniyelik kesinti oturumu düşürmemeli. */
    public function test_suren_oturum_kapatilmaz(): void
    {
        [$user, $credential] = $this->hazirla();
        $jeton = $this->ac($user, $credential);

        $this->artisan('erisim:konsol-temizle')->assertSuccessful();

        $this->assertNull(KonsolOturumu::query()->where('token', $jeton)->value('closed_at'));
    }

    /** @return array{0: User, 1: Credential} */
    private function hazirla(): array
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
            ['view' => [$group->getKey()], 'connect' => [$group->getKey()]],
        );

        return [$user->fresh(), $credential];
    }

    private function ac(User $user, Credential $credential): string
    {
        return $this->actingAs($user)
            ->postJson(route('ajax.remote.connect'), ['id' => $credential->uuid, 'reason' => 'bakim'])
            ->json('token');
    }
}
