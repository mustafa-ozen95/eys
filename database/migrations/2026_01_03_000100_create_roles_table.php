<?php

declare(strict_types=1);

use App\Enums\BadgeVariant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Roller veriye taşınır.
 *
 * Rol daha önce bir PHP enum'uydu; yeni bir rol açmak kod değişikliği
 * demekti. Artık rol bir KAYITTIR: panelden açılır, hazır yetkileri panelden
 * düzenlenir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            /* Kod ve seeder bu adla atıfta bulunur; sistem rollerinde değişmez. */
            $table->string('slug', 40)->unique();
            $table->string('label');
            $table->string('description')->nullable();
            $table->string('badge', 20)->default(BadgeVariant::Gray->value);

            /* Bütün kontrolleri geçen rol. En az biri KALMAK ZORUNDA. */
            $table->boolean('is_admin')->default(false);

            /* Yerleşik rol: silinemez, slug'ı değişmez. */
            $table->boolean('is_system')->default(false);

            /* Yeni kullanıcının varsayılan rolü. Yönetici rolü varsayılan olamaz. */
            $table->boolean('is_default')->default(false);

            /* Hazır yetki şablonu: kaynak => ['all' => [...], 'groups' => [...]] */
            $table->json('template')->nullable();

            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        $this->seedBuiltIn();

        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('role_id')->nullable()->after('email')->constrained('roles');
        });

        $this->linkUsers();

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('role', 40)->default('teknik')->after('email');
        });

        foreach (DB::table('roles')->get() as $role) {
            DB::table('users')->where('role_id', $role->id)->update(['role' => $role->slug]);
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['role_id']);
            $table->dropColumn('role_id');
        });

        Schema::dropIfExists('roles');
    }

    /**
     * Yerleşik roller. Şablonlar config/permissions.php içinde durur ki
     * kurulumdan sonra da tek yerden okunabilsin.
     */
    private function seedBuiltIn(): void
    {
        $templates = (array) config('permissions.templates', []);

        $roles = [
            [
                'slug' => 'admin', 'label' => 'Yönetici', 'badge' => BadgeVariant::Purple->value,
                'is_admin' => true, 'position' => 0,
                'description' => 'Bütün yetki kontrollerini geçer. En az bir yönetici rolü kalmak zorundadır.',
            ],
            [
                'slug' => 'network', 'label' => 'Network', 'badge' => BadgeVariant::Blue->value,
                'is_admin' => false, 'position' => 1,
                'description' => 'Tüm gruplarda görür; ağ gruplarında parolayı açar ve kaydı düzenler.',
            ],
            [
                'slug' => 'teknik', 'label' => 'Teknik', 'badge' => BadgeVariant::Gray->value,
                'is_admin' => false, 'position' => 2, 'is_default' => true,
                'description' => 'Tüm gruplarda adres, port ve kullanıcı adını görür; parola görmez, kayıt değiştirmez.',
            ],
        ];

        // Örnek veri kapalı SIFIRDAN kurulumda YALNIZCA yönetici rolü kurulur;
        // kurulumu yapan kendi rollerini panelden açar.
        //
        // Kullanıcısı olan bir kurulum yükseltiliyorsa öbür roller de kurulur:
        // yoksa linkUsers herkesi tek kalan role, YÖNETİCİYE bağlardı.
        if (! config('app.ornek_veri') && ! DB::table('users')->exists()) {
            $roles = array_slice($roles, 0, 1);
        }

        foreach ($roles as $role) {
            DB::table('roles')->insert([
                'is_default' => false,
                ...$role,
                'uuid' => (string) Str::uuid7(),
                'is_system' => true,
                'template' => json_encode($templates[$role['slug']] ?? [], JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Mevcut kullanıcıları yeni rollere bağlar.
     *
     * Rolü çözülemeyen kullanıcı YÖNETİCİ YAPILMAZ; en dar role düşer.
     * Eksik veri yetki üretmemeli.
     */
    private function linkUsers(): void
    {
        $ids = DB::table('roles')->pluck('id', 'slug');
        // 'teknik' yalnızca örnek veri kapalı sıfırdan kurulumda yok; orada
        // bağlanacak kullanıcı da yok (bkz. seedBuiltIn).
        $fallback = $ids['teknik'] ?? $ids->first();

        foreach ($ids as $slug => $id) {
            DB::table('users')->where('role', $slug)->update(['role_id' => $id]);
        }

        /*
         * Eski enum'da 'editor' vardı; karşılığı Network.
         *
         * `?? $fallback` ŞART: değer, güncellenecek satır olmasa da hesaplanıyor.
         * Örnek veri kapalı sıfırdan kurulumda 'network' yok ve bu satır
         * "Undefined array key" ile göçü düşürüyordu (yeni kurulum her
         * açılışta aynı göçte kalıyordu).
         */
        DB::table('users')->where('role', 'editor')->update(['role_id' => $ids['network'] ?? $fallback]);
        DB::table('users')->where('role', 'viewer')->update(['role_id' => $fallback]);
        DB::table('users')->whereNull('role_id')->update(['role_id' => $fallback]);
    }
};
