<?php

namespace Tests\Feature;

use App\Models\DocumentRequest;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PartnerNameMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): Migration
    {
        return require database_path('migrations/2026_10_13_073000_make_partner_name_nullable_on_document_requests.php');
    }

    public function test_partner_name_accepts_null_after_migration(): void
    {
        $request = DocumentRequest::factory()->create(['partner_name' => null]);

        $this->assertNull($request->fresh()->partner_name);
    }

    public function test_existing_rows_survive_the_migration_in_both_directions(): void
    {
        $withPartner = DocumentRequest::factory()->create(['partner_name' => 'PT Mitra Lama', 'status' => 'approved', 'details' => ['a' => 'b']]);
        $withoutPartner = DocumentRequest::factory()->create(['partner_name' => null]);

        $this->migration()->down();

        $this->assertSame('PT Mitra Lama', $withPartner->fresh()->partner_name);
        $this->assertSame('', $withoutPartner->fresh()->partner_name);
        $this->assertSame(1, collect(DB::select("pragma table_info('document_requests')"))->firstWhere('name', 'partner_name')->notnull);

        $this->migration()->up();

        $reloaded = $withPartner->fresh();
        $this->assertSame('PT Mitra Lama', $reloaded->partner_name);
        $this->assertSame('approved', $reloaded->status);
        $this->assertSame(['a' => 'b'], $reloaded->details);
        $this->assertSame(2, DocumentRequest::count());
        $this->assertSame(0, collect(DB::select("pragma table_info('document_requests')"))->firstWhere('name', 'partner_name')->notnull);
        $this->assertNull(DocumentRequest::factory()->create(['partner_name' => null])->fresh()->partner_name);
    }
}
