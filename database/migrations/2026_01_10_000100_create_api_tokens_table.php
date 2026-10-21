<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * API jetonları.
 *
 * Jetonun kendisi SAKLANMAZ; yalnızca sha256 özeti tutulur. Veritabanını ele
 * geçiren biri jetonları kullanamaz, panel de kaybolan bir jetonu geri
 * gösteremez — yenisi üretilir.
 *
 * "prefix" sırrın ilk birkaç karakteridir ve ekranda basılır: kullanıcı
 * hangi jetonu iptal ettiğini bilmek zorunda.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_tokens', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            $table->string('name');

            /*
             * Jetonun SAHİBİ. Jeton onun adına konuşur ve onun yetkilerini
             * AŞAMAZ. Kullanıcı silinirse jetonları da silinir: sahipsiz
             * jeton kimin adına konuştuğu belirsiz bir anahtardır.
             */
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Jetonu ÜRETEN. Sahibi başkası olabilir; kim çıkardı kayda geçer.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('token_hash', 64)->unique();
            $table->string('prefix', 16);

            /*
             * Jetonun yetkileri: kaynak => aksiyonlar. Paneldeki yetki
             * sözlüğünün aynısı; jeton kendi başına yeni bir yetki türü
             * icat etmez.
             */
            $table->json('abilities')->nullable();

            /*
             * İzinli adresler (IP ya da CIDR). Boş bırakılırsa her yerden
             * kabul edilir; ekran bunu uyarı olarak söyler.
             */
            $table->json('allowed_ips')->nullable();

            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();

            $table->timestamp('last_used_at')->nullable();
            $table->string('last_used_ip', 45)->nullable();

            $table->timestamps();

            $table->index(['user_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_tokens');
    }
};
