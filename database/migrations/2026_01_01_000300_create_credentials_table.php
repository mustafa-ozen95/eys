<?php

declare(strict_types=1);

use App\Enums\AccessType;
use App\Models\CredentialGroup;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credentials', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->foreignIdFor(CredentialGroup::class)->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('access_type', 30)->default(AccessType::Other->value);
            $table->string('host')->nullable();
            $table->unsignedSmallInteger('port')->nullable();
            $table->string('url')->nullable();
            $table->string('username')->nullable();

            /*
             * Parola ve notlar ŞİFRELİ saklanır: veritabanı dökümü tek başına
             * hiçbir erişim bilgisi vermemeli. Şifreli sütun aranamaz — bu
             * bilinçli: parolada arama yapılmaz.
             */
            $table->text('password')->nullable();
            $table->text('notes')->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['credential_group_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credentials');
    }
};
