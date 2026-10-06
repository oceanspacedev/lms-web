<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\DocumentActivity;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DocumentActivity> */
class DocumentActivityFactory extends Factory
{
    public function definition(): array
    {
        return ['document_id' => Document::factory(), 'user_id' => User::factory(), 'event' => 'metadata_updated'];
    }
}
