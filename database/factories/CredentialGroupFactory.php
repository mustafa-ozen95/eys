<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\GroupKind;
use App\Models\CredentialGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CredentialGroup> */
class CredentialGroupFactory extends Factory
{
    protected $model = CredentialGroup::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'kind' => GroupKind::Server,
            'description' => null,
            'position' => 0,
        ];
    }
}
