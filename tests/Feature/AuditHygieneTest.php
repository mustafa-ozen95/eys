<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\User;
use App\Services\Settings\SettingsRepository;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobProcessing;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * İptal edilmiş jeton sır yenilemeyle dirilmez; kuyruk işçisi ayar
 * değişikliğini işten önce görür.
 */
class AuditHygieneTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function iptal_edilmis_jeton_sir_yenilemeyle_yeniden_etkinlesmez(): void
    {
        $admin = User::factory()->admin()->create();
        $yeni = ApiToken::mint();
        $jeton = ApiToken::create([
            'name' => 'NYS', 'user_id' => User::factory()->create()->id, 'token_hash' => $yeni['hash'],
            'prefix' => $yeni['prefix'], 'abilities' => ['credential_group' => ['view']],
        ]);
        $jeton->revoke();
        $eskiOzet = $jeton->fresh()->token_hash;

        $this->actingAs($admin)->put(route('api-tokens.rotate', $jeton))->assertSessionHasErrors('token');

        $jeton->refresh();
        $this->assertNotNull($jeton->revoked_at);
        $this->assertFalse($jeton->isUsable());
        $this->assertSame($eskiOzet, $jeton->token_hash, 'İptal edilmişin sırrı değişmemeli.');
        $this->actingAs($admin)->get(route('api-tokens.show', $jeton))->assertOk()->assertDontSee('type="submit" form="jeton-sir-yenile"', false);
    }

    #[Test]
    public function etkin_jetonun_sirri_yenilenir_ve_modelden_iptal_silinmez(): void
    {
        $yeni = ApiToken::mint();
        $jeton = ApiToken::create([
            'name' => 'NYS', 'user_id' => User::factory()->create()->id, 'token_hash' => $yeni['hash'],
            'prefix' => $yeni['prefix'], 'abilities' => [],
        ]);

        $jeton->rotate();
        $this->assertNotSame($yeni['hash'], $jeton->fresh()->token_hash);

        /* Model düzeyinde de: rotate iptali geri almaz. */
        $jeton->revoke();
        $jeton->rotate();
        $this->assertNotNull($jeton->fresh()->revoked_at);
    }

    #[Test]
    public function kuyruk_isci_ayar_degisikligini_isten_once_gorur(): void
    {
        config(['rotation.enabled' => true]);

        /* Admin ekranından kapatıldı; işçi süreci hâlâ eski config'i taşıyor. */
        app(SettingsRepository::class)->put(['rotation.enabled' => false]);
        $this->assertTrue(config('rotation.enabled'), 'Kurgu: işçide config eski.');

        event(new JobProcessing('redis', $this->createStub(Job::class)));

        $this->assertFalse(config('rotation.enabled'), 'İş başlamadan önce ayar yeniden uygulanmalı.');
    }
}
