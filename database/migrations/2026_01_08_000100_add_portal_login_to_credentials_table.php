<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Portal oturumu için giriş formu tanımı.
 *
 * Amaç, parolayı kullanıcıya HİÇ göstermeden hedef sitenin giriş alanına
 * göndermek. Tarayıcı çapraz kökene FORM GÖNDERİMİNE izin veriyor (JS
 * enjeksiyonuna izin vermiyor); bu yüzden kendi sunucumuzun ürettiği geçici
 * bir sayfa, alanları doldurup hedefe POST ediyor.
 *
 * Alan adları siteden siteye değişiyor ve tahmin edilemez; kayıt başına
 * yazılır.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credentials', function (Blueprint $table): void {
            /* Giriş formunun POST adresi. Boşsa portal oturumu kapalıdır. */
            $table->string('login_url', 500)->nullable()->after('url');

            $table->string('login_username_field', 100)->nullable()->after('login_url');
            $table->string('login_password_field', 100)->nullable()->after('login_username_field');

            /*
             * Formun istediği sabit ek alanlar (gizli seçim kutusu, dil,
             * "beni hatırla" gibi). ad => değer.
             */
            $table->json('login_extra_fields')->nullable()->after('login_password_field');
        });
    }

    public function down(): void
    {
        Schema::table('credentials', function (Blueprint $table): void {
            $table->dropColumn(['login_url', 'login_username_field', 'login_password_field', 'login_extra_fields']);
        });
    }
};
