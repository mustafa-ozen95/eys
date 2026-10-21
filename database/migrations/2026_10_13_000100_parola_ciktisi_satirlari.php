<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TOPLU PAROLA ÇIKTISININ AYRINTISI.
 *
 * Denetim kaydına çıktı başına TEK satır yazılır ("12 adet parola dosyaya
 * çıkarıldı" gibi; kayıt başına satır okunaklı değildi). "Hangi parola
 * dışarı çıktı" sorusunun cevabı burada durur ve
 * kayıtlar ekranındaki ayrıntı penceresinde görünür.
 *
 * Grup ve kayıt adı o anki hâliyle KOPYALANIR: kayıt sonradan silinse ya da
 * adı değişse bile çıktıda ne gittiği okunur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parola_ciktisi_satirlari', function (Blueprint $table): void {
            $table->id();
            /* Denetim satırı budanırsa ayrıntısı da gider. */
            $table->foreignId('activity_log_id')->constrained('activity_logs')->cascadeOnDelete();
            $table->foreignId('credential_id')->nullable()->constrained()->nullOnDelete();
            $table->string('grup')->default('');
            $table->string('kayit');
            $table->index('credential_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parola_ciktisi_satirlari');
    }
};
