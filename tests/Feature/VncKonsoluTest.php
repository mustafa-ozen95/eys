<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AccessType;
use App\Models\Credential;
use App\Models\User;
use App\Services\Remote\GuacamoleService;
use App\Services\Remote\RemoteSessionStore;
use App\Services\Remote\TargetResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

/**
 * VNC KONSOLU — ekranda duran oturuma bağlanır.
 *
 * NEDEN AYRI BİR PROTOKOL: ekranı sürekli değişen bir makinede RDP yolu
 * taşımaz. xrdp üzerinden kurulan köprü xrdp'nin VNC istemcisini kullanır ve o
 * istemci YALNIZCA RAW konuşur — her kare sıkıştırılmadan gider. guacd
 * yetişemez, tarayıcı "User is not responding" ile düşer, istemci yeniden
 * bağlanır. Guacamole'un kendi VNC istemcisi ise sıkıştırılmış kodlamalarla
 * (ZRLE, tight) konuşur.
 *
 * Yani çözüm bir ayar oynaması değil, PROTOKOL seçimi.
 */
class VncKonsoluTest extends TestCase
{
    use RefreshDatabase;

    private function kayit(array $ustune = []): Credential
    {
        return Credential::factory()->create(array_merge([
            'name' => 'Monitor',
            'access_type' => AccessType::Vnc,
            'host' => '192.0.2.101',
            'port' => null,
            'username' => null,
            'password' => 'yansima-parolasi',
        ], $ustune));
    }

    /**
     * KULLANICI ADI ARANMAZ.
     *
     * RFB'nin klasik kimlik doğrulaması yalnızca parola soruyor; sunucu
     * kullanıcı adını hiç görmüyor. Şart koşulsaydı kullanıcı kasaya
     * anlamsız bir değer yazmak zorunda kalır, o değer de bir gün "bu
     * hesapla bağlanıyoruz" diye okunurdu.
     */
    #[Test]
    public function kullanici_adi_olmadan_cozulur(): void
    {
        $hedef = app(TargetResolver::class)->resolve($this->kayit());

        $this->assertSame(AccessType::Vnc, $hedef->protocol);
        $this->assertSame('192.0.2.101', $hedef->host);
        $this->assertSame('', $hedef->username);
    }

    /** Parola yoksa bağlanacak hiçbir şey yok: hata ekranda, makineye gidilmeden. */
    #[Test]
    public function parola_yoksa_sebep_yazilir(): void
    {
        $this->expectExceptionMessage('parola tanımlı değil');

        app(TargetResolver::class)->resolve($this->kayit(['password' => null]));
    }

    /** Portu yazılmamış kayıt VNC'nin alışılmış portuna düşer. */
    #[Test]
    public function varsayilan_port_5900(): void
    {
        $this->assertSame(5900, app(TargetResolver::class)->resolve($this->kayit())->port);
        $this->assertSame(5901, app(TargetResolver::class)->resolve($this->kayit(['port' => 5901]))->port);
    }

    /**
     * YÜK VNC PROTOKOLÜYLE GİDER VE KULLANICI ADI TAŞIMAZ.
     *
     * Protokol 'rdp' kalsaydı guacd RDP istemcisini açar ve 5900'de RDP
     * arardı; kullanıcı adı gönderilseydi de sunucuda öyle bir hesap varmış
     * izlenimi kalırdı.
     */
    #[Test]
    public function yuk_vnc_protokoluyle_kurulur(): void
    {
        $hedef = app(TargetResolver::class)->resolve($this->kayit());

        $yontem = new ReflectionMethod(GuacamoleService::class, 'payload');
        $veri = $yontem->invoke(app(GuacamoleService::class), $hedef, 'deneme');

        $baglanti = $veri['connections']['deneme'];

        $this->assertSame('vnc', $baglanti['protocol']);
        $this->assertSame('192.0.2.101', $baglanti['parameters']['hostname']);
        $this->assertSame('5900', $baglanti['parameters']['port']);
        $this->assertSame('yansima-parolasi', $baglanti['parameters']['password']);
        $this->assertArrayNotHasKey('username', $baglanti['parameters']);

        /* RDP'ye özgü ayarlar VNC yüküne sızmamalı. */
        $this->assertArrayNotHasKey('security', $baglanti['parameters']);
        $this->assertArrayNotHasKey('resize-method', $baglanti['parameters']);
    }

    /** RDP kaydı eskisi gibi RDP açmaya devam eder. */
    #[Test]
    public function rdp_kaydi_etkilenmez(): void
    {
        $hedef = app(TargetResolver::class)->resolve($this->kayit([
            'access_type' => AccessType::Rdp,
            'username' => 'administrator',
            'port' => 3389,
        ]));

        $yontem = new ReflectionMethod(GuacamoleService::class, 'payload');
        $veri = $yontem->invoke(app(GuacamoleService::class), $hedef, 'deneme');

        $this->assertSame('rdp', $veri['connections']['deneme']['protocol']);
        $this->assertSame('administrator', $veri['connections']['deneme']['parameters']['username']);
    }

    /**
     * DÜĞME GÖRÜNÜR.
     *
     * Konsol düğmesi kullanıcı adı dolu olan kayıtlarda açılıyordu; VNC
     * kaydında kullanıcı adı olmadığı için çözümleyici kaydı kabul ettiği
     * hâlde düğme görünmez, sebebi de yazılmazdı.
     */
    #[Test]
    public function konsol_dugmesi_kullanici_adi_olmadan_da_gorunur(): void
    {
        $kayit = $this->kayit();

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('credentials.index', ['kayit' => $kayit->uuid]))
            ->assertOk()
            ->assertSee('VNC');
    }

    /**
     * VEKİL BEKÇİSİ VNC OTURUMUNU DA TANIR.
     *
     * /rdp-proxy yolu adını RDP'den alıyor ama altından Guacamole ile açılan
     * her oturum geçiyor. Bekçi yalnızca RDP anahtarına baksaydı, oturumun
     * sahibi kendi oturumunda 403 görürdü.
     */
    #[Test]
    public function vekil_bekcisi_vnc_oturumunu_kabul_eder(): void
    {
        $kullanici = User::factory()->admin()->create();
        $kayit = $this->kayit();

        $kasa = app(RemoteSessionStore::class);
        $jeton = 'deneme-jetonu';

        $kasa->put($jeton, [
            'label' => $kayit->name,
            'protocol' => AccessType::Vnc->value,
            'credential_id' => $kayit->getKey(),
            'user_id' => $kullanici->id,
        ]);
        $kasa->rememberForUser($kullanici->id, AccessType::Vnc, $jeton);

        $this->actingAs($kullanici)
            ->get(route('ajax.remote.guard'))
            ->assertNoContent();
    }
}
