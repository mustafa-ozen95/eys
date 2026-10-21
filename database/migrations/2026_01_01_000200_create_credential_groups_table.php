<?php

declare(strict_types=1);

use App\Enums\GroupKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credential_groups', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();
            $table->string('name');
            $table->string('kind', 30)->default(GroupKind::Other->value);
            $table->string('description')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->unique('name');
            $table->index(['position', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credential_groups');
    }
};
