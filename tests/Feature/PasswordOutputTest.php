<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ActivityAction;
use App\Enums\PermissionEffect;
use App\Models\ActivityLog;
use App\Models\Credential;
use App\Models\CredentialGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ZipArchive;

/**
 * Toplu parola çıktısı.
 *
 * Parolayı panelin DIŞINA çıkaran tek uç burasıdır ve kuralları tek tek
 * kilitlenir: ayrı yetki, satır başına yetki, zorunlu gerekçe ve her kayıt
 * için denetim satırı.
 */
final class PasswordOutputTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: CredentialGroup} */
    private function yetkili(array $actions): array
    {
        $user = User::factory()->create();
        $group = CredentialGroup::factory()->create();

        foreach ($actions as $action) {
            $user->permissions()->create([
                'resource' => 'credential_group',
                'resource_id' => $group->getKey(),
                'action' => $action,
                'effect' => PermissionEffect::Allow,
            ]);
        }

        return [$user->fresh(), $group];
    }

    private function kayit(CredentialGroup $group, string $ad, string $parola): Credential
    {
        return Credential::factory()->create([
            'credential_group_id' => $group->getKey(),
            'name' => $ad,
            'username' => 'kullanici',
            'password' => $parola,
        ]);
    }

    /** Dosyanın içindeki sayfayı düz metne çevirir. */
    private function sayfa(string $icerik): string
    {
        $yol = tempnam(sys_get_temp_dir(), 'test-xlsx-');
        file_put_contents($yol, $icerik);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($yol) === true, 'Üretilen dosya bir zip değil.');

        $xml = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        unlink($yol);

        return $xml;
    }

    #[Test]
    public function yetkisi_olmayan_cikti_alamaz(): void
    {
        /* 'field.password' var ama 'export' yok: görmek çıkarmayı kapsamaz. */
        [$user, $group] = $this->yetkili(['view', 'field.password']);
        $this->kayit($group, 'Sunucu', 'GIZLI');

        $this->actingAs($user)
            ->post(route('credentials.export'), ['kapsam' => 'tumu', 'reason' => 'denetim'])
            ->assertForbidden();
    }

    #[Test]
    public function gerekcesiz_cikti_alinamaz(): void
    {
        [$user, $group] = $this->yetkili(['view', 'field.password', 'export']);
        $this->kayit($group, 'Sunucu', 'GIZLI');

        $this->actingAs($user)
            ->post(route('credentials.export'), ['kapsam' => 'tumu'])
            ->assertSessionHasErrors('reason');
    }

    #[Test]
    public function cikti_excel_dosyasi_olarak_iner_ve_parolayi_tasir(): void
    {
        [$user, $group] = $this->yetkili(['view', 'field.password', 'export']);
        $this->kayit($group, 'Sunucu', 'COK-GIZLI-PAROLA');

        $yanit = $this->actingAs($user)
            ->post(route('credentials.export'), ['kapsam' => 'tumu', 'reason' => 'yil sonu denetimi']);

        $yanit->assertOk();
        $yanit->assertHeader(
            'Content-Type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        );
        $this->assertStringContainsString('.xlsx', (string) $yanit->headers->get('Content-Disposition'));

        /* Açık parola listesi hiçbir yerde önbelleğe düşmemeli. */
        $this->assertStringContainsString('no-store', (string) $yanit->headers->get('Cache-Control'));

        $sayfa = $this->sayfa((string) $yanit->getContent());

        $this->assertStringContainsString('COK-GIZLI-PAROLA', $sayfa);
        $this->assertStringContainsString('Sunucu', $sayfa);
    }

    #[Test]
    public function goremedigi_kayit_dosyaya_girmez(): void
    {
        [$user, $group] = $this->yetkili(['view', 'field.password', 'export']);
        $this->kayit($group, 'Gorulen', 'GORULEN-PAROLA');

        /* Başka bir grup: kullanıcının orada hiçbir yetkisi yok. */
        $baska = CredentialGroup::factory()->create();
        $this->kayit($baska, 'Gizli', 'GIZLI-PAROLA');

        $yanit = $this->actingAs($user)
            ->post(route('credentials.export'), ['kapsam' => 'tumu', 'reason' => 'denetim']);

        $sayfa = $this->sayfa((string) $yanit->getContent());

        $this->assertStringContainsString('GORULEN-PAROLA', $sayfa);
        $this->assertStringNotContainsString('GIZLI-PAROLA', $sayfa);
    }

    /**
     * Elenenlerin SEBEBİ ayrı ayrı söylenir.
     *
     * "Atlandı" demek yetmiyor: yetkinin yetmemesi ile kaydın kasada zaten
     * parolasız durması bambaşka iki durum.
     */
    #[Test]
    public function parolasiz_kayitlar_ayrica_sayilir(): void
    {
        [$user, $group] = $this->yetkili(['view', 'field.password', 'export']);
        $this->kayit($group, 'Dolu', 'BIR-PAROLA');
        $this->kayit($group, 'Bos', '');

        $yanit = $this->actingAs($user)
            ->post(route('credentials.export'), ['kapsam' => 'tumu', 'reason' => 'denetim']);

        $yanit->assertOk();
        $this->assertStringContainsString(
            '1 kaydın kasada parolası yok',
            (string) $yanit->headers->get('X-Eys-Digest'),
        );
    }

    /** Hiç parola yoksa boş dosya inmez; sebebi söylenir. */
    #[Test]
    public function hic_parola_yoksa_dosya_inmez(): void
    {
        [$user, $group] = $this->yetkili(['view', 'field.password', 'export']);
        $this->kayit($group, 'Bos', '');

        $this->actingAs($user)
            ->post(route('credentials.export'), ['kapsam' => 'tumu', 'reason' => 'denetim'])
            ->assertRedirect()
            ->assertSessionHas('error', fn (string $mesaj): bool => str_contains($mesaj, 'parolası yok'));
    }

    /**
     * Çıktı başına TEK denetim satırı (kayıt başına satır denetim defterini
     * okunmaz kılıyordu); hangi kayıtların gittiği ayrıntıda durur.
     */
    #[Test]
    public function cikti_tek_denetim_satiri_yazar_ve_kayitlar_ayrintida_durur(): void
    {
        [$user, $group] = $this->yetkili(['view', 'field.password', 'export']);
        $bir = $this->kayit($group, 'Bir', 'BIR');
        $iki = $this->kayit($group, 'Iki', 'IKI');

        $this->actingAs($user)
            ->post(route('credentials.export'), ['kapsam' => 'tumu', 'reason' => 'yil sonu denetimi'])
            ->assertOk();

        $satirlar = ActivityLog::query()->where('action', ActivityAction::Exported)->get();

        $this->assertCount(1, $satirlar);
        $this->assertStringContainsString('2 adet parola dosyaya çıkarıldı', (string) $satirlar->first()->summary);
        $this->assertSame('yil sonu denetimi', $satirlar->first()->reason);
        $this->assertEqualsCanonicalizing(
            [$bir->getKey(), $iki->getKey()],
            $satirlar->first()->ciktiSatirlari->pluck('credential_id')->all(),
        );
        $this->assertSame($group->name, $satirlar->first()->ciktiSatirlari->first()->grup);
    }

    /** Kayıtlar ekranı: satırda ayrıntı simgesi, penceresinde dosyaya giren kayıtlar; kaydın geçmişinde de görünür. */
    #[Test]
    public function kayitlar_ekraninda_ayrinti_penceresi_ve_kayit_gecmisinde_cikti(): void
    {
        [$user, $group] = $this->yetkili(['view', 'field.password', 'export']);
        $bir = $this->kayit($group, 'Birinci Switch', 'BIR');
        $this->kayit($group, 'Ikinci Switch', 'IKI');
        $disarida = $this->kayit($group, 'Ciktiya Girmeyen', '');

        $this->actingAs($user)->post(route('credentials.export'), ['kapsam' => 'tumu', 'reason' => 'denetim'])->assertOk();

        $yonetici = User::factory()->admin()->create();
        $this->actingAs($yonetici)->get(route('logs.index'))
            ->assertOk()
            ->assertSee('data-modal-open="cikti-', false)
            ->assertSee('id="cikti-', false)
            ->assertSee('Birinci Switch')
            ->assertSee('Ikinci Switch');

        $this->actingAs($yonetici)->get(route('logs.index', ['kayit' => $bir->uuid]))
            ->assertOk()->assertSee('2 adet parola dosyaya çıkarıldı');

        $this->actingAs($yonetici)->get(route('logs.index', ['kayit' => $disarida->uuid]))
            ->assertOk()->assertDontSee('adet parola dosyaya çıkarıldı');
    }
}
