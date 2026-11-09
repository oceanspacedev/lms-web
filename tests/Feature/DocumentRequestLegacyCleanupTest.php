<?php

namespace Tests\Feature;

use App\Filament\Resources\DocumentRequests\DocumentRequestResource;
use App\Models\DocumentRequest;
use App\Models\DocumentType;
use App\Models\User;
use App\Policies\DocumentRequestPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class DocumentRequestLegacyCleanupTest extends TestCase
{
    use RefreshDatabase;

    public function test_statuses_no_longer_list_the_removed_approval_stage(): void
    {
        $this->assertSame(['draft', 'submitted', 'revision', 'signing', 'handover', 'rejected', 'archived', 'cancelled'], array_keys(DocumentRequest::STATUSES));
    }

    public function test_status_label_falls_back_for_legacy_and_unknown_statuses(): void
    {
        $this->assertSame('Menunggu Tanda Tangan', DocumentRequest::statusLabel('signing'));
        $this->assertSame('Menunggu Persetujuan', DocumentRequest::statusLabel('review'));
        $this->assertSame('Menunggu Tanda Tangan', DocumentRequest::statusLabel('approved'));
        $this->assertSame('tidak-dikenal', DocumentRequest::statusLabel('tidak-dikenal'));
    }

    public function test_legacy_history_still_maps_without_statuses_keys(): void
    {
        $request = DocumentRequest::factory()->make(['status' => 'cancelled', 'history' => [
            ['status' => 'submitted', 'at' => '2026-10-01T01:00:00Z'], ['status' => 'review', 'at' => '2026-10-02T01:00:00Z'],
            ['status' => 'approved', 'at' => '2026-10-03T01:00:00Z'], ['status' => 'cancelled', 'at' => '2026-10-04T01:00:00Z'],
        ]]);

        $this->assertSame(['done', 'stopped', 'pending', 'pending'], array_column($request->progressSteps(), 'state'));
        $this->assertSame('Dibatalkan', $request->publicTimeline()[0]['label']);
        $this->assertSame('Disetujui, menunggu tanda tangan', $request->publicTimeline()[1]['label']);
    }

    public function test_legal_visible_statuses_have_a_single_definition(): void
    {
        $this->assertSame(['signing', 'handover', 'archived', 'cancelled'], DocumentRequest::LEGAL_VISIBLE_STATUSES);
        $this->assertArrayNotHasKey('LEGAL_VISIBLE_STATUSES', (new \ReflectionClass(DocumentRequestResource::class))->getConstants());
        $this->assertArrayNotHasKey('LEGAL_VISIBLE_STATUSES', (new \ReflectionClass(DocumentRequestPolicy::class))->getConstants());
    }

    public function test_approve_permission_alone_no_longer_grants_view(): void
    {
        foreach (['View:DocumentRequest', 'Approve:DocumentRequest'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $approver = User::factory()->create();
        $approver->givePermissionTo(['View:DocumentRequest', 'Approve:DocumentRequest']);
        $request = DocumentRequest::factory()->create(['status' => 'signing', 'approver_id' => $approver->id]);
        $unassigned = DocumentRequest::factory()->create(['status' => 'signing', 'approver_id' => null]);

        $this->assertFalse($approver->can('view', $request));
        $this->assertFalse($approver->can('view', $unassigned));
    }

    public function test_request_pic_resolution_ignores_the_legacy_approver_column(): void
    {
        Permission::findOrCreate('Review:DocumentRequest', 'web');
        $reviewer = User::factory()->create();
        $reviewer->givePermissionTo('Review:DocumentRequest');
        $approver = User::factory()->create();
        $approver->givePermissionTo('Review:DocumentRequest');
        $type = DocumentType::factory()->create(['request_pic_id' => null, 'request_reviewer_id' => null, 'request_approver_id' => $approver->id]);

        $this->assertSame($reviewer->id, $type->resolveRequestPic()->id);
    }
}
