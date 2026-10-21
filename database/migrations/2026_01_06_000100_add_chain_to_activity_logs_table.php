<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Denetim kaydına hash zinciri eklenir.
 *
 * Her satır kendinden öncekinin özetini taşır. Bir satır sonradan
 * değiştirilir ya da silinirse ondan sonraki bütün özetler tutmaz ve
 * doğrulama kırılmanın tam yerini söyler.
 *
 * Zincirden ÖNCE yazılmış satırların özeti YOKTUR ve sonradan
 * özetlenmezler: o satırların değişmediğine dair elimizde kanıt yok, öyleymiş
 * gibi davranmak zincirin bütün anlamını ortadan kaldırırdı. Doğrulama
 * onları ayrıca "korumasız" diye sayar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_logs', function (Blueprint $table): void {
            $table->string('chain', 20)->default('audit')->after('id');
            $table->unsignedTinyInteger('chain_version')->nullable()->after('chain');
            $table->string('previous_hash', 64)->nullable()->after('user_agent');
            $table->string('hash', 64)->nullable()->after('previous_hash');

            $table->index(['chain', 'id']);
        });
    }

    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table): void {
            $table->dropIndex(['chain', 'id']);
            $table->dropColumn(['chain', 'chain_version', 'previous_hash', 'hash']);
        });
    }
};
