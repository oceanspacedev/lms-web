<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'document_type_id' => DocumentType::factory(),
            'pic_user_id' => User::factory(),
            'title' => fake()->sentence(3),
            'document_number' => fake()->unique()->numerify('DOC-####'),
            'counterparty' => fake()->company(),
            'notes' => null,
        ];
    }
}
