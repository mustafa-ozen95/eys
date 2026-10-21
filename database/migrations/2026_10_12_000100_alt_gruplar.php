<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ALT GRUPLAR.
 *
 * Kenar çubuğu kalabalıklaşır: "Yedek 1", "Yedek 2" gibi aynı sistemin
 * parçaları ayrı ayrı grup olarak durursa menü tekrarla dolar. Bunları tek
 * grupta toplayıp listede alt başlık olarak göstermek tekrarı azaltır.
 *
 * GRUP OLDUĞU GİBİ KALIR: yetki sınırı yine gruptur, rol şablonları yine
 * grup ADIYLA çalışır, hiçbir kayıt ve yetki taşınmaz. Alt grup bir grubun
 * İÇİNDEKİ bölmedir — kenar çubuğunda grubun altında, listede başlık olarak
 * görünür. Yetki TAŞIMAZ: alt gruba göre "bu rol görmesin" demek için kayıt
 * bazlı kısıt zaten var (credential_role_restrictions).
 *
 * NEDEN AYRI TABLO, NEDEN KAYDA SERBEST METİN DEĞİL: aynı ad on kayda elle
 * yazılsaydı birinde "Yedek 1", öbüründe "Yedek1" olurdu ve iki başlık
 * çıkardı. Ad tek yerde durur, kayıt ona BAĞLANIR.
 *
 * ALT GRUP SİLİNİRSE KAYIT SİLİNMEZ (`nullOnDelete`): kayıt grubunda,
 * başlıksız olarak kalır. Grup silinirse alt grupları da gider — grubun
 * kayıtları zaten gidiyor, boş bölme başlığı kalmasın.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alt_gruplar', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('credential_group_id')->constrained('credential_groups')->cascadeOnDelete();
            $table->string('name', 120);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            /* Aynı grupta aynı ad iki kez olmaz: iki başlık tek bölme demektir. */
            $table->unique(['credential_group_id', 'name']);
            $table->index(['credential_group_id', 'position']);
        });

        Schema::table('credentials', function (Blueprint $table): void {
            $table->foreignId('alt_grup_id')->nullable()->after('credential_group_id')
                ->constrained('alt_gruplar')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('credentials', function (Blueprint $table): void {
            $table->dropForeign(['alt_grup_id']);
            $table->dropColumn('alt_grup_id');
        });

        Schema::dropIfExists('alt_gruplar');
    }
};
