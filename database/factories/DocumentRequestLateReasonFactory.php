<?php

namespace Database\Factories;

use App\Models\DocumentRequest;
use App\Models\DocumentRequestLateReason;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DocumentRequestLateReason> */
class DocumentRequestLateReasonFactory extends Factory
{
    public function definition(): array
    {
        return ['document_request_id' => DocumentRequest::factory(), 'reason' => fake()->sentence(), 'user_id' => null];
    }
}
