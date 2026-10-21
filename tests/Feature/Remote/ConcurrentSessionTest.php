<?php

namespace Tests\Feature\Remote;

use App\Enums\AccessType;
use App\Models\Credential;
use App\Models\User;
use App\Services\Remote\RemoteSessionStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * AYNI ANDA BİRDEN ÇOK RDP/VNC OTURUMU: ikinci bağlantı birinciyi atmamalı.
 *
 * İki ayrı kopma vardı: Guacamole yeni sekmede önceki sekmenin jetonunu iptal
 * ediyordu (tarayıcı tarafı, remote-session.js) ve proxy bekçisi kullanıcının
 * yalnızca SON oturumunu tanıyordu (sunucu tarafı, RemoteSessionStore).
 */
class ConcurrentSessionTest extends TestCase
{
    use RefreshDatabase;

    private function oturum(RemoteSessionStore $kasa, User $kullanici, Credential $kayit, string $jeton): void
    {
        $kasa->put($jeton, [
            'label' => $kayit->name,
            'protocol' => AccessType::Rdp->value,
            'credential_id' => $kayit->getKey(),
            'user_id' => $kullanici->id,
        ]);
        $kasa->rememberForUser($kullanici->id, AccessType::Rdp, $jeton);
    }

    #[Test]
    public function son_acilan_kapaninca_oncekinin_proxy_istekleri_gecer(): void
    {
        $kullanici = User::factory()->admin()->create();
        $kayit = Credential::factory()->create(['name' => 'Sunucu', 'access_type' => AccessType::Rdp, 'host' => '198.51.100.10']);
        $kasa = app(RemoteSessionStore::class);

        $this->oturum($kasa, $kullanici, $kayit, 'birinci');
        $this->oturum($kasa, $kullanici, $kayit, 'ikinci');
        $this->actingAs($kullanici)->get(route('ajax.remote.guard'))->assertNoContent();

        $kasa->forget('ikinci');

        $this->assertNotNull($kasa->forUser($kullanici->id, AccessType::Rdp));
        $this->actingAs($kullanici)->get(route('ajax.remote.guard'))->assertNoContent();

        $kasa->forget('birinci');
        $this->actingAs($kullanici)->get(route('ajax.remote.guard'))->assertForbidden();
    }

    #[Test]
    public function canli_tutma_kullanicinin_listesini_tazeler_eski_bicim_okunur(): void
    {
        $kullanici = User::factory()->admin()->create();
        $kayit = Credential::factory()->create(['name' => 'Sunucu', 'access_type' => AccessType::Rdp, 'host' => '198.51.100.10']);
        $kasa = app(RemoteSessionStore::class);
        $anahtar = config('remote.session.cache_prefix')."user:{$kullanici->id}:rdp";

        /* Eski biçimde saklanmış oturum: tek jeton, dizgi. */
        $this->oturum($kasa, $kullanici, $kayit, 'eski');
        Cache::put($anahtar, 'eski', 60);
        $this->assertNotNull($kasa->forUser($kullanici->id, AccessType::Rdp));

        /* Liste düşse bile canlı tutma onu geri yazar. */
        Cache::forget($anahtar);
        $this->assertNull($kasa->forUser($kullanici->id, AccessType::Rdp));
        $this->assertTrue($kasa->touch('eski'));
        $this->assertSame(['eski'], Cache::get($anahtar));
    }

    #[Test]
    public function cerceve_onceki_sekmenin_jetonunu_silmeden_acilmaz(): void
    {
        $sablon = (string) file_get_contents(resource_path('views/remote/session.blade.php'));
        $betik = (string) file_get_contents(resource_path('js/components/remote-session.js'));

        $this->assertStringContainsString('data-src="{{ $session[\'path\'] }}"', $sablon);
        $this->assertSame(0, preg_match('/(?<!data-)src="\{\{ \$session/', $sablon), 'çerçeve kendiliğinden yüklenmemeli');
        $sil = strpos($betik, "removeItem('GUAC_AUTH')");
        $ac = strpos($betik, 'cerceve.src = cerceve.dataset.src');
        $this->assertNotFalse($sil);
        $this->assertNotFalse($ac);
        $this->assertLessThan($ac, $sil, 'jeton kaydı çerçeve açılmadan ÖNCE silinmeli');
    }
}
