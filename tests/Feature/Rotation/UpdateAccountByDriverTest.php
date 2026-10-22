<?php

declare(strict_types=1);

namespace Tests\Feature\Rotation;

use App\Enums\AccessType;
use App\Models\Credential;
use App\Models\CredentialGroup;
use App\Models\User;
use App\Services\Rotation\Contracts\NeedsMachineAccount;
use App\Services\Rotation\DriverRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * "GÜNCELLEME HESABI — KASADAN SEÇ" ALANI SÜRÜCÜYE GÖRE ÇIKAR.
 *
 * Çoğu sürücüde parolayı değiştiren, parolası değişen hesabın kendisidir:
 * cihaza o hesapla bağlanılır. Plesk, mailcow süper yöneticisi ve rspamd'de
 * böyle değil — panelin parolası makinede koşan bir komutla değişiyor, yani
 * bağlantı MAKİNENİN SSH KİMLİĞİYLE kuruluyor.
 *
 * Alan her kayıtta aynı duruyordu; sürücüsü onu hiç kullanmayan kayıtlarda da
 * "kasadan seç" diyordu; oysa alanın anlamı sürücüye göre değişir.
 *
 * BAŞLANGIÇ DURUMUNU SUNUCU VERİR: betik yüklenmese de Plesk kaydını
 * düzenleyen biri alanı görebilmeli. Betik yalnızca sürücü kutusu
 * değiştiğinde günceller.
 */
class UpdateAccountByDriverTest extends TestCase
{
    use RefreshDatabase;

    private function kayit(?string $surucu): Credential
    {
        $grup = CredentialGroup::factory()->create(['name' => 'Grup C']);

        return Credential::factory()->for($grup, 'group')->create([
            'name' => 'Panel',
            'access_type' => AccessType::Panel,
            'rotation_driver' => $surucu,
        ]);
    }

    /** Ekrandaki alanın gizli mi çizildiği. */
    private function gizliMi(Credential $kayit): bool
    {
        $html = $this->actingAs(User::factory()->admin()->create())
            ->get(route('credentials.index', ['kayit' => $kayit->uuid, 'pencere' => 'duzenle']))
            ->assertOk()
            ->assertSee('data-machine-account', false)
            ->getContent();

        $konum = strpos((string) $html, 'data-machine-account');

        /* Alanın kendi etiketindeki sınıf: aynı düğümün öznitelikleri arasında. */
        $etiket = substr((string) $html, max(0, $konum - 400), 600);

        return str_contains($etiket, 'is-hidden');
    }

    /** "Elle" hesap ve parola kutularının gizli mi çizildiği. */
    private function elleGizliMi(Credential $kayit): bool
    {
        $html = (string) $this->actingAs(User::factory()->admin()->create())
            ->get(route('credentials.index', ['kayit' => $kayit->uuid, 'pencere' => 'duzenle']))
            ->assertOk()
            ->assertSee('data-manual-account', false)
            ->getContent();

        $konum = strpos($html, 'data-manual-account');

        return str_contains(substr($html, max(0, $konum - 200), 220), 'is-hidden');
    }

    #[Test]
    public function makinenin_ssh_kimligini_isteyen_surucude_gorunur(): void
    {
        $this->assertFalse($this->gizliMi($this->kayit('plesk')));
    }

    /**
     * KASADAN KAYIT SEÇİLİYKEN "ELLE" KUTULARI GİZLENİR.
     *
     * Seçim varken o iki kutu okunmuyor; ekranda durmaları hangisinin
     * geçerli olduğunu soruşturmak demekti.
     */
    #[Test]
    public function kasadan_secim_varken_elle_kutulari_gizlenir(): void
    {
        $kayit = $this->kayit('plesk');

        $ssh = Credential::factory()->for($kayit->group, 'group')->create([
            'access_type' => AccessType::Ssh,
            'username' => 'root',
            'password' => 'ssh-parolasi',
        ]);

        $kayit->forceFill(['rotation_credential_id' => $ssh->id])->save();

        $this->assertTrue($this->elleGizliMi($kayit->fresh()));
    }

    /** Seçim yokken görünür: kasada karşılığı olmayan hesap elle verilebilsin. */
    #[Test]
    public function secim_yokken_elle_kutulari_gorunur(): void
    {
        $this->assertFalse($this->elleGizliMi($this->kayit('plesk')));
    }

    /**
     * SEÇİM YOKKEN "PAROLAYI GÜNCELLE" DÜĞMESİ ÇIKMAZ.
     *
     * Aynı adreste bir SSH kaydı dursa bile kendiliğinden kullanılmıyor:
     * uzaktaki bir makinede parola değiştiren bir işin hangi kimlikle
     * bağlanacağı, ekrana bakan kişinin göremediği bir kuraldan çıkmamalı;
     * hesap seçilmemişse düğme gizlenir.
     */
    #[Test]
    public function secim_yokken_guncelle_dugmesi_cikmaz(): void
    {
        $kayit = $this->kayit('plesk');

        $kayit->forceFill(['username' => 'admin', 'password' => 'panel-parolasi'])->save();

        $ssh = Credential::factory()->for($kayit->group, 'group')->create([
            'host' => $kayit->host,
            'access_type' => AccessType::Ssh,
            'name' => 'Makine SSH',
            'username' => 'root',
            'password' => 'ssh-parolasi',
        ]);

        $yanit = $this->actingAs(User::factory()->admin()->create())
            ->get(route('credentials.index', ['kayit' => $kayit->uuid]))
            ->assertOk();

        $yanit->assertDontSee('Parolayı güncelle');

        /* Sebep ekranda yazar ve ne seçileceğini söyler. */
        $yanit->assertSee('Güncelleme hesabı seçilmemiş', false);
        $yanit->assertSee('#'.$ssh->id, false);
    }

    /** Seçildiğinde düğme geri gelir. */
    #[Test]
    public function secim_yapilinca_guncelle_dugmesi_cikar(): void
    {
        $kayit = $this->kayit('plesk');

        $ssh = Credential::factory()->for($kayit->group, 'group')->create([
            'host' => $kayit->host,
            'access_type' => AccessType::Ssh,
            'username' => 'root',
            'password' => 'ssh-parolasi',
        ]);

        $kayit->forceFill([
            'username' => 'admin',
            'password' => 'panel-parolasi',
            'rotation_credential_id' => $ssh->id,
        ])->save();

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('credentials.index', ['kayit' => $kayit->uuid]))
            ->assertOk()
            ->assertSee('Parolayı güncelle');
    }

    #[Test]
    public function istemeyen_surucude_gizli_cizilir(): void
    {
        $this->assertTrue($this->gizliMi($this->kayit('ssh')));
    }

    /** Sürücü seçilmemişse de gizli: hiçbir platform varsayılanı SSH kimliği istemiyor. */
    #[Test]
    public function surucu_secilmemisse_gizli_cizilir(): void
    {
        $this->assertTrue($this->gizliMi($this->kayit(null)));
    }

    /**
     * LİSTE SÜRÜCÜLERİN KENDİSİNDEN ÇIKAR.
     *
     * Ekranda elle tutulan ikinci bir liste olsaydı, yeni bir sürücü
     * eklendiğinde sessizce eskirdi.
     */
    #[Test]
    public function liste_arayuzu_uygulayan_surucularin_tamami(): void
    {
        $kayit = app(DriverRegistry::class);

        $beklenen = collect($kayit->drivers())
            ->filter(fn (string $sinif): bool => is_a($sinif, NeedsMachineAccount::class, true))
            ->keys()
            ->sort()
            ->values()
            ->all();

        $this->assertNotEmpty($beklenen);
        $this->assertSame($beklenen, collect($kayit->makineHesabiIsteyenler())->sort()->values()->all());
    }
}
