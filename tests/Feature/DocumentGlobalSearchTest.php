<?php

namespace Tests\Feature;

use App\Filament\Resources\Documents\DocumentResource;
use App\Models\Company;
use App\Models\Document;
use App\Models\User;
use Database\Seeders\DocumentSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DocumentGlobalSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->seed(DocumentSeeder::class);
    }

    public static function searchTerms(): array
    {
        return [['Kemitraan'], ['PKS-2026-99'], ['OceanSpace']];
    }

    #[DataProvider('searchTerms')]
    public function test_global_search_finds_title_number_and_company_and_links_to_details(string $term): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['ViewAny:Document', 'View:Document']);
        $this->actingAs($user);
        $document = Document::factory()->create(['title' => 'Perjanjian Kemitraan', 'document_number' => 'PKS-2026-99', 'company_id' => Company::factory()->create(['name' => 'OceanSpace'])->id]);
        $archived = Document::factory()->create(['title' => 'Arsip Kemitraan', 'document_number' => 'PKS-2026-99', 'company_id' => $document->company_id]);
        $archived->delete();
        $results = DocumentResource::getGlobalSearchResults($term);
        $this->assertCount(1, $results);
        $this->assertSame('Perjanjian Kemitraan', $results->first()->title);
        $this->assertSame(DocumentResource::getUrl('view', ['record' => $document]), $results->first()->url);
        $this->assertSame('OceanSpace', $results->first()->details['Perusahaan']);
    }

    public function test_search_is_unavailable_without_resource_permission_and_hides_unviewable_results(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->assertFalse(DocumentResource::canGloballySearch());
        $user->givePermissionTo('ViewAny:Document');
        Document::factory()->create(['title' => 'Rahasia']);
        $this->assertTrue(DocumentResource::canGloballySearch());
        $this->assertCount(0, DocumentResource::getGlobalSearchResults('Rahasia'));
    }
}
