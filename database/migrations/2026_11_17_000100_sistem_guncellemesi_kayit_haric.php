<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KAYIT BAZLI "güncellemeden hariç". Kilit MAKİNEYİ korur (kardeş
 * kayıtların hepsi); bu işaret yalnızca O KAYDI: kayıtla makineye hiç
 * bağlanılmaz (kurulum, yeniden başlatma, denetim), makine kardeş kayıttan
 * güncellenmeye devam eder.
 *
 * 17.11.2026, Barkod Yazıcı: aynı makinede root kaydı ve root yetkisi
 * olmayan dgnbarkod kaydı. dgnbarkod seçildikçe "Kurulamadı" yazıyordu;
 * kilit ise root kaydını da kapatırdı.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('os_update_states', function (Blueprint $table): void {
            $table->boolean('excluded')->default(false)->after('locked_at');
            $table->string('exclude_reason')->nullable()->after('excluded');
        });
    }

    public function down(): void
    {
        Schema::table('os_update_states', function (Blueprint $table): void {
            $table->dropColumn(['excluded', 'exclude_reason']);
        });
    }
};
