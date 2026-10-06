<?php

namespace Database\Factories;

use App\Models\Extension;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Extension>
 */
class ExtensionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'number' => (string) fake()->unique()->numberBetween(1000, 9999),
            'user_id' => User::factory()->operator(),
            'label' => null,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
