<?php

namespace Database\Factories;

use App\Models\DocumentType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentType>
 */
class DocumentTypeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(3, true),
            'has_expiry' => true,
            'reminder_days' => [90, 60, 30],
            'is_active' => true,
        ];
    }

    public function withoutExpiry(): static
    {
        return $this->state(fn (array $attributes): array => [
            'has_expiry' => false,
            'reminder_days' => [],
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => ['is_active' => false]);
    }
}
