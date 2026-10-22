<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AccessType;
use App\Enums\OperatingSystem;
use App\Enums\PermissionEffect;
use App\Models\Credential;
use App\Models\CredentialGroup;
use App\Models\User;
use App\Services\DeviceInterface\InterfaceSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Cihaz arayüzü vekilinde kimlik KANITI: adresteki kimlik yetmez, parola
 * tarayıcıya yetkisiz gitmez, devir açan tarayıcıya bağlıdır.
 */
final class InterfaceSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function kayit(CredentialGroup $group): Credential
    {
        return Credential::factory()->for($group, 'group')->create([
            'access_type' => AccessType::Esxi, 'operating_system' => OperatingSystem::Esxi,
            'host' => '10.0.0.44', 'port' => null, 'url' => null,
            'username' => 'root', 'password' => 'ESXI-PAROLASI',
        ]);
    }

    /** @return array{0: User, 1: CredentialGroup} */
    private function kisi(array $actions): array
    {
        $user = User::factory()->create();
        $group = CredentialGroup::factory()->create();

        foreach ($actions as $action) {
            $user->permissions()->create([
                'resource' => 'credential_group', 'resource_id' => $group->getKey(),
                'action' => $action, 'effect' => PermissionEffect::Allow,
            ]);
        }

        return [$user->fresh(), $group];
    }

    #[Test]
    public function bekci_adresteki_kimligi_cerezsiz_kabul_etmez(): void
    {
        [$user, $group] = $this->kisi(['view', 'field.password', 'session']);
        $oturum = app(InterfaceSession::class)->ac($this->kayit($group), (int) $user->id);
        $adres = '/device-session/'.$oturum['token'].'/ui/';

        /* Çerezsiz: ne URI ne Referer ile geçer; hedef de sır da verilmez. */
        $this->withHeader('X-Original-Uri', $adres)->get(route('ajax.interface.guard'))
            ->assertForbidden()->assertHeaderMissing('X-Eys-Target')->assertHeaderMissing('X-Eys-Secret');
        $this->withHeader('Referer', 'https://eys.ornek.test'.$adres)->get(route('ajax.interface.guard'))
            ->assertForbidden()->assertHeaderMissing('X-Eys-Target');

        /* Başkasının çerezi de yetmez. */
        $baska = app(InterfaceSession::class)->ac($this->kayit($this->kisi(['view', 'field.password', 'session'])[1]), (int) User::factory()->create()->id);
        $this->withCookies([InterfaceSession::COOKIE => $baska['token']])->withHeader('X-Original-Uri', $adres)
            ->get(route('ajax.interface.guard'))->assertForbidden();

        /* Sahibinin çerezi ile geçer. */
        $this->withCookies([InterfaceSession::COOKIE => $oturum['token']])->withHeader('X-Original-Uri', $adres)
            ->get(route('ajax.interface.guard'))->assertNoContent()->assertHeader('X-Eys-Target', '10.0.0.44:443');
        $this->withCookies([InterfaceSession::COOKIE => $oturum['token']])->withHeader('Referer', 'https://eys.ornek.test'.$adres)
            ->get(route('ajax.interface.guard'))->assertNoContent();
    }

    #[Test]
    public function kimlik_ucu_parola_yetkisi_olmayana_parola_vermez(): void
    {
        [$user, $group] = $this->kisi(['view', 'session']); // arayüzü açabilir, parolayı göremez
        $oturum = app(InterfaceSession::class)->ac($this->kayit($group), (int) $user->id);
        $cerez = [InterfaceSession::COOKIE => $oturum['token']];

        $yanit = $this->withCookies($cerez)->get($oturum['adres'].'identity');

        $yanit->assertForbidden();
        $this->assertStringNotContainsString('ESXI-PAROLASI', $yanit->getContent());
    }

    #[Test]
    public function kimlik_ucu_parola_yetkisi_olana_verir_ve_kayda_gecer(): void
    {
        [$user, $group] = $this->kisi(['view', 'session', 'field.password']);
        $kayit = $this->kayit($group);
        $oturum = app(InterfaceSession::class)->ac($kayit, (int) $user->id);

        $this->withCookies([InterfaceSession::COOKIE => $oturum['token']])->get($oturum['adres'].'identity')
            ->assertOk()->assertJson(['password' => 'ESXI-PAROLASI']);

        $this->assertDatabaseHas('activity_logs', ['action' => 'revealed', 'subject_id' => $kayit->id, 'user_id' => $user->id]);
    }

    #[Test]
    public function devir_yalnizca_oturumu_acan_tarayiciyla_harcanir(): void
    {
        [$user, $group] = $this->kisi(['view', 'field.password', 'session']);
        $kayit = $this->kayit($group);

        /* Oturumu açan tarayıcı: yönlendirme ile birlikte bağ çerezini alır. */
        $ac = $this->actingAs($user)->post(route('credentials.interface', $kayit), ['reason' => 'bakim']);
        $ac->assertRedirect();
        $bag = collect($ac->headers->getCookies())->first(fn ($c) => $c->getName() === InterfaceSession::DEVIR_BAG_COOKIE);
        $this->assertNotNull($bag, 'Devri açan tarayıcıya bağ çerezi bırakılmalı.');
        $devirAdresi = $ac->headers->get('Location');
        preg_match('#/handoff/([0-9a-f-]{36})$#', (string) $devirAdresi, $m);

        /* Bağ çerezi olmayan (saldırganın bağlantısını açan kurban): reddedilir ve jeton yanar. */
        $this->get(route('credentials.interface.handoff', ['devir' => $m[1]]))->assertStatus(410);

        /* (Jeton yandı: doğru tarayıcıyla bile ikinci kez harcanamaz.) Yeni devir, doğru tarayıcı: çerez kurulur. */
        $ac = $this->actingAs($user)->post(route('credentials.interface', $kayit), ['reason' => 'bakim']);
        $bag = collect($ac->headers->getCookies())->first(fn ($c) => $c->getName() === InterfaceSession::DEVIR_BAG_COOKIE);
        preg_match('#/handoff/([0-9a-f-]{36})$#', (string) $ac->headers->get('Location'), $m);

        /* Tarayıcı çerezi şifreli değeriyle geri yollar. */
        $this->withUnencryptedCookie(InterfaceSession::DEVIR_BAG_COOKIE, $bag->getValue())
            ->get(route('credentials.interface.handoff', ['devir' => $m[1]]))
            ->assertRedirect()->assertCookie(InterfaceSession::COOKIE);
    }

    #[Test]
    public function bekci_ve_ayna_dis_kapidan_kapatilmistir(): void
    {
        $snippet = (string) file_get_contents(base_path('docker/rootfs/etc/nginx/snippets/interface-watcher.conf'));
        $conf = (string) file_get_contents(base_path('docker/rootfs/etc/nginx/conf.d/eys.conf'));

        /* Snippet iki dinleyicide de include ediliyor ve iç konumlar `internal`. */
        $this->assertSame(2, substr_count($conf, 'include /etc/nginx/snippets/interface-watcher.conf;'));
        $this->assertMatchesRegularExpression('#location \^~ /ajax/device-session/ \{\s*return 404;#', $snippet);
        $this->assertSame(3, substr_count($snippet, 'internal;'));
    }
}
