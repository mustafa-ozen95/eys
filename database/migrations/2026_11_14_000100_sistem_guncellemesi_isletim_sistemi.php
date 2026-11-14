<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Denetimin okuduğu işletim sistemi adı (/etc/os-release PRETTY_NAME):
 * ekranda makine adının altında küçük yazılır. Kaydın "işletim sistemi"
 * alanı yalnızca türü söyler (linux); sürümü makinenin kendisi bilir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('os_update_states', function (Blueprint $table): void {
            $table->string('os_name', 100)->nullable()->after('package_manager');
        });
    }

    public function down(): void
    {
        Schema::table('os_update_states', function (Blueprint $table): void {
            $table->dropColumn('os_name');
        });
    }
};
