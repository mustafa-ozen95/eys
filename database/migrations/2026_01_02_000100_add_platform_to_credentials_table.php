<?php

declare(strict_types=1);

use App\Enums\OperatingSystem;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credentials', function (Blueprint $table): void {
            /*
             * Platform kaydın kendisinde durur, grubunda değil: bir grup her
             * zaman tek bir makine değildir (bir grupta farklı işletim
             * sistemli sunucular ya da ayrı ayrı PC'ler durabilir).
             */
            $table->string('operating_system', 30)
                ->default(OperatingSystem::Unknown->value)
                ->after('access_type');

            /* Sürüm/dağıtım: "Ubuntu 24", "Windows Server 2022", "iLO 5". Gizli değil. */
            $table->string('platform', 60)->nullable()->after('operating_system');

            $table->index('operating_system');
        });
    }

    public function down(): void
    {
        Schema::table('credentials', function (Blueprint $table): void {
            $table->dropIndex(['operating_system']);
            $table->dropColumn(['operating_system', 'platform']);
        });
    }
};
