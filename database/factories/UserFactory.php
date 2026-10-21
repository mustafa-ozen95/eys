<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    protected $model = User::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => Hash::make('gecerli-parola-1!'),
            /*
             * VARSAYILAN ROL HİÇBİR ŞEY VERMEZ.
             *
             * Rol artık canlı okunuyor (bkz. RolePermissions): şablonu dolu
             * bir rolde açılan kullanıcı daha ilk satırda yetkili olurdu ve
             * "şu izni verince ne oluyor" sorusunu soran testler ölçemezdi.
             * Rol davranışını sınamak isteyen test ->teknik(), ->network()
             * ya da ->admin() der.
             */
            'role_id' => $this->roleId('yetkisiz'),
            'status' => UserStatus::Active,
            'remember_token' => null,
        ];
    }

    public function admin(): static
    {
        return $this->state(fn (): array => ['role_id' => $this->roleId('admin')]);
    }

    public function network(): static
    {
        return $this->state(fn (): array => ['role_id' => $this->roleId('network')]);
    }

    /** Yerleşik "teknik" rolü: görür, parolayı açmaz. */
    public function teknik(): static
    {
        return $this->state(fn (): array => ['role_id' => $this->roleId('teknik')]);
    }

    public function passive(): static
    {
        return $this->state(['status' => UserStatus::Passive]);
    }

    /**
     * Yerleşik roller migration sırasında açılır; testte de oradan gelir.
     * Bulunamazsa oluşturulur ki fabrika tek başına da çalışsın.
     */
    private function roleId(string $slug): int
    {
        return Role::query()->firstOrCreate(
            ['slug' => $slug],
            ['label' => ucfirst($slug), 'is_admin' => $slug === 'admin', 'is_system' => true, 'template' => []],
        )->getKey();
    }
}
