<?php

declare(strict_types=1);

use App\Models\Credential;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credentials', function (Blueprint $table): void {
            /*
             * Sürücü normalde platformdan türer (Linux -> ssh). Bu sütun onu
             * EZER: aynı platformda farklı sürücü isteyen cihazlar var
             * (ATEN ile Lantronix ikisi de "gömülü" ama aynı yolu konuşmuyor).
             * Boşsa platformun varsayılanı geçerlidir.
             */
            $table->string('rotation_driver', 40)->nullable()->after('platform');

            /*
             * Döndürmenin hangi hesapla yapılacağı. Boşsa kaydın kendi
             * kullanıcısı kullanılır — ama o zaman döndürülen parolayla
             * bağlanılmış olur ve bir hata kilitlenmeye yol açabilir.
             */
            $table->string('rotation_username')->nullable()->after('rotation_driver');
            $table->text('rotation_password')->nullable()->after('rotation_username');

            $table->boolean('rotation_enabled')->default(false)->after('rotation_password');
            $table->timestamp('rotated_at')->nullable()->after('rotation_enabled');
        });

        Schema::create('password_rotations', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignIdFor(Credential::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(User::class)->nullable()->constrained()->nullOnDelete();
            $table->string('actor_name');
            $table->string('status', 20);

            /*
             * Denenen parola HER ZAMAN saklanır — başarısız denemede bile.
             *
             * "Cihazda değişti ama doğrulanamadı" hâlinde kasadaki parola
             * eski, cihazdaki yeni olur. Denenen parola saklanmazsa cihaza
             * bir daha girilemez. Şifreli tutulur ve hiçbir yanıtta basılmaz.
             */
            $table->text('attempted_password')->nullable();
            $table->text('previous_password')->nullable();

            $table->string('message')->nullable();
            $table->timestamps();

            $table->index(['credential_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_rotations');

        Schema::table('credentials', function (Blueprint $table): void {
            $table->dropColumn([
                'rotation_driver',
                'rotation_username',
                'rotation_password',
                'rotation_enabled',
                'rotated_at',
            ]);
        });
    }
};
