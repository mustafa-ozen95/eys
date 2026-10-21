<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GÜNCELLEME HESABI ARTIK BİR KAYDA BAĞLANABİLİR.
 *
 * Plesk gibi sürücülerde parolayı değiştiren şey makinenin SSH kimliğidir ve
 * bu kimlik `rotation_username`/`rotation_password` alanlarına KOPYALANIYORDU.
 * Kopya iki sorun demek:
 *
 *  - SSH kaydının parolası döndüğünde kopya sessizce eskir; bir sonraki tur
 *    "bağlanamadı" der ve sebebi görünmez.
 *  - Aynı sır kasada iki yerde durur.
 *
 * Bu sütun kopya yerine BAĞ kurar: hangi kaydın kimliğiyle bağlanılacağı
 * seçilir (formda SSH kayıtlarından biri), parola her turda o kayıttan okunur.
 *
 * ESKİ ALANLAR DURUYOR: elle girilmiş kimlikler çalışmaya devam etsin ve
 * kasada karşılığı olmayan bir hesap hâlâ verilebilsin diye.
 *
 * SİLİNEN KAYIT BAĞI KOPARIR (nullOnDelete): bağlı kayıt silinince bu kayıt
 * "kimliksiz" kalır ve ekran bunu söyler — yanlış bir kimliğe bağlı kalmak
 * ya da silmeyi engellemek, ikisi de daha kötü olurdu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credentials', function (Blueprint $table): void {
            $table->foreignId('rotation_credential_id')
                ->nullable()
                ->after('rotation_password')
                ->constrained('credentials')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('credentials', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('rotation_credential_id');
        });
    }
};
