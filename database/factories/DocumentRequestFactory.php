<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\DocumentRequest;
use App\Models\DocumentType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DocumentRequest> */
class DocumentRequestFactory extends Factory
{
    public function definition(): array
    {
        return ['company_id' => Company::factory(), 'document_type_id' => DocumentType::factory(), 'requester_id' => User::factory(), 'pic_user_id' => User::factory(), 'title' => fake()->sentence(3), 'partner_name' => fake()->company(), 'purpose' => 'new', 'has_cost' => false];
    }
}
