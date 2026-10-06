<?php

namespace Database\Factories;

use App\Models\DocumentVersion;
use App\Models\ReminderLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReminderLog>
 */
class ReminderLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'document_version_id' => DocumentVersion::factory(),
            'offset_days' => 30,
            'recipient_phone' => '6281234567890',
            'status' => 'sent',
            'attempts' => 1,
            'sent_at' => now(),
        ];
    }
}
