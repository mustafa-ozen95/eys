<?php

declare(strict_types=1);

namespace App\Providers;

use App\Listeners\OturumAcilisiniYaz;
use App\Listeners\SonGirisiIsaretle;
use App\Models\Role;
use App\Models\User;
use App\Policies\RolePolicy;
use App\Policies\UserPolicy;
use App\Services\Api\ApiTokenContext;
use App\Services\Api\ApiTokenGuard;
use App\Services\Api\TokenAbilityGate;
use App\Services\Authorization\PermissionRegistry;
use App\Services\Authorization\RolePermissions;
use App\Services\Authorization\UserPermissionResolver;
use App\Services\Rotation\Drivers\MailcowAdminPasswordDriver;
use App\Services\Rotation\Drivers\RspamdPasswordDriver;
use App\Services\Rotation\Drivers\SshPasswordDriver;
use App\Services\Rotation\Drivers\WindowsPasswordDriver;
use App\Services\Settings\SettingsRepository;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /*
         * İsteği yapan jeton istek boyunca TEKTİR.
         *
         * Singleton olmasaydı jetonu yazan bekçi ile onu okuyan yetki
         * daraltması iki ayrı nesneye bakar, daraltma hiç çalışmaz ve dar
         * yetkili bir jeton sahibinin tüm yetkileriyle konuşurdu.
         */
        $this->app->singleton(ApiTokenContext::class);

        /*
         * YETKİ KARARLARI İSTEK BOYUNCA TEKTİR.
         *
         * Çözümleyici kararları bir tabloda tutuyor ve izin değiştiğinde
         * flush() ile düşürüyor. Singleton olmasaydı her enjeksiyon ayrı bir
         * nesne olur, düşürme yalnızca birinde çalışır ve aynı istek içinde
         * iki farklı yanıt alınabilirdi. Rolün canlı okunması da aynı
         * önbelleğe dayanıyor (bkz. RolePermissions).
         */
        $this->app->singleton(UserPermissionResolver::class);
        $this->app->singleton(PermissionRegistry::class);
        $this->app->singleton(RolePermissions::class);

        /*
         * Sürücülerin zaman aşımı AYARDAN gelir. Kurucudaki varsayılana
         * bırakılsaydı ayar ekranındaki kutu hiçbir şeyi değiştirmezdi.
         */
        foreach ([SshPasswordDriver::class, WindowsPasswordDriver::class, RspamdPasswordDriver::class, MailcowAdminPasswordDriver::class] as $driver) {
            $this->app->bind($driver, fn (): object => new $driver((int) config('rotation.ssh_timeout', 15)));
        }
    }

    public function boot(): void
    {
        /*
         * Veritabanındaki ayarlar config üzerine bindirilir. Kod her yerde
         * config() okumaya devam eder; ayarın veritabanından mı yoksa
         * dosyadan mı geldiğini bilmesi gerekmez.
         */
        $this->app->make(SettingsRepository::class)->apply();

        /*
         * UZUN ÖMÜRLÜ İŞÇİ AYARI HER İŞTEN ÖNCE YENİDEN OKUR.
         *
         * Bindirme yalnızca süreç açılışında yapılıyordu; kuyruk işçisi saatlerce
         * (--max-time=3600) yaşadığı için ekrandan kapatılan "otomatik parola
         * güncelleme" anahtarı, işçi yeniden başlayana kadar ona ulaşmıyor ve
         * kuyruktaki turlar çalışmaya devam ediyordu. Acil durdurma anahtarının
         * işe yaraması için her iş başlamadan önce güncel değerler uygulanır
         * (önbellek ayar kaydında düşer, bu yüzden ucuz bir okumadır).
         *
         * Not: bir ayar SIFIRLANIRSA (satır silinirse) çalışan işçideki eski
         * bindirme geri alınmaz; bu yön güvenli (kapalı kalır) ve işçi
         * yeniden başlayınca varsayılana döner.
         */
        Queue::before(fn () => $this->app->make(SettingsRepository::class)->apply());

        /* API yalnızca jetonla konuşur; oturum çerezi kabul edilmez. */
        Auth::viaRequest('api-token', $this->app->make(ApiTokenGuard::class));

        /*
         * Hız sınırı JETON başınadır, adres başına değil: aynı adresten gelen
         * iki jeton birbirini yavaşlatmamalı.
         */
        RateLimiter::for('api', function (Request $request): Limit {
            $jeton = $this->app->make(ApiTokenContext::class)->get();

            return Limit::perMinute((int) config('api.rate_limit'))
                ->by($jeton?->uuid ?? $request->ip());
        });

        /*
         * API jetonu kendi listesini AŞAMAZ.
         *
         * SIRA ÖNEMLİ: jeton daraltması yönetici geçişinden ÖNCE çalışmalı.
         * Ters sırada, yöneticiye ait bir jeton bütün kontrolleri geçer ve
         * jetona verilen dar liste hiçbir şey ifade etmezdi.
         */
        Gate::before(fn (User $user, string $ability, array $arguments = []): ?bool => $this->app
            ->make(TokenAbilityGate::class)
            ->check($user, $ability, $arguments));

        /*
         * Yöneticinin bütün kontrolleri geçtiği TEK yer burasıdır. Her
         * policy'de ayrıca "ya da yöneticiyse" yazılsaydı, bir policy'de
         * unutulduğunda kimse fark etmezdi.
         */
        Gate::before(function (User $user, string $ability, array $arguments = []): ?bool {
            /*
             * Yöneticinin KENDİ hesabına yaptığı bazı işlemler bu muafiyetin
             * dışındadır: kendi rolünü düşürmek ya da kendini silmek paneli
             * sahipsiz bırakabilir. O iki soruyu policy yanıtlar.
             */
            $target = $arguments[0] ?? null;

            if ($target instanceof User
                && $user->is($target)
                && in_array($ability, UserPolicy::SELF_GUARDED, true)) {
                return null;
            }

            /*
             * Yerleşik ya da kullanıcısı olan bir rolü yönetici de silemez;
             * kullanıcı rolsüz kalırsa hiçbir kontrolden geçemez.
             */
            if ($target instanceof Role && in_array($ability, RolePolicy::GUARDED, true)) {
                return null;
            }

            return $user->isAdmin() ? true : null;
        });

        /*
         * SON GİRİŞ HANGİ KAPIDAN GİRİLİRSE GİRİLSİN yazılır: hem panelin
         * kendi parola girişi hem merkezle (KYM) giriş bu olayı üretiyor
         * (bkz. SonGirisiIsaretle).
         */
        Event::listen(Login::class, SonGirisiIsaretle::class);

        /* Ortak çıkış için oturumun açılış anı (bkz. OturumAcilisiniYaz). */
        Event::listen(Login::class, OturumAcilisiniYaz::class);

        /*
         * SAYFALAMA PANELİN KENDİ ŞERİDİYLE ÇİZİLİR.
         *
         * Şerit (vendor/pagination/default.blade.php) ve biçemi
         * (components/pagination.css) depoda durur ama çerçeveye hangi
         * görünümü kullanacağı AYRICA SÖYLENMELİ: Laravel'in varsayılanı
         * `pagination::tailwind` ve bu panelde Tailwind yok. Söylenmezse
         * içindeki SVG oklar hiçbir sınırı olmadan sayfa boyunda çizilir.
         */
        Paginator::defaultView('vendor.pagination.default');
        Paginator::defaultSimpleView('vendor.pagination.default');

        /*
         * $model->update($request->all()) ve benzeri kazaların sessizce
         * geçmemesi için: tanımsız alan yazılmaya çalışılırsa istisna atar.
         */
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());
        Model::preventLazyLoading(! $this->app->isProduction());

        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }
    }
}
