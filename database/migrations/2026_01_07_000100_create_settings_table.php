<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Çalışma zamanı ayarları.
 *
 * Anahtar, ÜZERİNE BİNDİRİLEN config yoludur ("rotation.password_length");
 * değer JSON metnidir. Böylece ayarın nereye etki ettiği anahtarın kendisinden
 * okunur ve kodda ikinci bir eşleme tutmak gerekmez.
 *
 * Satırı olmayan ayar varsayılanında kalır; bir ayarı "sıfırlamak" satırı
 * silmektir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->foreignIdFor(User::class, 'updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
