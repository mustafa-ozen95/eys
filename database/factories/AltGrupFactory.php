<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AltGrup;
use App\Models\CredentialGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AltGrup> */
class AltGrupFactory extends Factory
{
    protected $model = AltGrup::class;

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
