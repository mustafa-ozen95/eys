<?php

declare(strict_types=1);

use App\Models\CredentialGroup;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credential_group_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(CredentialGroup::class)->constrained()->cascadeOnDelete();

            /*
             * "Görebilir" ile "parolayı okuyabilir" AYRI izinlerdir. Gruba
             * erişimi olan herkes parolayı da görebilseydi ayrı bir izin
             * tanımlamanın anlamı kalmazdı.
             */
            $table->boolean('can_reveal')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'credential_group_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credential_group_user');
    }
};
