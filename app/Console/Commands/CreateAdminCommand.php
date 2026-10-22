<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * İlk yöneticiyi açar.
 *
 * Kayıt olma ekranı YOKTUR; ilk hesap buradan, sunucuya erişebilen biri
 * tarafından açılır. Parola komut satırına yazılmaz, sorulur: kabuk geçmişine
 * ve `ps` çıktısına düşmesin.
 */
class CreateAdminCommand extends Command
{
    protected $signature = 'admin:add {--name=} {--email=}';

    protected $description = 'Panele yönetici yetkili bir kullanıcı ekler';

    public function handle(): int
    {
        $name = $this->option('name') ?: $this->ask('Ad soyad');
        $email = $this->option('email') ?: $this->ask('E-posta');
        $password = $this->secret('Parola (en az 12 karakter; harf, rakam ve simge)');

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password],
            [
                'name' => ['required', 'string', 'max:120'],
                'email' => ['required', 'email', 'max:190', 'unique:users,email'],
                'password' => ['required', Password::min(12)->letters()->numbers()->symbols()],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $role = Role::query()->where('is_admin', true)->orderBy('position')->first();

        if ($role === null) {
            $this->error('Yönetici rolü bulunamadı. Önce migration çalıştırın.');

            return self::FAILURE;
        }

        $user = User::create([
            ...$validator->validated(),
            'role_id' => $role->getKey(),
            'status' => UserStatus::Active,
        ]);

        $this->info("Yönetici eklendi: {$user->email}");

        return self::SUCCESS;
    }
}
