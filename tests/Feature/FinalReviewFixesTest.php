<?php

namespace Tests\Feature;

use App\Filament\Resources\DocumentRequests\Pages\EditDocumentRequest;
use App\Filament\Resources\DocumentRequests\Pages\ListDocumentRequests;
use App\Models\Company;
use App\Models\DocumentRequest;
use App\Models\DocumentType;
use App\Models\User;
use App\Services\UserDeactivator;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Perbaikan temuan review akhir cabang alur tanda tangan dan dokumen fisik.
 */
class FinalReviewFixesTest extends TestCase
{
    use RefreshDatabase;

    private const LEGAL_PERMISSIONS = ['ViewAny:DocumentRequest', 'View:DocumentRequest', 'Review:DocumentRequest', 'Create:Document'];

    private const REQUESTER_PERMISSIONS = ['ViewAny:DocumentRequest', 'View:DocumentRequest', 'Create:DocumentRequest', 'Update:DocumentRequest'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 12)->setTime(8, 0));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Storage::fake('local');
        Storage::fake('s3');
        config(['services.waghub.url' => 'https://waghub.test', 'services.waghub.token' => 'test-token', 'services.waghub.purpose' => 'test-purpose']);
        Http::preventStrayRequests();
        Http::fake(['waghub.test/*' => Http::response([], 202)]);
    }

    /** @param list<string> $permissions */
    private function user(array $permissions, string $name): User
    {
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $user = User::factory()->create(['name' => $name]);
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function legal(): User
    {
        return $this->user(self::LEGAL_PERMISSIONS, 'Lina Legal');
    }

    private function requester(): User
    {
        return $this->user(self::REQUESTER_PERMISSIONS, 'Rina Pemohon');
    }

    /** @param array<string, mixed> $attributes */
    private function handover(User $requester, User $legal, int $signedDaysAgo, array $attributes = []): DocumentRequest
    {
        return DocumentRequest::factory()->handover($signedDaysAgo)->create([
            'requester_id' => $requester->id, 'reviewer_id' => $legal->id,
            'company_id' => Company::factory()->create()->id,
            'document_type_id' => DocumentType::factory()->withoutExpiry()->create()->id,
            'history' => [['status' => 'submitted', 'user' => $requester->name, 'user_id' => $requester->id, 'at' => now()->toIso8601String(), 'note' => null]],
            ...$attributes,
        ]);
    }

    private function pdf(string $name = 'berkas.pdf'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 20, 'application/pdf');
    }

    /** @return array<string, mixed> */
    private function entry(string $status, string $at, ?int $userId = null, ?string $event = null, ?string $note = null): array
    {
        return [...($event ? ['event' => $event] : []), 'status' => $status, 'user' => 'Staf', 'user_id' => $userId, 'at' => $at, 'note' => $note];
    }

    public function test_staff_history_labels_non_status_events(): void
    {
        $legal = $this->legal();
        $request = $this->handover($this->requester(), $legal, 5, ['history' => [
            $this->entry('submitted', '2026-11-01T01:00:00Z'),
            $this->entry('signing', '2026-11-02T01:00:00Z'),
            $this->entry('signing', '2026-11-03T01:00:00Z', event: 'draft_replaced'),
            $this->entry('handover', '2026-11-04T01:00:00Z'),
            $this->entry('handover', '2026-11-05T01:00:00Z', event: 'signed_replaced'),
            $this->entry('handover', '2026-11-12T01:00:00Z', event: 'late_reason', note: 'Kurir tertahan'),
        ]]);

        $this->actingAs($legal);
        $html = Livewire::test(EditDocumentRequest::class, ['record' => $request->getRouteKey()])->html();

        $this->assertStringContainsString('Draf dokumen diganti', $html);
        $this->assertStringContainsString('Scan diganti', $html);
        $this->assertStringContainsString('Alasan keterlambatan dari pengaju', $html);
        $this->assertSame(1, substr_count($html, 'Draf dokumen diterbitkan, menunggu tanda tangan'));
        $this->assertSame(1, substr_count($html, 'Scan diunggah, menunggu dokumen fisik'));
    }

    public function test_received_on_day_seven_is_not_late(): void
    {
        $legal = $this->legal();
        $request = $this->handover($this->requester(), $legal, 7);
        Storage::disk('local')->put($request->signed_path, '%PDF-1.4 scan');

        $request->markReceived('PKS/007', null, null, $legal);

        $request->refresh();
        $this->assertSame('archived', $request->status);
        $this->assertNull(collect($request->history)->last()['note']);
        $this->actingAs($legal);
        Livewire::test(EditDocumentRequest::class, ['record' => $request->getRouteKey()])->assertDontSee('terlambat');
    }

    public function test_received_late_records_days_late_in_history_and_section(): void
    {
        $legal = $this->legal();
        $request = $this->handover($this->requester(), $legal, 10);
        Storage::disk('local')->put($request->signed_path, '%PDF-1.4 scan');

        $request->markReceived('PKS/010', null, null, $legal);

        $request->refresh();
        $this->assertSame('archived', $request->status);
        $this->assertSame('Diterima terlambat 3 hari', collect($request->history)->last()['note']);
        $this->actingAs($legal);
        Livewire::test(EditDocumentRequest::class, ['record' => $request->getRouteKey()])
            ->assertSee('12 Nov 2026 · terlambat 3 hari')
            ->assertSee('Diterima terlambat 3 hari');
    }

    public function test_received_from_signing_without_scan_has_no_lateness(): void
    {
        $legal = $this->legal();
        $request = DocumentRequest::factory()->signing(30)->create([
            'requester_id' => $this->requester()->id, 'reviewer_id' => $legal->id, 'company_id' => Company::factory()->create()->id,
            'document_type_id' => DocumentType::factory()->withoutExpiry()->create()->id,
        ]);

        $request->markReceived('PKS/011', null, null, $legal, null, $this->pdf('final.pdf'));

        $this->assertNull(collect($request->fresh()->history)->last()['note']);
    }

    public function test_cancelled_requests_do_not_block_deactivation_or_get_reassigned(): void
    {
        $admin = $this->user(['ViewAny:User', 'View:User', 'Update:User'], 'Admin');
        $leaver = $this->user(['Review:DocumentRequest'], 'Staf Keluar');
        $cancelled = DocumentRequest::factory()->cancelled()->create(['reviewer_id' => $leaver->id, 'pic_user_id' => $leaver->id]);

        $this->assertNull(app(UserDeactivator::class)->missingCandidatesMessage($leaver));
        $this->assertSame([], app(UserDeactivator::class)->requiredGroups($leaver));
        app(UserDeactivator::class)->deactivate($leaver, $admin, []);

        $this->assertFalse($leaver->fresh()->isActive());
        $this->assertSame([$leaver->id, $leaver->id], [$cancelled->fresh()->reviewer_id, $cancelled->fresh()->pic_user_id]);
    }

    public function test_applicant_actionable_tab_includes_own_signing_and_late_handover(): void
    {
        $legal = $this->legal();
        $me = $this->requester();
        $this->actingAs($me);
        $signing = DocumentRequest::factory()->signing(1)->create(['requester_id' => $me->id, 'reviewer_id' => $legal->id]);
        $late = $this->handover($me, $legal, 10);
        $onTime = $this->handover($me, $legal, 2);
        $others = DocumentRequest::factory()->signing(1)->create(['requester_id' => $legal->id, 'reviewer_id' => $legal->id]);

        Livewire::test(ListDocumentRequests::class)->set('activeTab', 'actionable')
            ->assertCanSeeTableRecords([$signing, $late])->assertCanNotSeeTableRecords([$onTime, $others]);
    }

    public function test_first_draft_on_a_migrated_signing_request_is_a_first_issue(): void
    {
        $legal = $this->legal();
        $request = DocumentRequest::factory()->create([
            'status' => 'signing', 'requester_id' => $this->requester()->id, 'reviewer_id' => $legal->id,
            'company_id' => Company::factory()->create()->id, 'document_type_id' => DocumentType::factory()->withoutExpiry()->create()->id,
            'draft_path' => null, 'draft_name' => null, 'draft_issued_at' => null,
            'history' => [$this->entry('signing', '2026-11-01T01:00:00Z')],
        ]);

        $request->issueDraft($this->pdf('draft.pdf'), $legal);

        $request->refresh();
        $this->assertNotNull($request->draft_issued_at);
        $this->assertNotNull($request->draft_path);
        $last = collect($request->history)->last();
        $this->assertSame('signing', $last['status']);
        $this->assertArrayNotHasKey('event', $last);

        $request->issueDraft($this->pdf('draft2.pdf'), $legal);
        $this->assertSame('draft_replaced', collect($request->fresh()->history)->last()['event']);
    }

    public function test_public_timeline_attributes_scans_to_applicant_only_when_uploaded_by_them(): void
    {
        $request = DocumentRequest::factory()->make(['status' => 'handover', 'requester_id' => 7, 'history' => [
            $this->entry('handover', '2026-11-01T01:00:00Z', 7),
            $this->entry('handover', '2026-11-02T01:00:00Z', 7, 'signed_replaced'),
            $this->entry('handover', '2026-11-03T01:00:00Z', 99, 'signed_replaced'),
            $this->entry('handover', '2026-11-04T01:00:00Z', null, 'signed_replaced'),
        ]]);
        $this->assertSame(['Pengaju', 'Tim legal', 'Pengaju', 'Pengaju'], array_column($request->publicTimeline(), 'actor'));

        $legalUpload = DocumentRequest::factory()->make(['status' => 'handover', 'requester_id' => 7, 'history' => [$this->entry('handover', '2026-11-01T01:00:00Z', 99)]]);
        $this->assertSame('Tim legal', $legalUpload->publicTimeline()[0]['actor']);
        $publicUpload = DocumentRequest::factory()->make(['status' => 'handover', 'requester_id' => null, 'history' => [$this->entry('handover', '2026-11-01T01:00:00Z', null)]]);
        $this->assertSame('Pengaju', $publicUpload->publicTimeline()[0]['actor']);
    }

    public function test_late_requester_sees_mandatory_reason_notice_and_others_do_not(): void
    {
        $legal = $this->legal();
        $requester = $this->requester();
        $late = $this->handover($requester, $legal, 10);
        $onTime = $this->handover($requester, $legal, 2);

        $this->actingAs($requester);
        Livewire::test(EditDocumentRequest::class, ['record' => $late->getRouteKey()])
            ->assertSee('Batas penyerahan dokumen fisik sudah lewat')->assertSee('Tulis Alasan Keterlambatan');
        Livewire::test(EditDocumentRequest::class, ['record' => $onTime->getRouteKey()])->assertDontSee('Batas penyerahan dokumen fisik sudah lewat');

        $this->actingAs($legal);
        Livewire::test(EditDocumentRequest::class, ['record' => $late->getRouteKey()])->assertDontSee('Batas penyerahan dokumen fisik sudah lewat');
    }

    public function test_unused_approve_permission_is_labelled_as_unused(): void
    {
        $this->assertSame('Setujui pengajuan (tidak dipakai)', config('filament-shield.custom_permissions')['Approve:DocumentRequest']);
    }

    public function test_status_badge_colours_make_cancelled_look_final(): void
    {
        $this->actingAs($this->legal());
        $column = Livewire::test(ListDocumentRequests::class)->instance()->getTable()->getColumn('status');

        $this->assertSame('danger', $column->getColor('cancelled'));
        $this->assertSame($column->getColor('rejected'), $column->getColor('cancelled'));
        $this->assertSame('info', $column->getColor('signing'));
        $this->assertSame('info', $column->getColor('handover'));
    }
}
