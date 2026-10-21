<?php

declare(strict_types=1);

namespace Tests\Feature\Rotation;

use App\Enums\AccessType;
use App\Enums\PermissionEffect;
use App\Models\Credential;
use App\Models\CredentialGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * GÜNCELLEME HESABI LİSTESİ: ÖNCE KENDİ GRUBU, ADIYLA.
 *
 * Liste kasadaki bütün SSH kayıtlarını düz bir sırayla gösteriyordu ve
 * SSH kayıtlarının çoğunun adı yalnızca "SSH" olabiliyor — ekranda aynı
 * kelime defalarca yazıyor, hangisinin hangi makine olduğu anlaşılmıyordu.
 * Makineyi söyleyen şey çoğu zaman GRUP adıdır ("Grup A", "Grup B").
 *
 * Bu yüzden liste önce kaydın kendi grubundaki SSH kayıtlarını gösterir ve
 * her seçenekte grubun adı da görünür.
 *
 * Diğer gruplar listeden ÇIKARILMADI: makinenin SSH kaydı başka bir grupta
 * tutuluyorsa seçilemez duruma düşmemeli. Ayrı bir başlık altında ve grup
 * adıyla yazılı duruyorlar.
 */
class UpdateAccountListTest extends TestCase
{
    use RefreshDatabase;

    private function yonetici(): User
    {
        return User::factory()->admin()->create();
    }

    private function ssh(CredentialGroup $group, string $ad = 'SSH'): Credential
    {
        return Credential::factory()->for($group, 'group')->create([
            'name' => $ad,
            'access_type' => AccessType::Ssh,
            'username' => 'root',
            'password' => 'parola',
            'is_active' => true,
        ]);
    }

    #[Test]
    public function kaydin_kendi_grubu_once_gelir(): void
    {
        $grup = CredentialGroup::factory()->create(['name' => 'Grup A']);
        $baska = CredentialGroup::factory()->create(['name' => 'Grup B']);

        $this->ssh($grup);
        $this->ssh($baska);

        $kayit = Credential::factory()->for($grup, 'group')->create([
            'name' => 'Plesk',
            'access_type' => AccessType::Panel,
            'rotation_driver' => 'plesk',
        ]);

        $yanit = $this->actingAs($this->yonetici())
            ->get(route('credentials.index', ['kayit' => $kayit->uuid, 'pencere' => 'duzenle']))
            ->assertOk();

        /* Kendi grubu kendi başlığı altında; diğerleri ayrı başlıkta. */
        $yanit->assertSee('Bu grup — Grup A', false);
        $yanit->assertSee('Diğer gruplar', false);

        /*
         * GRUP ADI HER ETİKETTE yazar — kendi grubundakinde de. Kutu
         * kapalıyken optgroup başlığı görünmüyor ve ekranda yalnızca
         * "#1 · SSH" kalıyordu.
         */
        $yanit->assertSee('Grup A · SSH', false);
        $yanit->assertSee('Grup B · SSH', false);
    }

    /** KAYIT KENDİNİ SEÇEMEZ: kendi parolasıyla kendini döndürmek olurdu. */
    #[Test]
    public function kayit_kendini_listelemez(): void
    {
        $grup = CredentialGroup::factory()->create(['name' => 'Grup C']);
        $kendisi = $this->ssh($grup, 'BENZERSIZ-SSH-ADI');

        $yanit = $this->actingAs($this->yonetici())
            ->get(route('credentials.index', ['kayit' => $kendisi->uuid, 'pencere' => 'duzenle']))
            ->assertOk();

        $yanit->assertDontSee('#'.$kendisi->getKey().' · BENZERSIZ-SSH-ADI', false);
    }

    /**
     * GÖREMEDİĞİ KAYIT LİSTEDE YOK.
     *
     * Göremediği bir kaydı seçebilseydi, adını oradan öğrenirdi.
     */
    #[Test]
    public function goremedigi_grubun_ssh_kaydi_listelenmez(): void
    {
        $gorunur = CredentialGroup::factory()->create(['name' => 'Görünür']);
        $gizli = CredentialGroup::factory()->create(['name' => 'Gizli']);

        $this->ssh($gizli, 'GIZLI-SSH');

        $kayit = Credential::factory()->for($gorunur, 'group')->create(['name' => 'Plesk']);

        $user = User::factory()->create();

        foreach (['view', 'update'] as $action) {
            $user->permissions()->create([
                'resource' => 'credential_group',
                'resource_id' => $gorunur->getKey(),
                'action' => $action,
                'effect' => PermissionEffect::Allow,
            ]);
        }

        $this->actingAs($user)
            ->get(route('credentials.index', ['kayit' => $kayit->uuid, 'pencere' => 'duzenle']))
            ->assertOk()
            ->assertDontSee('GIZLI-SSH');
    }

    /**
     * PASİF KAYIT LİSTEDE YOK: "artık bunu kullanmayın" demektir.
     *
     * Aranan şey SEÇENEK ETİKETİ ("#12 · PASIF-SSH"), kaydın adı değil: pasif
     * kayıt orta sütunda zaten görünüyor ve sayfanın tamamında ad aramak
     * listeyi değil o satırı ölçerdi.
     */
    #[Test]
    public function pasif_ssh_kaydi_listelenmez(): void
    {
        $grup = CredentialGroup::factory()->create(['name' => 'Grup C']);

        $pasif = $this->ssh($grup, 'PASIF-SSH');
        $pasif->forceFill(['is_active' => false])->save();

        $kayit = Credential::factory()->for($grup, 'group')->create(['name' => 'Plesk']);

        $this->actingAs($this->yonetici())
            ->get(route('credentials.index', ['kayit' => $kayit->uuid, 'pencere' => 'duzenle']))
            ->assertOk()
            ->assertDontSee('#'.$pasif->getKey().' · PASIF-SSH', false);
    }
}
