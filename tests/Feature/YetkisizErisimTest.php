<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as Rota;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * "Giriş yapmış ama yetkisi olmayan kullanıcı hiçbir şey göremez."
 *
 * GuestAccessTest oturumsuz erişimi kapatıyor; bu onun eşi: oturum AÇMIŞ ama
 * hiçbir izni olmayan bir kullanıcı, parametresiz her GET ucunu sırayla
 * dener ve hiçbirinden 200 alamaz.
 *
 * İŞİ AÇIĞIN KAPANDIĞINI GÖSTERMEK DEĞİL, BİR DAHA AÇILAMAYACAĞINI
 * göstermektir: yetki kontrolü unutulmuş her YENİ uç burada patlar. Rotaları
 * tek tek okuyarak doğrulamak bir defalıktır; bu sınama kalıcıdır.
 *
 * SERBEST LİSTESİ ELLE TUTULUR ve kısa kalmalıdır. Buraya bir uç eklemek,
 * "bu ucu izni olmayan biri de görebilir" demektir — gerekçesi yanında
 * yazılmalı.
 */
class YetkisizErisimTest extends TestCase
{
    use RefreshDatabase;

    /**
     * İzin gerektirmeyen uçlar.
     *
     * @var array<string, string> rota adı => neden serbest
     */
    private const SERBEST = [
        'credentials.index' => 'Kişisel alan: herkes KENDİ kayıtlarını görür. '
            .'Ekip kayıtları listede çıkmaz — süzme sorguda (Credential::scopeVisibleTo), '
            .'yetkisi olmayan boş bir liste görür.',
        'profil' => 'Kendi hesabı: herkes kendi adını ve e-postasını görür.',
        'hesap.profil' => 'Kendi hesabı.',
        'dashboard' => 'Karşılama ekranı; veri taşımaz.',
        'home' => 'Karşılama ekranı.',
        'login' => 'Giriş ekranı.',
        'merkez.giris' => 'Kimlik sağlayıcıya yönlendirme.',
        'merkez.geri' => 'Kimlik sağlayıcıdan dönüş.',
    ];

    /** Kimliğini kendi çerezi taşıyan, panelin oturumuna bakmayan uçlar. */
    private const CIHAZ_ARAYUZU_GRUBU = 'cihaz-arayuzu';

    #[Test]
    public function izinsiz_kullanici_parametresiz_hicbir_get_ucunu_acamaz(): void
    {
        /* Fabrikanın varsayılanı zaten yerleşik "yetkisiz" rolü: yönetici
           değil, hiçbir yetki satırı yok (bkz. UserFactory). */
        $kullanici = User::factory()->create();

        $rotalar = $this->denenecekRotalar();

        /*
         * SINAMANIN KENDİSİ SINANIR.
         *
         * Liste boş kalsaydı bu sınama hiçbir şey denemeden geçerdi ve
         * "yetkiler kapalı" diye yanlış bir güven verirdi. Süzgeçlerden biri
         * fazla geniş olursa (örneğin rota adları değişirse) burası düşer.
         */
        $this->assertGreaterThanOrEqual(
            10,
            count($rotalar),
            'Denenecek uç kalmadı: süzgeçler fazla geniş, sınama boşa dönüyor.',
        );

        $acilanlar = [];

        foreach ($rotalar as $rota) {
            $yanit = $this->actingAs($kullanici)->get($rota->uri() === '/' ? '/' : '/'.ltrim($rota->uri(), '/'));

            if ($yanit->getStatusCode() === 200) {
                $acilanlar[] = sprintf(
                    '%s (%s)',
                    $rota->uri(),
                    $rota->getName() ?? $rota->getActionName(),
                );
            }
        }

        $this->assertSame(
            [],
            $acilanlar,
            "İzni olmayan kullanıcı şu uçları açabildi:\n  ".implode("\n  ", $acilanlar)
                ."\nYa yetki kontrolü eksik ya da uç bilerek serbest — ikincisiyse SERBEST listesine gerekçesiyle ekleyin.",
        );
    }

    /**
     * @return array<int, Rota>
     */
    private function denenecekRotalar(): array
    {
        $secilen = [];

        foreach (Route::getRoutes()->getRoutes() as $rota) {
            if (! in_array('GET', $rota->methods(), true)) {
                continue;
            }

            /* Parametreli uçlar ayrı ele alınır: uydurulmuş bir kimlik 404
               döndürür ve o 404, yetki kontrolünün varlığını KANITLAMAZ. */
            if ($rota->parameterNames() !== []) {
                continue;
            }

            $ara = array_map(fn ($m) => is_string($m) ? $m : '', $rota->gatherMiddleware());

            /* Oturum istemeyen uçlar GuestAccessTest'in işi. */
            if (! in_array('auth', $ara, true)) {
                continue;
            }

            if (in_array(self::CIHAZ_ARAYUZU_GRUBU, $ara, true)) {
                continue;
            }

            if (array_key_exists((string) $rota->getName(), self::SERBEST)) {
                continue;
            }

            $secilen[] = $rota;
        }

        return $secilen;
    }
}
