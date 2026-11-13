<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentRequest;
use App\Models\DocumentType;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Aksi alur tanda tangan di model: keluarkan draf, unggah scan, alasan keterlambatan, fisik diterima, dan batal.
 */
class RequestSigningFlowTest extends TestCase
{
    use RefreshDatabase;

    private const LEGAL_PERMISSIONS = ['ViewAny:DocumentRequest', 'View:DocumentRequest', 'Review:DocumentRequest', 'Create:Document'];

    private const REQUESTER_PERMISSIONS = ['ViewAny:DocumentRequest', 'View:DocumentRequest', 'Create:DocumentRequest', 'Update:DocumentRequest'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 9)->setTime(8, 0));
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

    private function legal(string $name = 'Lina Legal'): User
    {
        return $this->user(self::LEGAL_PERMISSIONS, $name);
    }

    private function requester(): User
    {
        return $this->user(self::REQUESTER_PERMISSIONS, 'Rina Pemohon');
    }

    /** @param array<string, mixed> $attributes */
    private function request(User $requester, ?User $reviewer = null, array $attributes = []): DocumentRequest
    {
        return DocumentRequest::factory()->create([
            'requester_id' => $requester->id, 'reviewer_id' => $reviewer?->id, 'status' => 'submitted',
            'company_id' => Company::factory()->create(['name' => 'Abc'])->id,
            'document_type_id' => DocumentType::factory()->withoutExpiry()->create(['name' => 'Perjanjian Kerja Sama'])->id,
            'history' => [['status' => 'submitted', 'user' => $requester->name, 'user_id' => $requester->id, 'at' => now()->toIso8601String(), 'note' => null]],
            ...$attributes,
        ]);
    }

    private function pdf(string $name = 'dokumen.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n% {$name}\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");
    }

    /** Berkas nyata (bukan File palsu Laravel) agar jenisnya ditebak dari isi, bukan dari nama. */
    private function realFile(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'lms');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    /** @return list<string> */
    private function historyEvents(DocumentRequest $request): array
    {
        return collect($request->history)->map(fn (array $entry): string => $entry['event'] ?? $entry['status'])->values()->all();
    }

    public function test_issue_draft_moves_submitted_to_signing_and_stores_file(): void
    {
        $legal = $this->legal();
        $request = $this->request($this->requester(), $legal);

        $request->issueDraft($this->pdf('draf-kontrak.pdf'), $legal);

        $this->assertSame('signing', $request->status);
        $this->assertSame('draf-kontrak.pdf', $request->draft_name);
        $this->assertTrue($request->draft_issued_at->equalTo(now()));
        $this->assertStringStartsWith('lms/requests/perjanjian-kerja-sama/', $request->draft_path);
        $this->assertStringContainsString('_draft_pengajuan'.$request->id.'_', $request->draft_path);
        Storage::disk('local')->assertExists($request->draft_path);
        $this->assertSame('signing', collect($request->history)->last()['status']);
        $this->assertTrue($request->status_changed_at->equalTo(now()));
    }

    public function test_replacing_draft_keeps_first_issue_time(): void
    {
        $legal = $this->legal();
        $request = $this->request($this->requester(), $legal);
        $request->issueDraft($this->pdf('draf-1.pdf'), $legal);
        $firstPath = $request->draft_path;
        $issuedAt = $request->draft_issued_at;

        $this->travel(2)->days();
        $request->issueDraft($this->pdf('draf-2.pdf'), $legal);

        $this->assertSame('signing', $request->status);
        $this->assertSame('draf-2.pdf', $request->draft_name);
        $this->assertTrue($request->draft_issued_at->equalTo($issuedAt));
        $this->assertNotSame($firstPath, $request->draft_path);
        Storage::disk('local')->assertExists($request->draft_path);
        Storage::disk('local')->assertMissing($firstPath);
        $this->assertSame('draft_replaced', collect($request->history)->last()['event']);
        $this->assertTrue($request->status_changed_at->equalTo($issuedAt));
    }

    public function test_draft_cannot_be_replaced_after_scan(): void
    {
        $legal = $this->legal();
        $request = $this->request($this->requester(), $legal);
        $request->issueDraft($this->pdf('draf.pdf'), $legal);
        $request->uploadSigned($this->pdf('scan.pdf'), $legal);
        $draftPath = $request->draft_path;

        $this->assertFalse($legal->can('issueDraft', $request));
        try {
            $request->issueDraft($this->pdf('draf-baru.pdf'), $legal);
            $this->fail('Draf tidak boleh diganti setelah scan diunggah.');
        } catch (ValidationException) {
        }

        $request->refresh();
        $this->assertSame($draftPath, $request->draft_path);
        $this->assertSame('draf.pdf', $request->draft_name);
        $this->assertCount(2, Storage::disk('local')->allFiles());
    }

    public function test_upload_signed_starts_handover_once_and_replacing_keeps_clock(): void
    {
        $legal = $this->legal();
        $requester = $this->requester();
        $request = $this->request($requester, $legal);
        $request->issueDraft($this->pdf('draf.pdf'), $legal);
        $dayZero = now();

        $request->uploadSigned($this->pdf('scan-1.pdf'), $requester);
        $firstPath = $request->signed_path;
        $this->assertSame('handover', $request->status);
        $this->assertTrue($request->signed_uploaded_at->equalTo($dayZero));

        $this->travel(3)->days();
        $request->uploadSigned($this->pdf('scan-2.pdf'), null);

        $this->assertSame('handover', $request->status);
        $this->assertSame('scan-2.pdf', $request->signed_name);
        $this->assertTrue($request->signed_uploaded_at->equalTo($dayZero));
        $this->assertTrue($request->status_changed_at->equalTo($dayZero));
        Storage::disk('local')->assertMissing($firstPath);
        Storage::disk('local')->assertExists($request->signed_path);
        $this->assertStringContainsString('_ttd_pengajuan'.$request->id.'_', $request->signed_path);
        $this->assertContains('signed_replaced', $this->historyEvents($request));
        $replaced = collect($request->history)->last();
        $this->assertSame('signed_replaced', $replaced['event']);
        $this->assertSame('handover', $replaced['status']);
        $this->assertSame('Rina Pemohon', $replaced['user']);
    }

    public function test_any_legal_staff_can_act_not_only_assigned_reviewer(): void
    {
        $assigned = $this->legal('Ana Legal');
        $other = $this->legal('Budi Legal');
        $request = $this->request($this->requester(), $assigned);
        $request->issueDraft($this->pdf('draf.pdf'), $assigned);

        $this->assertTrue($other->can('issueDraft', $request));
        $this->assertTrue($other->can('uploadSigned', $request));
        $this->assertTrue($other->can('cancel', $request));
        $this->assertTrue($other->can('markReceived', $request));
        $this->assertTrue($other->can('view', $request));

        $request->issueDraft($this->pdf('draf-perbaikan.pdf'), $other);
        $request->uploadSigned($this->pdf('scan.pdf'), $other);
        $request->markReceived($other);
        $document = $request->completeWithScan($this->pdf('scan.pdf'), $other, null, null, '001/PKS/2026');

        $this->assertSame('archived', $request->status);
        $this->assertSame($document->id, $request->document_id);
    }

    public function test_super_admin_can_review_a_request_assigned_to_another_reviewer(): void
    {
        $assigned = $this->legal('Ana Legal');
        $other = $this->legal('Budi Legal');
        $superAdmin = $this->legal('Sari Super');
        $superAdmin->assignRole(Role::findOrCreate(config('filament-shield.super_admin.name'), 'web'));
        $request = $this->request($this->requester(), $assigned);

        $this->assertFalse($other->can('revise', $request));
        $this->assertFalse($other->can('issueDraft', $request));
        $this->assertTrue($superAdmin->can('revise', $request));
        $this->assertTrue($superAdmin->can('issueDraft', $request));

        $request->issueDraft($this->pdf('draf.pdf'), $superAdmin);

        $this->assertSame('signing', $request->status);
    }

    public function test_super_admin_still_cannot_review_their_own_request(): void
    {
        $superAdmin = $this->user([...self::LEGAL_PERMISSIONS, ...self::REQUESTER_PERMISSIONS], 'Sari Super');
        $superAdmin->assignRole(Role::findOrCreate(config('filament-shield.super_admin.name'), 'web'));
        $request = $this->request($superAdmin);

        $this->assertFalse($superAdmin->can('revise', $request));
        $this->assertFalse($superAdmin->can('issueDraft', $request));
    }

    public function test_late_reason_only_when_late_and_kept_as_history(): void
    {
        $requester = $this->requester();
        $request = $this->request($requester, null, ['status' => 'handover', 'signed_uploaded_at' => now()->subDays(5), 'draft_issued_at' => now()->subDays(6)]);

        $this->assertFalse($requester->can('addLateReason', $request));
        try {
            $request->addLateReason('Direktur sedang dinas.', $requester);
            $this->fail('Alasan belum boleh diisi sebelum terlambat.');
        } catch (ValidationException) {
        }
        $this->assertSame(0, $request->lateReasons()->count());

        $this->travel(4)->days();
        $request->refresh();
        $this->assertTrue($requester->can('addLateReason', $request));
        $first = $request->addLateReason('Direktur sedang dinas.', $requester);
        $request->addLateReason('Kurir terlambat mengambil berkas.', null);

        try {
            $request->addLateReason('', $requester);
            $this->fail('Alasan kosong harus ditolak.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reason', $exception->errors());
        }
        try {
            $request->addLateReason(str_repeat('a', 2001), $requester);
            $this->fail('Alasan lebih dari 2000 karakter harus ditolak.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reason', $exception->errors());
        }

        $this->assertSame(2, $request->lateReasons()->count());
        $this->assertSame($requester->id, $first->user_id);
        $this->assertSame(['Direktur sedang dinas.', 'Kurir terlambat mengambil berkas.'], $request->lateReasons()->pluck('reason')->all());
        $this->assertNull($request->lateReasons()->get()->last()->user_id);
        $this->assertSame('handover', $request->status);
        $this->assertTrue($request->signed_uploaded_at->equalTo(now()->subDays(9)));
        $notes = collect($request->history)->where('event', 'late_reason')->pluck('note')->all();
        $this->assertSame(['Direktur sedang dinas.', 'Kurir terlambat mengambil berkas.'], $notes);
    }

    public function test_start_review_moves_submitted_to_reviewing_and_every_review_step_can_follow(): void
    {
        $legal = $this->legal();
        $requester = $this->requester();
        $request = $this->request($requester, $legal);

        $this->assertFalse($requester->can('startReview', $request));
        $this->assertFalse($this->legal('Budi Legal')->can('startReview', $request));
        $request->transition('reviewing', $legal);

        $this->assertSame('reviewing', $request->status);
        $this->assertSame('reviewing', collect($request->history)->last()['status']);
        $this->assertSame('Tim Legal memeriksa pengajuan dokumen', $request->publicTimeline()[0]['label']);
        $this->assertFalse($legal->can('startReview', $request));
        $this->assertTrue($legal->can('startDrafting', $request));
        $this->assertTrue($legal->can('revise', $request));
        $this->assertTrue($legal->can('issueDraft', $request));
        $this->assertFalse($legal->can('cancel', $request));
        try {
            $request->transition('reviewing', $legal);
            $this->fail('Pengajuan yang sedang diperiksa tidak boleh diklik Periksa lagi.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }

        $request->transition('drafting', $legal);
        $this->assertSame('drafting', $request->status);
    }

    public function test_request_being_reviewed_can_be_sent_back_for_revision(): void
    {
        $legal = $this->legal();
        $request = $this->request($this->requester(), $legal);
        $request->transition('reviewing', $legal);
        $request->transition('revision', $legal, 'Lengkapi lampiran');

        $this->assertSame('revision', $request->status);
        $this->assertSame('Tim Legal meminta revisi pengajuan', $request->publicTimeline()[0]['label']);
    }

    public function test_draft_can_be_issued_straight_from_a_request_being_reviewed(): void
    {
        $legal = $this->legal();
        $request = $this->request($this->requester(), $legal);
        $request->transition('reviewing', $legal);
        $request->issueDraft($this->pdf('draft.pdf'), $legal);

        $this->assertSame('signing', $request->status);
        $this->assertNotNull($request->draft_issued_at);
        $this->assertSame('Tim Legal menyerahkan draft dokumen, menunggu tanda tangan pengaju', $request->publicTimeline()[0]['label']);
    }

    public function test_start_drafting_moves_submitted_to_drafting_and_draft_can_follow(): void
    {
        $legal = $this->legal();
        $requester = $this->requester();
        $request = $this->request($requester, $legal);

        $this->assertFalse($requester->can('startDrafting', $request));
        $this->assertFalse($this->legal('Budi Legal')->can('startDrafting', $request));
        $request->transition('drafting', $legal);

        $this->assertSame('drafting', $request->status);
        $this->assertSame('drafting', collect($request->history)->last()['status']);
        $this->assertTrue($legal->can('revise', $request));
        $this->assertTrue($legal->can('cancel', $request));
        $this->assertTrue($legal->can('issueDraft', $request));
        $this->assertStringContainsString('Tim legal sedang menyusun draft', $request->notifications()->sole()->payload['message']['text']);

        $request->issueDraft($this->pdf('draf.pdf'), $legal);
        $this->assertSame('signing', $request->status);
        $this->assertSame(['submitted', 'drafting', 'signing'], $this->historyEvents($request));
    }

    public function test_drafting_request_can_still_be_sent_back_for_revision(): void
    {
        $legal = $this->legal();
        $request = $this->request($this->requester(), $legal);
        $request->transition('drafting', $legal);

        $request->transition('revision', $legal, 'Lampiran kurang.');

        $this->assertSame('revision', $request->status);
    }

    public function test_mark_received_moves_to_scanning_without_archiving_or_messaging_applicant(): void
    {
        $legal = $this->legal();
        $request = $this->request($this->requester(), $legal);
        $request->issueDraft($this->pdf('draf.pdf'), $legal);
        $request->uploadSigned($this->pdf('soft-file-ttd.pdf'), $legal);
        $this->travel(10)->days();
        $messages = $request->notifications()->count();

        $request->markReceived($legal);

        $this->assertSame('scanning', $request->status);
        $this->assertTrue($request->physical_received_at->equalTo(now()));
        $this->assertNull($request->document_id);
        $this->assertSame(0, Document::count());
        $last = collect($request->history)->last();
        $this->assertSame('scanning', $last['status']);
        $this->assertSame('Diterima terlambat 3 hari', $last['note']);
        $this->assertSame($messages, $request->notifications()->count());
        $this->assertFalse($request->isHandoverLate());
        $this->assertFalse($legal->can('cancel', $request));
        $this->assertSame(['Scanning', DocumentRequest::BADGE_NO_PHYSICAL_SCAN], $request->statusBadges());
    }

    public function test_physical_can_be_received_before_the_applicant_uploads_a_soft_file(): void
    {
        $legal = $this->legal();
        $request = $this->request($this->requester(), $legal);
        $request->issueDraft($this->pdf('draf.pdf'), $legal);

        $request->markReceived($legal);

        $this->assertSame('scanning', $request->status);
        $this->assertNull($request->signed_path);
    }

    public function test_complete_with_scan_archives_the_legal_scan_and_notifies_applicant(): void
    {
        $legal = $this->legal();
        $request = $this->request($this->requester(), $legal);
        $request->issueDraft($this->pdf('draf.pdf'), $legal);
        $request->uploadSigned($this->pdf('soft-file-ttd.pdf'), $legal);
        $request->markReceived($legal);

        $this->assertFalse($legal->can('markReceived', $request));
        $this->assertTrue($legal->can('completeWithScan', $request));
        $document = $request->completeWithScan($this->pdf('scan-asli-cap.pdf'), $legal, null, null, '001/PKS/2026');

        $this->assertSame('archived', $request->status);
        $this->assertSame($document->id, $request->document_id);
        $this->assertSame('scan-asli-cap.pdf', $request->scan_name);
        $this->assertStringContainsString('_scan-fisik_pengajuan'.$request->id.'_', $request->scan_path);
        Storage::disk('local')->assertExists($request->scan_path);
        $version = $document->currentVersion;
        $this->assertSame('scan-asli-cap.pdf', $version->file_name);
        $this->assertSame('s3', $version->disk);
        $this->assertSame(Storage::disk('local')->get($request->scan_path), Storage::disk('s3')->get($version->file_path));
        $this->assertSame('001/PKS/2026', $document->document_number);
        Storage::disk('local')->assertExists($request->signed_path);
        $this->assertSame(['submitted', 'signing', 'handover', 'scanning', 'archived'], $this->historyEvents($request));
        $last = $request->notifications()->where('event_key', 'like', '%-'.count($request->history).'-applicant')->sole();
        $this->assertStringContainsString('scan dokumen bertanda tangan bisa diunduh', $last->payload['message']['text']);
    }

    public function test_complete_with_scan_is_only_for_scanning_stage(): void
    {
        $legal = $this->legal();
        $request = $this->request($this->requester(), $legal);
        $request->issueDraft($this->pdf('draf.pdf'), $legal);
        $request->uploadSigned($this->pdf('soft-file.pdf'), $legal);

        try {
            $request->completeWithScan($this->pdf('scan.pdf'), $legal);
            $this->fail('Scan baru bisa diunggah setelah fisik diterima.');
        } catch (ValidationException $exception) {
            $this->assertSame(['status' => ['Tahap pengajuan tidak sesuai.']], $exception->errors());
        }
        $this->assertSame('handover', $request->fresh()->status);
        $this->assertSame(0, Document::count());
    }

    public function test_scan_is_removed_when_archiving_fails(): void
    {
        $legal = $this->legal();
        $request = $this->request($this->requester(), $legal, ['company_id' => null, 'other_business_name' => 'PT Usaha Lain']);
        $request->issueDraft($this->pdf('draf.pdf'), $legal);
        $request->markReceived($legal);
        $before = Storage::disk('local')->allFiles();

        try {
            $request->completeWithScan($this->pdf('scan.pdf'), $legal);
            $this->fail('Tanpa badan usaha arsip, pengajuan tidak bisa diselesaikan.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('company_id', $exception->errors());
        }

        $request->refresh();
        $this->assertSame('scanning', $request->status);
        $this->assertNull($request->scan_path);
        $this->assertSame($before, Storage::disk('local')->allFiles());
        $this->assertSame(0, Document::count());

        $company = Company::factory()->create();
        $request->completeWithScan($this->pdf('scan.pdf'), $legal, null, $company->id);
        $this->assertSame('archived', $request->status);
        $this->assertSame($company->id, $request->company_id);
    }

    public function test_complete_with_scan_twice_creates_one_document(): void
    {
        $legal = $this->legal();
        $request = $this->request($this->requester(), $legal);
        $request->issueDraft($this->pdf('draf.pdf'), $legal);
        $request->markReceived($legal);
        $stale = DocumentRequest::find($request->id);

        $request->completeWithScan($this->pdf('scan.pdf'), $legal);
        try {
            $stale->completeWithScan($this->pdf('scan.pdf'), $legal);
            $this->fail('Menyelesaikan kedua kali harus ditolak.');
        } catch (ValidationException) {
        }

        $this->assertSame(1, Document::count());
        $this->assertCount(1, Storage::disk('s3')->allFiles());
    }

    public function test_cancel_requires_note_and_stops_flow(): void
    {
        $legal = $this->legal();
        $request = $this->request($this->requester(), $legal);
        $request->issueDraft($this->pdf('draf.pdf'), $legal);
        $request->uploadSigned($this->pdf('scan.pdf'), $legal);
        $this->travel(10)->days();
        $request->refresh();
        $this->assertTrue($request->isHandoverLate());

        try {
            $request->transition('cancelled', $legal);
            $this->fail('Pembatalan tanpa alasan harus ditolak.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('note', $exception->errors());
        }
        $this->assertSame('handover', $request->fresh()->status);

        $request->transition('cancelled', $legal, 'Kerja sama tidak jadi.');

        $this->assertSame('cancelled', $request->status);
        $last = collect($request->history)->last();
        $this->assertSame('cancelled', $last['status']);
        $this->assertSame('Kerja sama tidak jadi.', $last['note']);
        Storage::disk('local')->assertExists($request->draft_path);
        Storage::disk('local')->assertExists($request->signed_path);
        $this->assertFalse($legal->can('uploadSigned', $request));
        $this->assertFalse($legal->can('markReceived', $request));
        $this->assertFalse($legal->can('cancel', $request));
        $this->assertTrue($legal->can('view', $request));
        $this->expectException(ValidationException::class);
        $request->uploadSigned($this->pdf('scan-baru.pdf'), $legal);
    }

    public function test_invalid_draft_file_is_rejected_and_not_stored(): void
    {
        $legal = $this->legal();
        $request = $this->request($this->requester(), $legal);

        foreach ([$this->realFile('catatan.txt', 'teks biasa'), $this->realFile('palsu.pdf', 'ini bukan pdf')] as $file) {
            try {
                $request->issueDraft($file, $legal);
                $this->fail("Berkas {$file->getClientOriginalName()} harus ditolak.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('file', $exception->errors());
            }
        }

        $this->assertSame([], Storage::disk('local')->allFiles());
        $request->refresh();
        $this->assertSame('submitted', $request->status);
        $this->assertNull($request->draft_path);
        $this->assertNull($request->draft_issued_at);
    }

    public function test_requester_cannot_issue_draft_or_mark_received(): void
    {
        $legal = $this->legal();
        $requester = $this->requester();
        $request = $this->request($requester, $legal);

        $this->assertFalse($requester->can('issueDraft', $request));
        $this->assertFalse($requester->can('revise', $request));
        try {
            $request->issueDraft($this->pdf('draf.pdf'), $requester);
            $this->fail('Pemohon tidak boleh mengeluarkan draf.');
        } catch (AuthorizationException|ValidationException) {
        }
        $this->assertSame('submitted', $request->fresh()->status);

        $request->issueDraft($this->pdf('draf.pdf'), $legal);
        $this->assertFalse($requester->can('issueDraft', $request));
        try {
            $request->issueDraft($this->pdf('draf-saya.pdf'), $requester);
            $this->fail('Pemohon tidak boleh mengganti draf.');
        } catch (AuthorizationException) {
        }

        $this->assertTrue($requester->can('uploadSigned', $request));
        $request->uploadSigned($this->pdf('scan.pdf'), $requester);
        $this->assertFalse($requester->can('markReceived', $request));
        $this->assertFalse($requester->can('cancel', $request));
        try {
            $request->markReceived($requester);
            $this->fail('Pemohon tidak boleh menandai fisik diterima.');
        } catch (AuthorizationException) {
        }
        try {
            $request->transition('cancelled', $requester, 'Batal saja.');
            $this->fail('Pemohon tidak boleh membatalkan.');
        } catch (AuthorizationException) {
        }

        $this->assertSame('handover', $request->fresh()->status);
        $this->assertSame('draf.pdf', $request->fresh()->draft_name);
        $this->assertSame(0, Document::count());
    }
}
