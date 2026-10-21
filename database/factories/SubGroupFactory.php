<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CredentialGroup;
use App\Models\SubGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SubGroup> */
class SubGroupFactory extends Factory
{
    protected $model = SubGroup::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'credential_group_id' => CredentialGroup::factory(),
            'name' => fake()->unique()->words(2, true),
            'position' => 0,
        ];
    }
}
