<?php

declare(strict_types=1);

use App\Enums\RotationStage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Döndürme artık bir KURTARMA HESABI üzerinden yapılıyor.
 *
 * Önceki tasarımda geri dönüş yolu açık kalan SSH oturumuydu; oturum koparsa
 * geri alacak yol kalmıyordu. Kurtarma hesabı oturumdan bağımsızdır ve tur
 * yarıda kalsa bile makinede durur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('password_rotations', function (Blueprint $table): void {
            $table->string('stage', 30)->default(RotationStage::Started->value)->after('status');

            $table->string('recovery_username')->nullable()->after('previous_password');
            $table->text('recovery_password')->nullable()->after('recovery_username');

            /*
             * Kurtarma hesabı makinede DURUYOR mu? Tur yarıda kaldıysa bu
             * bayrak açık kalır ve ekran uyarır: açıkta duran fazladan bir
             * yetki, kapatılana kadar bir açıktır.
             */
            $table->boolean('recovery_active')->default(false)->after('recovery_password');

            $table->timestamp('finished_at')->nullable()->after('message');

            $table->index('recovery_active');
        });
    }

    public function down(): void
    {
        Schema::table('password_rotations', function (Blueprint $table): void {
            $table->dropIndex(['recovery_active']);
            $table->dropColumn([
                'stage',
                'recovery_username',
                'recovery_password',
                'recovery_active',
                'finished_at',
            ]);
        });
    }
};
