<?php

namespace Database\Factories;

use App\Models\ReminderTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReminderTemplate>
 */
class ReminderTemplateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(3, true), 'is_active' => true, 'body' => 'Pengingat {dokumen}, berakhir {tanggal_berakhir}, sisa {sisa_hari} hari.',
        ];
    }
}
