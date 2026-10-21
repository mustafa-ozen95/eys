<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->uuid()->after('id')->unique();
            /* Bu sütunu 2026_01_03_000100 kaldırır; rol artık bir kayıt. */
            $table->string('role', 20)->default('viewer')->after('password');
            $table->string('status', 20)->default('active')->after('role');
            $table->timestamp('last_login_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['uuid', 'role', 'status', 'last_login_at']);
        });
    }
};
