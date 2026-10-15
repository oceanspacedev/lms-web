<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentVersion>
 */
class DocumentVersionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'document_id' => Document::factory(),
            'version_number' => 1,
            'file_path' => 'lms/documents/'.fake()->uuid().'/v1/arsip.pdf',
            'file_name' => 'arsip.pdf',
            'file_size' => 64,
            'mime_type' => 'application/pdf',
            'issued_date' => now()->toDateString(),
            'expiry_date' => now()->addYear()->toDateString(),
            'is_current' => true,
            'uploaded_by' => User::factory(),
        ];
    }
}
