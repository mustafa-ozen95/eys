<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AccessType;
use App\Enums\OperatingSystem;
use App\Models\Credential;
use App\Models\CredentialGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Credential> */
class CredentialFactory extends Factory
{
    protected $model = Credential::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'credential_group_id' => CredentialGroup::factory(),
            'name' => fake()->unique()->words(2, true),
            'access_type' => AccessType::Ssh,
            'operating_system' => OperatingSystem::Linux,
            'platform' => 'Ubuntu 24',
            'host' => fake()->ipv4(),
            'port' => 22,
            'url' => null,
            'username' => 'root',
            'password' => 'cok-gizli-parola',
            'notes' => null,
            'is_active' => true,
            'position' => 0,
        ];
    }

    public function withoutPassword(): static
    {
        return $this->state(['password' => null]);
    }
}
