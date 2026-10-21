<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * activity_logs.user_id artık yabancı anahtar DEĞİL.
 *
 * Kullanıcı silinince "ON DELETE SET NULL" o kişinin satırlarının user_id'sini
 * veritabanı kendisi NULL yapar; user_id özete girdiği için denetim zinciri
 * kalıcı kırılır. Kısıt kalkınca silinen kullanıcının satırları olduğu gibi
 * kalır (kim yaptı bilgisi de korunur); ilişki zaten kullanıcı yoksa boş döner.
 *
 * Sütun, tip ve indeks aynı kalır; mevcut satırlara dokunulmaz.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_logs', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
        });
    }

    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table): void {
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }
};
