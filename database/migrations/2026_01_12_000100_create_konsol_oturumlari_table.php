<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Açık konsol oturumları.
 *
 * Oturumun KENDİSİ kısa ömürlüdür ve önbellekte durur; bu tablo onun
 * DENETİM İZİDİR. Ayrı durmasının sebebi: tarayıcı çökerse, makine
 * uykuya girerse ya da ağ koparsa istemci "kapandı" haberini gönderemiyor;
 * önbellekteki kayıt sessizce düşüyor ve geriye "açıldı" satırı olup
 * "kapandı" satırı olmayan bir iz kalıyordu.
 *
 * Burada duran satır, kapanışı kimsenin haber vermediği durumda da
 * zamanlayıcının fark etmesini sağlar (bkz. KonsolTemizleCommand).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('konsol_oturumlari', function (Blueprint $table): void {
            $table->id();
            $table->uuid('token')->unique();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('credential_id')->nullable()->constrained()->nullOnDelete();

            /* Kaydın kendisi silinse de izde ne olduğu yazılı kalsın. */
            $table->string('label');
            $table->string('protocol', 16);
            $table->unsignedInteger('port')->nullable();

            $table->timestamp('opened_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('closed_at')->nullable();
            $table->string('closed_reason', 32)->nullable();

            $table->timestamps();

            /* Zamanlayıcı "kapanmamış ve sesi kesilmiş" satırları arar. */
            $table->index(['closed_at', 'last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('konsol_oturumlari');
    }
};
