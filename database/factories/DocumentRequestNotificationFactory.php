<?php

namespace Database\Factories;

use App\Models\DocumentRequest;
use App\Models\DocumentRequestNotification;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentRequestNotification>
 */
class DocumentRequestNotificationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'document_request_id' => DocumentRequest::factory(), 'event_key' => 'dummy-'.fake()->uuid(),
            'recipient_kind' => 'applicant', 'recipient_phone' => '6280000000000', 'status' => 'cancelled',
            'payload' => ['message' => ['type' => 'text', 'text' => 'Notifikasi dummy']],
        ];
    }
}
