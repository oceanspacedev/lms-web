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

    /** Menunggu scan tanda tangan; draf diterbitkan $draftDaysAgo hari lalu. */
    public function signing(?int $draftDaysAgo = 0): static
    {
        return $this->state(fn () => ['status' => 'signing', 'draft_path' => 'lms/requests/draft.pdf', 'draft_name' => 'draft.pdf', 'draft_issued_at' => now()->subDays($draftDaysAgo ?? 0)]);
    }

    /** Menunggu dokumen fisik; scan pertama diunggah $signedDaysAgo hari lalu. */
    public function handover(int $signedDaysAgo = 0): static
    {
        return $this->signing($signedDaysAgo)->state(fn () => ['status' => 'handover', 'signed_path' => 'lms/requests/signed.pdf', 'signed_name' => 'signed.pdf', 'signed_uploaded_at' => now()->subDays($signedDaysAgo)]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => ['status' => 'cancelled']);
    }
}
