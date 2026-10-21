<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * ORTAK ÇIKIŞ: kişinin merkezdeki son çıkış anı (ms). Bu andan önce
 * açılmış panel oturumu kapanır (bkz. App\Services\Identity\SharedLogout).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_identities', function (Blueprint $table): void {
            $table->unsignedBigInteger('merkez_cikis_ms')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('user_identities', function (Blueprint $table): void {
            $table->dropColumn('merkez_cikis_ms');
        });
    }
};
