<?php

namespace Tests\Feature;

use App\Models\DocumentRequest;
use App\Models\ReminderTemplate;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SigningFlowMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): Migration
    {
        return require database_path('migrations/2026_11_04_000002_migrate_request_statuses_to_signing_flow.php');
    }

    public function test_review_becomes_submitted_and_approved_becomes_signing(): void
    {
        $review = DocumentRequest::factory()->create(['status' => 'review']);
        $approved = DocumentRequest::factory()->create(['status' => 'approved']);
        $draft = DocumentRequest::factory()->create(['status' => 'draft']);

        $this->migration()->up();

        $this->assertSame('submitted', $review->fresh()->status);
        $this->assertSame('signing', $approved->fresh()->status);
        $this->assertNull($approved->fresh()->draft_path);
        $this->assertSame('draft', $draft->fresh()->status);

        $this->migration()->down();

        $this->assertSame('approved', $approved->fresh()->status);
    }

    public function test_request_templates_drop_review_and_approved_keys(): void
    {
        $template = ReminderTemplate::factory()->create();
        DB::table('reminder_templates')->where('id', $template->id)->update([
            'request_templates' => json_encode(['approved' => 'x', 'review' => 'z', 'submitted' => 'y']),
        ]);

        $this->migration()->up();

        $this->assertSame(['submitted' => 'y'], $template->fresh()->request_templates);
    }

    public function test_approvers_receive_escalation_and_view_all(): void
    {
        Permission::findOrCreate('Approve:DocumentRequest', 'web');
        $role = Role::findOrCreate('penyetuju_lama', 'web');
        $role->givePermissionTo('Approve:DocumentRequest');
        $approver = User::factory()->create();
        $approver->assignRole($role);
        $other = User::factory()->create();

        $this->migration()->up();

        $approver = $approver->fresh();
        $this->assertTrue($approver->can('ReceiveEscalation:DocumentRequest'));
        $this->assertTrue($approver->can('ViewAll:DocumentRequest'));
        $this->assertFalse($other->fresh()->can('ReceiveEscalation:DocumentRequest'));
        $this->assertFalse($other->fresh()->can('ViewAll:DocumentRequest'));
    }

    public function test_migration_works_without_the_approve_permission(): void
    {
        $this->migration()->up();

        $this->assertTrue(Permission::where('name', 'ReceiveEscalation:DocumentRequest')->exists());
    }
}
