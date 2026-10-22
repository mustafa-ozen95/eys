<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\User;
use App\Models\UserIdentity;
use App\Services\Identity\AccountMatcher;
use App\Services\Identity\IdentitySyncer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * MERKEZ HER ZAMAN EZER.
 *
 * Eşitleme yalnızca KAPATMAZ: merkezde geri açılan hesap burada da açılır.
 * Yoksa merkezde "etkin" yazan hesap panelde pasif durur ve hangisinin doğru
 * olduğu belli olmazdı.
 *
 * Panelde yapılan yerel değişiklik YEREL KALIR: burada pasife almak merkezi
 * etkilemez, yalnızca bu panele girişi keser. Merkezden yapılan ise her yere
 * işler.
 */
final class IdentityMirrorTest extends TestCase
{
    use RefreshDatabase;

    private function merkezliKullanici(UserStatus $durum = UserStatus::Active): User
    {
        $kullanici = User::factory()->create(['status' => $durum]);

        UserIdentity::create([
            'user_id' => $kullanici->id,
            'provider' => AccountMatcher::SAGLAYICI,
            'subject' => (string) Str::uuid(),
            'email' => $kullanici->email,
        ]);

        return $kullanici;
    }

    #[Test]
    public function merkezde_erisimi_kalkan_burada_kapanir(): void
    {
        $kullanici = $this->merkezliKullanici();

        app(IdentitySyncer::class)->uygula($kullanici, [
            'status' => 'askida', 'can_enter' => false, 'role' => null,
        ]);

        $this->assertSame(UserStatus::Passive, $kullanici->fresh()->status);
    }

    /**
     * ASIL EKSİK BUYDU: merkezde geri açılan hesap burada kapalı kalıyordu.
     */
    #[Test]
    public function merkezde_geri_acilan_burada_da_acilir(): void
    {
        $kullanici = $this->merkezliKullanici(UserStatus::Passive);

        app(IdentitySyncer::class)->uygula($kullanici, [
            'status' => 'etkin', 'can_enter' => true, 'role' => null,
        ]);

        $this->assertSame(UserStatus::Active, $kullanici->fresh()->status);
    }

    /**
     * Rol KAPALI hesapta da eşitlenir.
     *
     * Atlanınca hesap merkezden geri açıldığında eski rolüyle açılıyordu.
     */
    #[Test]
    public function kapali_hesabin_rolu_de_esitlenir(): void
    {
        $kullanici = $this->merkezliKullanici(UserStatus::Passive);
        $hedef = Role::query()->where('is_admin', false)->first() ?? Role::query()->first();

        app(IdentitySyncer::class)->uygula($kullanici, [
            'status' => 'askida', 'can_enter' => false, 'role' => $hedef->slug,
        ]);

        $this->assertSame($hedef->id, $kullanici->fresh()->role_id);
    }

    /**
     * PASİFE ALMAK AÇIK OTURUMU DA KAPATIR.
     *
     * Durumu değiştirmek yalnızca "bir daha giremesin" demekti; o anda açık
     * olan oturum çalışmaya devam ediyordu.
     */
    #[Test]
    public function pasife_alinan_hesabin_oturumu_silinir(): void
    {
        if (config('session.driver') !== 'database') {
            $this->markTestSkipped('Oturum satırı yalnızca veritabanı sürücüsünde silinebilir.');
        }

        $kullanici = User::factory()->create();

        DB::table(config('session.table', 'sessions'))->insert([
            'id' => 'sinama-oturum',
            'user_id' => $kullanici->id,
            'ip_address' => '10.0.0.1',
            'user_agent' => 'sinama',
            'payload' => base64_encode('x'),
            'last_activity' => time(),
        ]);

        $kullanici->update(['status' => UserStatus::Passive]);

        $this->assertSame(0, DB::table(config('session.table', 'sessions'))
            ->where('user_id', $kullanici->id)->count());
    }

    /**
     * Oturum satırı silinemese bile PASİF HESAP İÇERİDE KALMAZ.
     *
     * Ara katman her istekte sorar: hangi oturum sürücüsü kullanılırsa
     * kullanılsın ve durum hangi yoldan değişirse değişsin.
     */
    #[Test]
    public function pasif_hesap_bir_sonraki_istekte_disari_atilir(): void
    {
        $kullanici = User::factory()->create();

        /*
         * GERÇEK GİRİŞ yapılır, actingAs DEĞİL: actingAs kullanıcı nesnesini
         * belleğe koyuyor ve sonraki istek onu veritabanından okumuyor. Oysa
         * sınanan şey tam olarak "bir sonraki istekte veritabanındaki durum
         * okunur mu".
         */
        $this->post(route('login'), [
            'email' => $kullanici->email,
            'password' => 'gecerli-parola-1!',
        ]);

        $this->assertAuthenticated();

        /* Ham güncelleme: model olayı çalışmaz, oturum satırı silinmez. */
        DB::table('users')->where('id', $kullanici->id)->update(['status' => UserStatus::Passive->value]);

        /*
         * Muhafız, çözdüğü kullanıcıyı BELLEKTE tutuyor; gerçek bir istekte
         * ise süreç yeni doğuyor ve kimlik oturumdan okunup veritabanından
         * yükleniyor. Testte o tazelenmeyi elle yaptırmak gerekiyor, yoksa
         * sınanan şey kodun kendisi değil test istemcisinin önbelleği olurdu.
         */
        $this->app['auth']->forgetGuards();

        $this->get(route('credentials.index'))->assertRedirect(route('login'));
        $this->assertGuest();
    }
}
