<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\OperatingSystem;
use App\Enums\PermissionEffect;
use App\Jobs\RotateCredentialPasswordJob;
use App\Models\Credential;
use App\Models\CredentialGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Toplu döndürme.
 *
 * Tehlike, "seçmenin yetki gibi davranmasıdır": yirmi kaydı işaretlemek yirmi
 * ayrı yetki demektir, tek bir toplu yetki değil. Bu testler onu kilitler —
 * ve toplu ucun tek kayıtla AYNI motoru kullandığını da.
 */
class BulkRotationTest extends TestCase
{
    use RefreshDatabase;

    /** @param  list<string>  $actions */
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

    /** Döndürmeye gerçekten hazır bir kayıt: sürücüsü, adresi ve parolası var. */
    private function kayit(CredentialGroup $group, string $name): Credential
    {
        return Credential::factory()->for($group, 'group')->create([
            'name' => $name,
            'operating_system' => OperatingSystem::Linux,
            'host' => '10.0.0.'.random_int(2, 250),
            'username' => 'root',
            'password' => 'eski-parola',
            'rotation_enabled' => true,
        ]);
    }

    #[Test]
    public function secilen_kayitlar_sirayla_kuyruga_alinir(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $group = CredentialGroup::factory()->create();
        $this->yetkilendir($user, $group, ['view', 'rotate', 'field.password']);

        $bir = $this->kayit($group, 'Bir');
        $iki = $this->kayit($group, 'Iki');

        $this->actingAs($user)
            ->post(route('credentials.rotate.bulk'), [
                'kayitlar' => [$bir->uuid, $iki->uuid],
                'reason' => 'Ceyreklik parola yenileme',
            ])
            ->assertRedirect()
            ->assertSessionHas('status', fn (string $mesaj): bool => str_contains($mesaj, '2 kayıt'));

        Queue::assertPushed(RotateCredentialPasswordJob::class, 2);
    }

    /**
     * Seçim yetki genişletmez.
     *
     * Kullanıcı A grubunda döndürebiliyor diye B grubundaki kaydı da
     * seçebilseydi, toplu uç yetki sisteminin etrafından dolaşan bir kapı
     * olurdu.
     */
    #[Test]
    public function yetkisi_olmayan_grubun_kaydi_atlanir(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $benim = CredentialGroup::factory()->create();
        $baskasi = CredentialGroup::factory()->create();
        $this->yetkilendir($user, $benim, ['view', 'rotate', 'field.password']);
        $this->yetkilendir($user, $baskasi, ['view', 'field.password']);

        $izinli = $this->kayit($benim, 'Izinli');
        $izinsiz = $this->kayit($baskasi, 'Izinsiz');

        $this->actingAs($user)
            ->post(route('credentials.rotate.bulk'), [
                'kayitlar' => [$izinli->uuid, $izinsiz->uuid],
                'reason' => 'Toplu deneme',
            ])
            ->assertSessionHas('status', fn (string $m): bool => str_contains($m, '1 kayıt sıraya alındı')
                && str_contains($m, 'Izinsiz'));

        Queue::assertPushed(RotateCredentialPasswordJob::class, 1);
    }

    /** Göremediği kaydın uuid'sini forma yazan biri onu döndüremez (IDOR). */
    #[Test]
    public function goremedigi_kaydin_kimligi_forma_elle_yazilamaz(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $benim = CredentialGroup::factory()->create();
        $this->yetkilendir($user, $benim, ['view', 'rotate', 'field.password']);

        $gizli = $this->kayit(CredentialGroup::factory()->create(), 'Gizli');

        $this->actingAs($user)
            ->post(route('credentials.rotate.bulk'), [
                'kayitlar' => [$gizli->uuid],
                'reason' => 'Toplu deneme',
            ])
            ->assertSessionHas('error');

        Queue::assertNothingPushed();
    }

    #[Test]
    public function dondurulemeyecek_kayit_nedeniyle_birlikte_bildirilir(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $group = CredentialGroup::factory()->create();
        $this->yetkilendir($user, $group, ['view', 'rotate', 'field.password']);

        $hazir = $this->kayit($group, 'Hazir');

        /* Döndürmesi kapalı kayıt: seçilse bile çalıştırılmaz. */
        $kapali = $this->kayit($group, 'Kapali');
        $kapali->forceFill(['rotation_enabled' => false])->save();

        $this->actingAs($user)
            ->post(route('credentials.rotate.bulk'), [
                'kayitlar' => [$hazir->uuid, $kapali->uuid],
                'reason' => 'Toplu deneme',
            ])
            ->assertSessionHas('status', fn (string $m): bool => str_contains($m, 'Kapali')
                && str_contains($m, 'Otomatik güncellemeye kapalı'));

        Queue::assertPushed(RotateCredentialPasswordJob::class, 1);
    }

    #[Test]
    public function gerekcesiz_toplu_dondurme_reddedilir(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $group = CredentialGroup::factory()->create();
        $this->yetkilendir($user, $group, ['view', 'rotate', 'field.password']);

        $kayit = $this->kayit($group, 'Bir');

        $this->actingAs($user)
            ->post(route('credentials.rotate.bulk'), ['kayitlar' => [$kayit->uuid]])
            ->assertSessionHasErrors('reason');

        Queue::assertNothingPushed();
    }

    #[Test]
    public function dondurme_yetkisi_olmayan_toplu_ucu_hic_acamaz(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $group = CredentialGroup::factory()->create();
        $this->yetkilendir($user, $group, ['view', 'field.password']);

        $kayit = $this->kayit($group, 'Bir');

        $this->actingAs($user)
            ->post(route('credentials.rotate.bulk'), [
                'kayitlar' => [$kayit->uuid],
                'reason' => 'Toplu deneme',
            ])
            ->assertForbidden();

        Queue::assertNothingPushed();
    }

    /** Tek istekte kaç kayıt: sınır sunucuda. */
    #[Test]
    public function tek_istekte_elliden_fazla_kayit_gonderilemez(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $group = CredentialGroup::factory()->create();
        $this->yetkilendir($user, $group, ['view', 'rotate', 'field.password']);

        $this->actingAs($user)
            ->post(route('credentials.rotate.bulk'), [
                'kayitlar' => array_map(fn (): string => (string) Str::uuid7(), range(1, 51)),
                'reason' => 'Toplu deneme',
            ])
            ->assertSessionHasErrors('kayitlar');

        Queue::assertNothingPushed();
    }

    /** Seçim kutusu yalnızca gerçekten döndürülebilen satırda basılır. */
    #[Test]
    public function listede_kutu_yalnizca_dondurulebilir_satirda_cikar(): void
    {
        $user = User::factory()->create();
        $group = CredentialGroup::factory()->create();
        $this->yetkilendir($user, $group, ['view', 'rotate', 'field.password']);

        $hazir = $this->kayit($group, 'Hazir');
        $kapali = $this->kayit($group, 'Kapali');
        $kapali->forceFill(['rotation_enabled' => false])->save();

        $html = $this->actingAs($user)->get(route('credentials.index'))->assertOk()->getContent();

        $this->assertStringContainsString('value="'.$hazir->uuid.'" data-bulk-item', $html);
        $this->assertStringNotContainsString('value="'.$kapali->uuid.'" data-bulk-item', $html);
    }

    /**
     * Hazır kayıt yokken "Tümü" kutusu kapalı gelir.
     *
     * Açık bırakıldığında tıklanıyor, işaretleyecek satır bulamıyor ve kendini
     * geri kapatıyordu: kullanıcı ekranı bozuk sanıyordu. Sayı da yazılır ki
     * "neden hiçbir şey seçilmiyor" sorusu kayıt açmadan yanıtlansın.
     */
    #[Test]
    public function hazir_kayit_yokken_tumu_kutusu_kapali_gelir(): void
    {
        $user = User::factory()->create();
        $group = CredentialGroup::factory()->create();
        $this->yetkilendir($user, $group, ['view', 'rotate', 'field.password']);

        /* Üç kayıt var ama üçünde de döndürme kapalı. */
        foreach (['Bir', 'Iki', 'Uc'] as $ad) {
            $this->kayit($group, $ad)->forceFill(['rotation_enabled' => false])->save();
        }

        $this->actingAs($user)
            ->get(route('credentials.index'))
            ->assertOk()
            ->assertSee('data-bulk-all', false)
            ->assertSee('disabled', false)
            ->assertSee('0/3 hazır')
            ->assertDontSee('data-bulk-item', false);
    }

    #[Test]
    public function hazir_kayit_varken_tumu_kutusu_aciktir(): void
    {
        $user = User::factory()->create();
        $group = CredentialGroup::factory()->create();
        $this->yetkilendir($user, $group, ['view', 'rotate', 'field.password']);

        $this->kayit($group, 'Hazir');
        $this->kayit($group, 'Kapali')->forceFill(['rotation_enabled' => false])->save();

        $html = $this->actingAs($user)->get(route('credentials.index'))->assertOk()->getContent();

        $this->assertStringContainsString('1/2 hazır', $html);
        $this->assertDoesNotMatchRegularExpression(
            '/data-bulk-all[^>]*\sdisabled/',
            $html,
            'Hazır kayıt varken "Tümü" kutusu açık olmalı.',
        );
    }

    /**
     * "Tüm kasayı güncelle" kaldırıldı: kayıt listesi
     * olmadan gelen istek, eski 'tumu' kapsamıyla da olsa, hiçbir şey döndürmez.
     */
    #[Test]
    public function kayit_listesi_olmadan_kasa_geneli_dondurme_yapilmaz(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $group = CredentialGroup::factory()->create();
        $this->yetkilendir($user, $group, ['view', 'rotate', 'field.password']);
        $this->kayit($group, 'Bir');

        $this->actingAs($user)
            ->post(route('credentials.rotate.bulk'), ['kapsam' => 'tumu', 'reason' => 'Kasa geneli yenileme'])
            ->assertSessionHasErrors('kayitlar');

        Queue::assertNothingPushed();
    }

    /**
     * Bildirim OKUNABİLİR kalmalı.
     *
     * İlk sürümde atlanan her kaydın adı ve nedeni bildirime giriyordu:
     * kalabalık bir kasada ekranı kaplayan, dört saniye sonra kaybolan bir
     * metin çıkıyordu. Bildirim kısa olmalı; ayrıntı zaten listede, satırın
     * kendi üstünde yazıyor.
     */
    #[Test]
    public function bildirim_metni_duvara_donmez(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $group = CredentialGroup::factory()->create();
        $this->yetkilendir($user, $group, ['view', 'rotate', 'field.password']);

        $this->kayit($group, 'Hazir');

        /* Otuz kayıt hazır değil. */
        foreach (range(1, 30) as $i) {
            $this->kayit($group, "Kapali {$i}")->forceFill(['rotation_enabled' => false])->save();
        }

        /* Seçili kapsamda adlar yazılır ama en fazla birkaç tanesi. */
        $secili = Credential::query()->where('credential_group_id', $group->getKey())->pluck('uuid')->all();

        $this->actingAs($user)->post(route('credentials.rotate.bulk'), [
            'kayitlar' => array_slice($secili, 0, 20),
            'reason' => 'Secili deneme',
        ]);

        $this->assertLessThan(300, mb_strlen((string) session('status')));
        $this->assertStringContainsString('kayıt daha', (string) session('status'));
    }

    #[Test]
    public function dondurme_yetkisi_olmayan_toplu_seridi_gormez(): void
    {
        $user = User::factory()->create();
        $group = CredentialGroup::factory()->create();
        $this->yetkilendir($user, $group, ['view', 'field.password']);

        $this->kayit($group, 'Bir');

        $this->actingAs($user)
            ->get(route('credentials.index'))
            ->assertOk()
            ->assertDontSee('data-bulk-form', false)
            ->assertDontSee('data-bulk-item', false);
    }
}
