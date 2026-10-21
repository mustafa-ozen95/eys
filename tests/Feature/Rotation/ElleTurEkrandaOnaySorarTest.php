<?php

declare(strict_types=1);

namespace Tests\Feature\Rotation;

use App\Enums\PermissionEffect;
use App\Models\Credential;
use App\Models\CredentialGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ELLE TUR: KUTU KAPALIYKEN DE DÜĞME ÇIKAR, AMA ONAY SORAR.
 *
 * "Otomatik güncellemeye açık" kutusu kapalıyken kayıt ekranında düğme
 * yerine "otomatik güncelleme açık değil" yazıyordu — kullanıcının elle
 * başlatacağı tur da kapanmış oluyordu.
 *
 * Şimdi düğme duruyor; kapısı iki yerde:
 *  - `rotate` YETKİSİ (yetkisi olmayan düğmeyi hiç görmez),
 *  - ONAY PENCERESİ (uzaktaki makinede parola değişeceği yazılı, gerekçe
 *    isteniyor).
 *
 * Kural: elle güncelleme tek kayıtta serbesttir, yetki dahilinde ve onay
 * penceresiyle sorularak yapılır.
 */
class ElleTurEkrandaOnaySorarTest extends TestCase
{
    use RefreshDatabase;

    private function yetkilendir(User $user, CredentialGroup $group, array $actions): void
    {
        foreach ($actions as $action) {
            $user->permissions()->create([
                'resource' => 'credential_group',
                'resource_id' => $group->getKey(),
                'action' => $action,
                'effect' => PermissionEffect::Allow,
            ]);
        }

        $user->unsetRelation('permissions');
    }

    private function kayit(CredentialGroup $group): Credential
    {
        return Credential::factory()->for($group, 'group')->create([
            'name' => 'Plesk sunucusu',
            'host' => '192.0.2.90',
            'username' => 'root',
            'password' => 'eski-parola',
            'rotation_enabled' => false,
        ]);
    }

    #[Test]
    public function oto_kapaliyken_de_guncelle_dugmesi_ve_onay_penceresi_cikar(): void
    {
        $user = User::factory()->create();
        $group = CredentialGroup::factory()->create();
        $this->yetkilendir($user, $group, ['view', 'rotate', 'field.password']);

        $kayit = $this->kayit($group);

        $yanit = $this->actingAs($user)
            ->get(route('credentials.index', ['grup' => $group->uuid, 'kayit' => $kayit->uuid]))
            ->assertOk();

        $yanit->assertSee('Parolayı güncelle');

        /* Onay penceresi: uzaktaki makinede değişeceği yazılı olmalı. */
        $yanit->assertSee('UZAKTAKİ MAKİNEDE', false);

        $yanit->assertDontSee('otomatik güncelleme açık değil');
    }

    /** Yetkisi olmayan düğmeyi görmez. */
    #[Test]
    public function yetkisiz_kullanici_dugmeyi_gormez(): void
    {
        $user = User::factory()->create();
        $group = CredentialGroup::factory()->create();
        $this->yetkilendir($user, $group, ['view', 'field.password']);

        $kayit = $this->kayit($group);

        $this->actingAs($user)
            ->get(route('credentials.index', ['grup' => $group->uuid, 'kayit' => $kayit->uuid]))
            ->assertOk()
            ->assertDontSee('Parolayı güncelle');
    }
}
