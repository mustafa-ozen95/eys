<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table): void {
            $table->id();

            /*
             * Kullanıcı silinse de kayıt kalır (nullOnDelete): denetim kaydı
             * "kim yaptı" bilgisini kaybedebilir ama "ne oldu" bilgisini
             * kaybetmemeli.
             */
            $table->foreignIdFor(User::class)->nullable()->constrained()->nullOnDelete();
            $table->string('actor_name');
            $table->string('action', 30);
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('summary');
            $table->string('reason')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['subject_type', 'subject_id']);
            $table->index(['action', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
