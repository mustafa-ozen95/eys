<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CredentialGroup;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RolFormuTest extends TestCase
{
    use RefreshDatabase;

    public function test_kaydedilen_tikler_ekranda_geri_gelir(): void
    {
        $grup = CredentialGroup::factory()->create(['name' => 'Grup A']);
        $rol = Role::query()->where('slug', 'network')->firstOrFail();
        $admin = User::factory()->admin()->create();

        $gonderi = [
            'label' => $rol->label,
            'slug' => $rol->slug,
            'badge' => $rol->badge->value,
            'position' => 1,
            'template_all' => ['view'],
            'template_groups' => [$grup->uuid => ['field.password', 'session']],
        ];

        $this->actingAs($admin)->put(route('roles.update', $rol), $gonderi)->assertSessionHasNoErrors();

        $taze = $rol->fresh();

        $this->assertSame(['view'], $taze->normalizedTemplate()['all']);
        $this->assertSame(['Grup A' => ['field.password', 'session']], $taze->normalizedTemplate()['groups']);

        $govde = $this->actingAs($admin)->get(route('roles.edit', $taze))->assertOk()->getContent();

        $this->assertStringContainsString('template_groups['.$grup->uuid.'][]', $govde);

        /*
         * KAYITLI YETKİLER EKRANDA İŞARETLİ GELİR.
         *
         * Gelmediğinde kullanıcı "kaydet" deyince gördüğü şey — yani hiçbir
         * yetki — yazılıyor ve kayıtlı yetkiler sessizce siliniyordu.
         */
        $this->assertMatchesRegularExpression(
            '/<input[^>]*name="template_all\[\]"[^>]*value="view"[^>]*checked/s',
            $govde,
            'Bütün gruplarda verilen yetki işaretli gelmeli.',
        );

        $this->assertMatchesRegularExpression(
            '/<input[^>]*name="template_groups\['.preg_quote($grup->uuid, '/').'\]\[\]"[^>]*value="field.password"[^>]*checked/s',
            $govde,
            'Gruba verilen yetki işaretli gelmeli.',
        );

        /* İkinci kayıt aynı veriyi korumalı. */
        $this->actingAs($admin)->put(route('roles.update', $taze), $gonderi)->assertSessionHasNoErrors();

        $this->assertSame(['Grup A' => ['field.password', 'session']], $taze->fresh()->normalizedTemplate()['groups']);
    }
}
