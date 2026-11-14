<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LİNUX SİSTEM GÜNCELLEMELERİ: kaydın son kontrolü, son kurulumu ve kilidi.
 *
 * Kayıt tablosuna sütun eklenmez: bu bilgiler yalnızca SSH'lı Linux
 * kayıtlarında var ve sıkça yazılıyor (4 saatte bir); kasanın kendisine
 * dokunan her yazım etkinlik zincirine ve "son düzenleme" zamanına karışırdı.
 *
 * KİLİT burada durur ve makine bazında uygulanır (aynı adres + port): bir
 * makinenin iki kaydından birinde kilit varsa ikisinden de kurulum yapılmaz
 * (bkz. OsUpdateTargets::kilit).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('os_update_states', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('credential_id')->unique()->constrained()->cascadeOnDelete();

            /* Son kontrol. Paket listesi yalnızca ad/sürüm/kritik taşır; komut çıktısı saklanmaz. */
            $table->string('package_manager', 10)->nullable();
            $table->unsignedInteger('critical_count')->nullable();
            $table->unsignedInteger('normal_count')->nullable();
            $table->json('packages')->nullable();
            $table->boolean('reboot_required')->default(false);
            $table->timestamp('checked_at')->nullable();
            $table->string('check_error', 300)->nullable();

            /* Son kurulum. */
            $table->string('apply_status', 12)->nullable();
            $table->string('apply_mode', 10)->nullable();
            $table->timestamp('apply_queued_at')->nullable();
            $table->timestamp('apply_finished_at')->nullable();
            $table->string('apply_error', 300)->nullable();
            $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();

            /* Kilit: kritik sunucuya kurulum yapılmaz — toplu seçim dahil. */
            $table->boolean('locked')->default(false);
            $table->string('lock_reason', 190)->nullable();
            $table->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('locked_at')->nullable();

            $table->timestamps();

            $table->index('checked_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('os_update_states');
    }
};
