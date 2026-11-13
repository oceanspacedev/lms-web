<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\DocumentRequest;
use App\Models\DocumentType;
use App\Models\ReminderTemplate;
use App\Models\User;
use App\Services\DocumentRequestNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Notifikasi WhatsApp per tahap alur tanda tangan: draf, scan, selesai, dan batal.
 */
class RequestSigningNotificationTest extends TestCase
{
    use RefreshDatabase;

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
    private function user(array $permissions, string $name, ?string $phone = null, array $attributes = []): User
    {
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $user = User::factory()->create(['name' => $name, 'phone' => $phone, ...$attributes]);
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function legal(string $name, ?string $phone, array $attributes = []): User
    {
        return $this->user(['ViewAny:DocumentRequest', 'View:DocumentRequest', 'Review:DocumentRequest', 'Create:Document'], $name, $phone, $attributes);
    }

    private function request(?User $reviewer = null): DocumentRequest
    {
        $requester = $this->user(['ViewAny:DocumentRequest', 'View:DocumentRequest', 'Create:DocumentRequest', 'Update:DocumentRequest'], 'Rina Pemohon', '081200000001');

        return DocumentRequest::factory()->create([
            'requester_id' => $requester->id, 'reviewer_id' => $reviewer?->id, 'status' => 'submitted', 'public_token' => Str::random(64),
            'company_id' => Company::factory()->create()->id,
            'document_type_id' => DocumentType::factory()->withoutExpiry()->create()->id,
            'history' => [['status' => 'submitted', 'user' => $requester->name, 'user_id' => $requester->id, 'at' => now()->toIso8601String(), 'note' => null]],
        ]);
    }

    private function pdf(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n% {$name}\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");
    }

    /** @return Collection<int, string> */
    private function texts(DocumentRequest $request, string $kind): Collection
    {
        return $request->notifications()->where('recipient_kind', $kind)->orderBy('id')->get()->map(fn ($notification): string => $notification->payload['message']['text']);
    }

    public function test_issuing_draft_notifies_applicant_with_link(): void
    {
        $legal = $this->legal('Lina Legal', '081200000002');
        $request = $this->request($legal);

        $request->issueDraft($this->pdf('draf.pdf'), $legal);

        $notification = $request->notifications()->where('recipient_kind', 'applicant')->sole();
        $this->assertSame('6281200000001', $notification->recipient_phone);
        $text = $notification->payload['message']['text'];
        $this->assertStringContainsString('Draft dokumen sudah dikeluarkan', $text);
        $this->assertStringContainsString(route('requests.public.status', ['token' => $request->public_token]), $text);
        $this->assertSame(0, $request->notifications()->where('recipient_kind', '!=', 'applicant')->count());
    }

    public function test_replacing_draft_notifies_applicant_again(): void
    {
        $legal = $this->legal('Lina Legal', '081200000002');
        $request = $this->request($legal);
        $request->issueDraft($this->pdf('draf-1.pdf'), $legal);

        $request->issueDraft($this->pdf('draf-2.pdf'), $legal);

        $texts = $this->texts($request, 'applicant');
        $this->assertCount(2, $texts);
        $this->assertStringContainsString('Draft dokumen sudah dikeluarkan', $texts[0]);
        $this->assertStringContainsString('Draft dokumen diperbarui', $texts[1]);
        $this->assertSame(['cancelled', 'pending'], $request->notifications()->orderBy('id')->pluck('status')->all());
    }

    public function test_first_scan_notifies_legal_staff_and_applicant_only_once(): void
    {
        $legal = $this->legal('Lina Legal', '081200000002');
        $request = $this->request($legal);
        $request->issueDraft($this->pdf('draf.pdf'), $legal);

        $request->uploadSigned($this->pdf('scan-1.pdf'), null);

        $applicant = $this->texts($request, 'applicant');
        $this->assertCount(2, $applicant, 'Pemohon menerima pesan draft lalu konfirmasi scan.');
        $this->assertStringContainsString('Soft file bertanda tangan sudah diterima', $applicant[1]);
        $this->assertStringContainsString('paling lambat 16 Okt 2026', $applicant[1]);
        $staff = $request->notifications()->where('recipient_kind', 'legal')->get();
        $this->assertCount(1, $staff);
        $this->assertSame("lms-request-{$request->id}-3-u{$legal->id}", $staff->sole()->event_key);
        $this->assertSame('6281200000002', $staff->sole()->recipient_phone);
        $this->assertStringContainsString('Soft file bertanda tangan masuk', $staff->sole()->payload['message']['text']);
        $this->assertStringContainsString('16 Okt 2026', $staff->sole()->payload['message']['text']);
        $before = $request->notifications()->count();

        $request->uploadSigned($this->pdf('scan-2.pdf'), null);
        $this->travel(9)->days();
        $request->addLateReason('Kurir tertunda', null);

        $this->assertSame($before, $request->notifications()->count());
        $this->assertSame('pending', $staff->sole()->fresh()->status, 'Notifikasi legal yang antre tidak dibatalkan oleh ganti scan.');
        app(DocumentRequestNotifier::class)->run($request->id);
        $this->assertSame('accepted', $staff->sole()->fresh()->status);
    }

    public function test_legal_staff_falls_back_to_all_reviewers_with_phone(): void
    {
        $inactiveReviewer = $this->legal('Nonaktif', '081200000003', ['deactivated_at' => now()]);
        $first = $this->legal('Lina Legal', '081200000002');
        $second = $this->legal('Lani Legal', '+62 812-0000-0004');
        $this->legal('Tanpa Nomor', null);
        $this->legal('Nomor Salah', 'abc');
        $nonLegal = $this->user(['ViewAny:DocumentRequest'], 'Bukan Legal', '081200000005');
        $request = $this->request($inactiveReviewer);

        $this->assertSame([$first->id, $second->id], $request->legalStaffRecipients()->pluck('id')->all());

        $request->reviewer_id = $second->id;
        $request->load('reviewer');
        $this->assertSame([$second->id], $request->legalStaffRecipients()->pluck('id')->all());

        $request->reviewer_id = $nonLegal->id;
        $request->load('reviewer');
        $this->assertSame([$first->id, $second->id], $request->legalStaffRecipients()->pluck('id')->all());
    }

    public function test_coordinators_are_active_holders_of_escalation_permission_with_phone(): void
    {
        $request = $this->request();
        $this->assertTrue($request->coordinatorRecipients()->isEmpty(), 'Izin belum ada: koleksi kosong.');

        $coordinator = $this->user(['ReceiveEscalation:DocumentRequest'], 'Koordinator', '081200000006');
        $this->user(['ReceiveEscalation:DocumentRequest'], 'Tanpa Nomor', null);
        $this->user(['ReceiveEscalation:DocumentRequest'], 'Nonaktif', '081200000007', ['deactivated_at' => now()]);

        $this->assertSame([$coordinator->id], $request->coordinatorRecipients()->pluck('id')->all());
    }

    public function test_cancel_and_archive_notify_applicant_with_note(): void
    {
        $legal = $this->legal('Lina Legal', '081200000002');
        $cancelled = $this->request($legal);
        $cancelled->issueDraft($this->pdf('draf.pdf'), $legal);

        $cancelled->transition('cancelled', $legal, 'Kerja sama batal.');

        $text = $this->texts($cancelled, 'applicant')->last();
        $this->assertStringContainsString('Pengajuan dibatalkan.', $text);
        $this->assertStringContainsString("\nCatatan: Kerja sama batal.\n", $text);

        $archived = $this->request($legal);
        $archived->issueDraft($this->pdf('draf.pdf'), $legal);
        $archived->uploadSigned($this->pdf('scan.pdf'), null);
        $before = $this->texts($archived, 'applicant')->count();
        $archived->markReceived($legal);
        $this->assertCount($before, $this->texts($archived, 'applicant'), 'Fisik diterima tidak mengirim pesan ke pengaju.');
        $archived->completeWithScan($this->pdf('scan-asli.pdf'), $legal, null, null, 'PKS-001');

        $text = $this->texts($archived, 'applicant')->last();
        $this->assertStringContainsString('Dokumen fisik sudah discan tim legal', $text);
        $this->assertSame('archived', $archived->fresh()->status);
    }

    public function test_starting_review_notifies_only_the_applicant_with_the_reviewing_message(): void
    {
        $legal = $this->legal('Lina Legal', '081200000002');
        $request = $this->request($legal);

        $request->transition('reviewing', $legal);

        $this->assertSame(['Tim legal sedang memeriksa pengajuan.'], $this->texts($request, 'applicant')->map(fn (string $text): string => explode("\n", $text)[2])->all());
        $this->assertStringContainsString(route('requests.public.status', ['token' => $request->public_token]), $this->texts($request, 'applicant')->sole());
        $this->assertSame(0, $request->notifications()->where('recipient_kind', '!=', 'applicant')->count());
    }

    public function test_stored_old_default_submitted_message_is_updated_and_custom_text_is_kept(): void
    {
        $old = "Pengajuan #{nomor_pengajuan}\n{judul}\nPengajuan diterima dan sedang diperiksa.{baris_catatan}\n{tautan}";
        $setting = ReminderTemplate::factory()->create();
        $migration = require database_path('migrations/2026_11_13_215052_update_submitted_request_template_wording.php');

        ReminderTemplate::whereKey($setting->id)->toBase()->update(['request_templates' => json_encode(['submitted' => $old, 'revision' => 'Revisi khusus'])]);
        $migration->up();
        $migration->up();
        $this->assertSame(['submitted' => ReminderTemplate::DEFAULT_REQUEST_TEMPLATES['submitted'], 'revision' => 'Revisi khusus'], $setting->refresh()->request_templates);

        ReminderTemplate::whereKey($setting->id)->toBase()->update(['request_templates' => json_encode(['submitted' => 'Pesan khusus admin'])]);
        $migration->up();
        $this->assertSame(['submitted' => 'Pesan khusus admin'], $setting->refresh()->request_templates);
    }

    public function test_template_labels_and_variables_cover_new_stages(): void
    {
        $this->assertSame(
            ['pic', 'submitted', 'reviewing', 'revision', 'rejected', 'drafting', 'signing', 'draft_replaced', 'scan_reminder', 'handover', 'handover_staff', 'late_7', 'late_14', 'late_21', 'late_staff', 'archived', 'cancelled'],
            array_keys(ReminderTemplate::REQUEST_TEMPLATE_LABELS),
        );
        $this->assertSame(array_keys(ReminderTemplate::REQUEST_TEMPLATE_LABELS), array_keys(ReminderTemplate::DEFAULT_REQUEST_TEMPLATES));
        foreach (ReminderTemplate::DEFAULT_REQUEST_TEMPLATES as $event => $body) {
            $this->assertSame([], ReminderTemplate::unknownVariables($body, ReminderTemplate::REQUEST_EXAMPLE_VALUES), $event);
        }
        $this->assertArrayHasKey('batas_waktu', ReminderTemplate::REQUEST_VARIABLE_LABELS);
        $this->assertArrayHasKey('hari_terlambat', ReminderTemplate::REQUEST_EXAMPLE_VALUES);
    }
}
