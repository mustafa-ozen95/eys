<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * KAYIT BAZINDA "KRİTİK" İŞARETİ.
 *
 * Gerekçe her işlemde soruluyordu. Bir web sunucusunun konsolunu açmak için
 * her seferinde "neden" yazmak gereksiz bir yüktü ve gerçekte şu sonuca
 * çıkıyordu: insanlar kutuya "test", "bakım", "." yazıyor
 * ve gerekçe alanı denetimde HİÇBİR ŞEY anlatmıyordu. Az sayıda kayıtta
 * sorulan gerekçe, her kayıtta sorulan gerekçeden daha çok bilgi taşır.
 *
 * Bundan sonra gerekçe YALNIZCA bu işareti taşıyan kayıtlarda istenir. Karar
 * tek yerde durur (bkz. App\Support\ReasonRule) ve iki uçta birden
 * uygulanır: onay penceresi alanı gizler, sunucu da aynı koşula bakar —
 * ekranda gizlemek doğrulama değildir.
 *
 * TOPLU İŞLEMLER BU İŞARETE BAKMAZ ve her zaman gerekçe ister: parolaları
 * dosyaya çıkarmak, toplu parola güncellemek ve grup silmek tek bir kaydın
 * işi değildir; hangi kayda bakılacağı belli olmadığı için "kritik mi"
 * sorusunun yanıtı da yoktur.
 *
 * BAŞLANGIÇ DEĞERİ. Varsayılan "kritik değil" — yoksa hiçbir şey değişmezdi.
 * İki küme bunun dışında tutuluyor ve ikisi de VERİDEN okunuyor, ad
 * eşleştirmesiyle değil:
 *
 *   1. Kayıt bazında role kapatılmış kayıtlar (credential_role_restrictions).
 *   2. "teknik" rolünün göremediği grupların kayıtları.
 *
 * İkisi de "bunu herkes görmesin" diye ayrılmış kayıtlardır; kritik
 * sayılacak ilk küme olarak en yakın şey budur. Bu YALNIZCA BAŞLANGIÇTIR:
 * işaret bundan sonra kayıt formundan tek tek yönetilir ve bu göç bir daha
 * koşmaz.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credentials', function (Blueprint $table): void {
            $table->boolean('is_critical')->default(false)->after('is_active');
        });

        $kritik = array_unique(array_merge(
            $this->kisitliKayitlar(),
            $this->teknigeKapaliGruplarinKayitlari(),
        ));

        if ($kritik !== []) {
            DB::table('credentials')->whereIn('id', $kritik)->update(['is_critical' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('credentials', function (Blueprint $table): void {
            $table->dropColumn('is_critical');
        });
    }

    /** @return array<int, int> */
    private function kisitliKayitlar(): array
    {
        if (! Schema::hasTable('credential_role_restrictions')) {
            return [];
        }

        return DB::table('credential_role_restrictions')
            ->distinct()
            ->pluck('credential_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * "teknik" rolünün GÖREMEDİĞİ grupların kayıtları.
     *
     * Şablon grup ADIYLA saklanıyor (bkz. RolePermissions): burada da adla
     * eşleştirmek zorundayız ama bu tek seferlik bir okuma. Eşleşmeyen ad
     * "göremiyor" tarafına düşer, yani hata güvenli yöne — bir kayıt fazladan
     * kritik olur, eksik olmaz.
     *
     * @return array<int, int>
     */
    private function teknigeKapaliGruplarinKayitlari(): array
    {
        $sablon = DB::table('roles')->where('slug', 'teknik')->value('template');

        if (! is_string($sablon)) {
            return [];
        }

        $tanim = json_decode($sablon, true)['credential_group'] ?? [];

        /* Rol bütün gruplarda görüyorsa kapalı grup yoktur. */
        if (in_array('view', (array) ($tanim['all'] ?? []), true)) {
            return [];
        }

        $gorunen = [];

        foreach ((array) ($tanim['groups'] ?? []) as $ad => $aksiyonlar) {
            if (in_array('view', (array) $aksiyonlar, true)) {
                $gorunen[] = (string) $ad;
            }
        }

        $kapaliGruplar = DB::table('credential_groups')
            ->whereNotIn('name', $gorunen)
            ->pluck('id');

        return DB::table('credentials')
            ->whereIn('credential_group_id', $kapaliGruplar)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }
};
