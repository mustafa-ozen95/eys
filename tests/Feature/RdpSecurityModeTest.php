<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AccessType;
use App\Enums\OperatingSystem;
use App\Models\Credential;
use App\Models\User;
use App\Services\Remote\GuacamoleService;
use App\Services\Remote\RemoteTarget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

/**
 * RDP güvenlik kipi KAYIT BAZINDA seçilebilir.
 *
 * Makineler aynı şeyi konuşmuyor:
 *
 *   Cihaz A   sertifikası RSA-SHA1 → TLS kurulamıyor, eski RDP güvenliği gerekir
 *   Cihaz B   YALNIZCA NLA kabul ediyor
 *
 * Tek bir genel ayar ikisini birden çözmüyor: "rdp" yazılsaydı Cihaz B
 * koparadı, "nla" yazılsaydı Cihaz A. Bu yüzden kip kayıttan okunuyor ve
 * boş bırakıldığında config'teki varsayılana düşüyor.
 */
class RdpSecurityModeTest extends TestCase
{
    use RefreshDatabase;

    private function kip(?string $secilen): string
    {
        $credential = Credential::factory()->create([
            'access_type' => AccessType::Rdp,
            'operating_system' => OperatingSystem::Windows,
            'host' => '10.0.0.5',
            'username' => 'administrator',
            'password' => 'parola',
            'rdp_security' => $secilen,
        ]);

        $hedef = new RemoteTarget(
            subject: $credential,
            label: $credential->name,
            host: (string) $credential->host,
            protocol: AccessType::Rdp,
            port: 3389,
            username: (string) $credential->username,
            password: (string) $credential->password,
        );

        $yontem = new ReflectionMethod(GuacamoleService::class, 'payload');
        $veri = $yontem->invoke(app(GuacamoleService::class), $hedef, 'deneme');

        return $veri['connections']['deneme']['parameters']['security'];
    }

    #[Test]
    public function kayitta_secilen_kip_gonderilir(): void
    {
        $this->assertSame('rdp', $this->kip('rdp'), 'Eski RDP güvenliği seçilebilmeli.');
        $this->assertSame('nla', $this->kip('nla'));
        $this->assertSame('tls', $this->kip('tls'));
    }

    /** Boş kayıt config'teki varsayılana düşer; eski davranış korunur. */
    #[Test]
    public function bos_birakilinca_varsayilan_gecerli(): void
    {
        config(['remote.guacamole.rdp.security' => 'any']);

        $this->assertSame('any', $this->kip(null));
    }

    /** Tanınmayan bir kip forma elle yazılamaz. */
    #[Test]
    public function taninmayan_kip_kabul_edilmez(): void
    {
        $kayit = Credential::factory()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->put(route('credentials.update', $kayit), [
                'group' => $kayit->group->uuid,
                'name' => $kayit->name,
                'access_type' => $kayit->access_type->value,
                'operating_system' => $kayit->operating_system->value,
                'rdp_security' => 'hicbiri',
            ])
            ->assertSessionHasErrors('rdp_security');
    }
}
