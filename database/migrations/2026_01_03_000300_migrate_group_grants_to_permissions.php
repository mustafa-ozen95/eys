<?php

declare(strict_types=1);

use App\Enums\PermissionEffect;
use App\Models\UserPermission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Eski grup izinleri yeni yetki satırlarına taşınır.
 *
 * Eskiden iki kutu vardı: "görebilir" ve "parolayı okuyabilir". Yeni modelde
 * her alan ayrı bir yetkidir. Taşıma sırasında eski "görebilir" izni, parola
 * DIŞINDAKİ bütün alanlara karşılık gelir — eski davranış buydu.
 *
 * İzin listesi iki tabloda birden durmaz: taşıma bitince eski tablo düşer.
 * İki kaynak olsaydı biri güncellenip diğeri unutulurdu.
 */
return new class extends Migration
{
    private const RESOURCE = 'credential_group';

    /** Eski "görebilir" izninin karşılığı. */
    private const VIEW_ACTIONS = [
        'view',
        'field.host',
        'field.port',
        'field.url',
        'field.username',
        'field.notes',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('credential_group_user')) {
            return;
        }

        $rows = [];
        $now = now();

        foreach (DB::table('credential_group_user')->get() as $grant) {
            $actions = self::VIEW_ACTIONS;

            if ((bool) $grant->can_reveal) {
                $actions[] = 'field.password';
            }

            foreach ($actions as $action) {
                $rows[] = [
                    'user_id' => $grant->user_id,
                    'resource' => self::RESOURCE,
                    'resource_id' => $grant->credential_group_id,
                    'action' => $action,
                    'effect' => PermissionEffect::Allow->value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('user_permissions')->insertOrIgnore($chunk);
        }

        Schema::dropIfExists('credential_group_user');
    }

    public function down(): void
    {
        Schema::create('credential_group_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('credential_group_id')->constrained()->cascadeOnDelete();
            $table->boolean('can_reveal')->default(false);
            $table->timestamps();
            $table->unique(['user_id', 'credential_group_id']);
        });

        $rows = DB::table('user_permissions')
            ->where('resource', self::RESOURCE)
            ->where('resource_id', '!=', UserPermission::ALL_RECORDS)
            ->where('effect', PermissionEffect::Allow->value)
            ->get()
            ->groupBy(fn ($row): string => $row->user_id.':'.$row->resource_id);

        $now = now();

        foreach ($rows as $key => $group) {
            [$userId, $groupId] = explode(':', (string) $key);

            DB::table('credential_group_user')->insert([
                'user_id' => (int) $userId,
                'credential_group_id' => (int) $groupId,
                'can_reveal' => $group->contains('action', 'field.password'),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('user_permissions')->where('resource', self::RESOURCE)->delete();
    }
};
