<?php

namespace Tests\Feature;

use App\Filament\Resources\DocumentRequests\DocumentRequestResource;
use App\Filament\Resources\DocumentRequests\Pages\ListDocumentRequests;
use App\Filament\Widgets\WaitingRequestsOverview;
use App\Models\DocumentRequest;
use App\Models\DocumentRequestLateReason;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use ReflectionMethod;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class RequestMonitoringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 9)->setTime(8, 0));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /** @param list<string> $permissions */
    private function signInLegal(array $permissions = []): User
    {
        $permissions = ['ViewAny:DocumentRequest', 'View:DocumentRequest', 'Review:DocumentRequest', ...$permissions];
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);
        $this->actingAs($user);

        return $user;
    }

    /** @param array<string, mixed> $attributes */
    private function submitted(array $attributes = []): DocumentRequest
    {
        return DocumentRequest::factory()->create(['status' => 'submitted', 'reviewer_id' => null, ...$attributes]);
    }

    public function test_stage_filter_groups_requests(): void
    {
        $this->signInLegal();
        $toReview = $this->submitted();
        $signing = DocumentRequest::factory()->signing(1)->create();
        $onTime = DocumentRequest::factory()->handover(3)->create();
        $late = DocumentRequest::factory()->handover(9)->create();
        $archived = DocumentRequest::factory()->create(['status' => 'archived']);
        $cancelled = DocumentRequest::factory()->cancelled()->create();
        $all = [$toReview, $signing, $onTime, $late, $archived, $cancelled];

        $page = Livewire::test(ListDocumentRequests::class)->assertCanSeeTableRecords($all);
        foreach (['to_review' => $toReview, 'signing' => $signing, 'handover_on_time' => $onTime, 'late' => $late, 'archived' => $archived, 'cancelled' => $cancelled] as $stage => $expected) {
            $page->filterTable('stage', $stage)
                ->assertCanSeeTableRecords([$expected])
                ->assertCanNotSeeTableRecords(array_values(array_filter($all, fn (DocumentRequest $request): bool => $request->isNot($expected))));
        }
    }

    public function test_late_row_shows_days_and_latest_reason_or_missing_reason(): void
    {
        $this->signInLegal();
        $withoutReason = DocumentRequest::factory()->handover(9)->create();
        $withReasons = DocumentRequest::factory()->handover(10)->create();
        DocumentRequestLateReason::factory()->create(['document_request_id' => $withReasons->id, 'reason' => 'Kurir tertunda', 'created_at' => now()->subDays(2)]);
        DocumentRequestLateReason::factory()->create(['document_request_id' => $withReasons->id, 'reason' => 'Menunggu stempel basah', 'created_at' => now()->subDay()]);
        $onTime = DocumentRequest::factory()->handover(3)->create();
        $scanOverdue = DocumentRequest::factory()->signing(8)->create();
        $freshSigning = DocumentRequest::factory()->signing(2)->create();
        $archived = DocumentRequest::factory()->create(['status' => 'archived']);

        Livewire::test(ListDocumentRequests::class)
            ->assertTableColumnStateSet('handover_note', 'Terlambat 2 hari · belum ada alasan', $withoutReason)
            ->assertTableColumnStateSet('handover_note', 'Terlambat 3 hari · Menunggu stempel basah', $withReasons)
            ->assertTableColumnStateSet('handover_note', 'Sisa 4 hari', $onTime)
            ->assertTableColumnStateSet('handover_note', 'Belum unggah scan > 7 hari', $scanOverdue)
            ->assertTableColumnStateSet('handover_note', null, $freshSigning)
            ->assertTableColumnStateSet('handover_note', null, $archived);

        $table = Livewire::test(ListDocumentRequests::class)->instance()->getTable();
        $colorOf = fn (DocumentRequest $request): mixed => $table->getColumn('handover_note')->record($request)->getColor($table->getColumn('handover_note')->record($request)->getState());
        $this->assertSame('danger', $colorOf($withoutReason));
        $this->assertSame('danger', $colorOf($withReasons));
        $this->assertSame('warning', $colorOf($scanOverdue));
    }

    public function test_any_reviewer_sees_signing_and_handover_requests_assigned_to_someone_else(): void
    {
        $me = $this->signInLegal();
        $other = User::factory()->create();
        $signing = DocumentRequest::factory()->signing()->create(['reviewer_id' => $other->id]);
        $handover = DocumentRequest::factory()->handover(2)->create(['reviewer_id' => $other->id]);
        $archived = DocumentRequest::factory()->create(['status' => 'archived', 'reviewer_id' => $other->id]);
        $cancelled = DocumentRequest::factory()->cancelled()->create(['reviewer_id' => $other->id]);
        $checkingElsewhere = $this->submitted(['reviewer_id' => $other->id]);
        $revisionElsewhere = DocumentRequest::factory()->create(['status' => 'revision', 'reviewer_id' => $other->id]);
        $draft = DocumentRequest::factory()->create(['status' => 'draft', 'reviewer_id' => $other->id]);
        $mine = $this->submitted(['reviewer_id' => $me->id]);

        Livewire::test(ListDocumentRequests::class)
            ->assertCanSeeTableRecords([$signing, $handover, $archived, $cancelled, $mine])
            ->assertCanNotSeeTableRecords([$checkingElsewhere, $revisionElsewhere, $draft]);

        $visible = DocumentRequestResource::getEloquentQuery()->pluck('id')->all();
        foreach ([$signing, $handover, $archived, $cancelled, $checkingElsewhere, $revisionElsewhere, $draft, $mine] as $request) {
            $this->assertSame(in_array($request->id, $visible, true), $me->can('view', $request), "Kebijakan dan query harus sepakat untuk status {$request->status}");
        }
    }

    public function test_widget_counts_waiting_handover_and_late(): void
    {
        $this->signInLegal();
        $this->submitted();
        $this->submitted();
        DocumentRequest::factory()->handover(1)->create();
        DocumentRequest::factory()->handover(7)->create();
        DocumentRequest::factory()->handover(3)->create();
        $withoutReason = DocumentRequest::factory()->handover(9)->create();
        $withReason = DocumentRequest::factory()->handover(12)->create();
        DocumentRequestLateReason::factory()->create(['document_request_id' => $withReason->id]);
        DocumentRequest::factory()->cancelled()->create();
        DocumentRequest::factory()->signing(1)->create();

        $stats = (new ReflectionMethod(WaitingRequestsOverview::class, 'getStats'))->invoke(Livewire::test(WaitingRequestsOverview::class)->instance());

        $this->assertSame(['Menunggu Pemeriksaan', 'Menunggu Fisik', 'Terlambat'], array_map(fn ($stat): string => (string) $stat->getLabel(), $stats));
        $this->assertSame([2, 3, 2], array_map(fn ($stat): int => $stat->getValue(), $stats));
        $this->assertSame('1 belum ada alasan', (string) $stats[2]->getDescription());
        $this->assertStringContainsString('to_review', rawurldecode($stats[0]->getUrl()));
        $this->assertStringContainsString('handover_on_time', rawurldecode($stats[1]->getUrl()));
        $this->assertStringContainsString('late', rawurldecode($stats[2]->getUrl()));
        $this->assertStringContainsString('stage', rawurldecode($stats[2]->getUrl()));
        $this->get($stats[2]->getUrl())->assertOk();
    }

    public function test_widget_is_visible_when_only_handover_requests_exist(): void
    {
        $this->signInLegal();
        $this->assertFalse(WaitingRequestsOverview::canView());

        DocumentRequest::factory()->handover(9)->create();

        $this->assertTrue(WaitingRequestsOverview::canView());
    }
}
