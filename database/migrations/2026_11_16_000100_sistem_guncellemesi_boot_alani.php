<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Denetimin okuduğu /boot boş alanı (MB). Yeni çekirdek sığmazsa kurulum
 * hiç başlamadan düşüyordu (16.11.2026, Barkod Yazıcı: "needs 3MB more
 * space on the /boot filesystem"); ekran bunu kurulumdan ÖNCE söyler.
 * Boş: henüz okunmadı.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('os_update_states', function (Blueprint $table): void {
            $table->unsignedInteger('boot_free_mb')->nullable()->after('reboot_required');
        });
    }

    public function down(): void
    {
        Schema::table('os_update_states', function (Blueprint $table): void {
            $table->dropColumn('boot_free_mb');
        });
    }
};
