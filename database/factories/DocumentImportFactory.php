<?php

namespace Database\Factories;

use App\Models\DocumentImport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DocumentImport>
 */
class DocumentImportFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'uuid' => (string) Str::uuid(),
            'excel_name' => 'impor-dokumen.xlsx',
            'zip_names' => ['dokumen.zip'],
            'status' => 'checked',
            'skip_past_reminders' => true,
            'rows' => [],
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes): array => ['status' => 'completed', 'completed_at' => now()]);
    }
}
