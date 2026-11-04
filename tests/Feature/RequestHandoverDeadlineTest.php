<?php

namespace Tests\Feature;

use App\Models\DocumentRequest;
use App\Models\DocumentRequestLateReason;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RequestHandoverDeadlineTest extends TestCase
{
    use RefreshDatabase;

    public function test_handover_due_date_is_seven_calendar_days_after_first_scan(): void
    {
        $this->travelTo('2026-11-04 10:00 WIB');
        $request = DocumentRequest::factory()->handover(0)->create();

        $this->assertSame('2026-11-11', $request->handoverDueDate()->toDateString());
    }

    public function test_handover_due_date_uses_jakarta_date(): void
    {
        $request = DocumentRequest::factory()->handover(0)->create();

        $request->signed_uploaded_at = '2026-11-04 16:30:00 UTC';
        $this->assertSame('2026-11-11', $request->handoverDueDate()->toDateString());

        $request->signed_uploaded_at = '2026-11-04 17:30:00 UTC';
        $this->assertSame('2026-11-12', $request->handoverDueDate()->toDateString());
    }

    public function test_due_date_is_null_without_scan(): void
    {
        $request = DocumentRequest::factory()->signing()->create();

        $this->assertNull($request->handoverDueDate());
        $this->assertNull($request->daysSinceSigned());
    }

    public function test_is_late_only_after_day_seven(): void
    {
        $this->travelTo('2026-11-04 10:00 WIB');
        $onDaySeven = DocumentRequest::factory()->handover(7)->create();
        $onDayEight = DocumentRequest::factory()->handover(8)->create();

        $this->assertFalse($onDaySeven->isHandoverLate());
        $this->assertSame(0, $onDaySeven->daysLate());
        $this->assertTrue($onDayEight->isHandoverLate());
        $this->assertSame(1, $onDayEight->daysLate());
    }

    public function test_scopes_split_waiting_requests(): void
    {
        $this->travelTo('2026-11-04 10:00 WIB');
        DocumentRequest::factory()->handover(3)->create();
        DocumentRequest::factory()->handover(9)->create();
        $withReason = DocumentRequest::factory()->handover(9)->create();
        DocumentRequestLateReason::factory()->create(['document_request_id' => $withReason->id]);
        DocumentRequest::factory()->signing(9)->create();
        DocumentRequest::factory()->cancelled()->create(['signed_uploaded_at' => now()->subDays(20), 'draft_issued_at' => now()->subDays(20)]);

        $this->assertSame(1, DocumentRequest::handoverOnTime()->count());
        $this->assertSame(2, DocumentRequest::handoverLate()->count());
        $this->assertSame(1, DocumentRequest::lateWithoutReason()->count());
        $this->assertSame(1, DocumentRequest::scanOverdue()->count());
    }
}
