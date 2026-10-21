<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApiToken>
 */
class ApiTokenFactory extends Factory
{
    public function definition(): array
    {
        $secret = ApiToken::mint();

        return [
            'name' => fake()->unique()->words(2, true),
            'user_id' => User::factory(),
            'token_hash' => $secret['hash'],
            'prefix' => $secret['prefix'],
            'abilities' => ['switches' => ['view']],
            'allowed_ips' => [],
            'expires_at' => null,
        ];
    }

    /**
     * Düz metin jetonu testin görebilmesi için sabitler.
     */
    public function withPlain(string $plain): static
    {
        return $this->state(fn (): array => [
            'token_hash' => hash('sha256', $plain),
            'prefix' => substr($plain, 0, 10),
        ]);
    }

    public function revoked(): static
    {
        return $this->state(fn (): array => ['revoked_at' => now()->subDay()]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => ['expires_at' => now()->subDay()]);
    }
}
