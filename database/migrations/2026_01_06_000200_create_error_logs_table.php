<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Uygulama hataları.
 *
 * Dosya günlüğü sunucuya girmeyi gerektiriyor; kuyrukta çalışan bir döndürme
 * patladığında bunu panelden görebilmek gerek.
 *
 * AYNI hata tekrar tekrar satır açmaz: imza (tür + mesaj + dosya + satır)
 * eşleşirse sayaç artar. Aksi hâlde tek bir döngü hatası ekranı doldurur ve
 * asıl önemli hata görünmez olurdu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('error_logs', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            /* Aynı hatayı tanımak için: tür|mesaj|dosya|satır özeti. */
            $table->string('signature', 64)->unique();

            $table->string('level', 20)->default('error');
            $table->string('type');
            $table->text('message');
            $table->string('file')->nullable();
            $table->unsignedInteger('line')->nullable();

            /* Nerede oldu: istek yolu ya da kuyruk işi. */
            $table->string('context_label')->nullable();
            $table->string('method', 10)->nullable();

            $table->foreignIdFor(User::class)->nullable()->constrained()->nullOnDelete();
            $table->string('actor_name')->nullable();
            $table->string('ip_address', 45)->nullable();

            /*
             * Yığın izi ARGÜMANSIZ saklanır: PHP'nin kendi izi çağrı
             * argümanlarını taşıyabiliyor ve orada parola geçebilir.
             * Yalnızca dosya, satır ve çağrılan metot yazılır.
             */
            $table->text('trace')->nullable();

            $table->unsignedInteger('occurrences')->default(1);
            $table->timestamp('first_seen_at')->useCurrent();
            $table->timestamp('last_seen_at')->useCurrent();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();

            $table->index(['resolved_at', 'last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('error_logs');
    }
};
