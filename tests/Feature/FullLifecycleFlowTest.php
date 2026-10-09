<?php

namespace Tests\Feature;

use App\Filament\Resources\DocumentRequests\Pages\EditDocumentRequest;
use App\Filament\Resources\Documents\Pages\ListDocuments;
use App\Filament\Resources\Documents\Pages\ViewDocument;
use App\Filament\Resources\ReminderTemplates\Pages\WhatsAppDeliveryHistory;
use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentRequest;
use App\Models\DocumentRequestNotification;
use App\Models\DocumentType;
use App\Models\ReminderLog;
use App\Models\ReminderTemplate;
use App\Models\User;
use App\Services\DocumentSpreadsheetExporter;
use Database\Seeders\DummyAccountSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;

/**
 * Satu cerita lengkap: pengaju publik mengajukan, staf memeriksa dan menyetujui, dokumen final diarsipkan,
 * lalu diingatkan, diperpanjang, diekspor, dan pesan gagal dipulihkan. Memakai akun dummy per role.
 */
class FullLifecycleFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $reviewer;

    private User $approver;

    private User $legal;

    private User $admin;

    private Company $company;

    private string $token = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 9)->setTime(8, 0));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Storage::fake('local');
        Storage::fake('s3');
        // Waktu dimajukan berhari-hari; tanpa ini Livewire menghapus unggahan sementara yang dianggap lebih dari 24 jam.
        config(['livewire.temporary_file_upload.cleanup' => false]);
        config(['services.waghub.url' => 'https://waghub.test', 'services.waghub.token' => 'test-token', 'services.waghub.purpose' => 'test-purpose']);
        Http::preventStrayRequests();
        Http::fake(['waghub.test/*' => fn () => Http::response(['status' => 'accepted'], (int) config('testing.waghub_status', 202))]);

        $this->seed(DummyAccountSeeder::class);
        $this->reviewer = $this->dummy('dummy-pemeriksa', '081200000001');
        $this->approver = $this->dummy('dummy-penyetuju', '081200000002');
        $this->legal = $this->dummy('dummy-legal', '081200000003');
        $this->admin = $this->dummy('super-admin', '081200000004');
        $this->company = Company::factory()->create(['name' => 'PT Alur Lengkap', 'is_active' => true]);
        ReminderTemplate::factory()->create([
            'schedule_mode' => 'specific_days', 'scheduled_days' => [30, 7], 'overdue_days' => [1], 'body' => ReminderTemplate::DEFAULT_BODY,
        ]);
    }

    /** Isi PDF minimal agar tipe berkas terdeteksi dari isinya, seperti berkas sungguhan. */
    private function pdfContent(): string
    {
        return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [] /Count 0 >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
    }

    private function dummy(string $slug, string $phone): User
    {
        $user = User::where('email', $slug.'@'.DummyAccountSeeder::EMAIL_DOMAIN)->firstOrFail();
        $user->update(['phone' => $phone]);

        return $user;
    }

    /** @return list<array<string, mixed>> Isi permintaan WagHub yang terkirim, urut. */
    private function sentMessages(): array
    {
        return Http::recorded()->map(fn (array $pair): array => [
            'to' => $pair[0]['recipient']['value'], 'text' => $pair[0]['message']['text'], 'key' => $pair[0]->header('Idempotency-Key')[0] ?? null,
        ])->values()->all();
    }

    private function messagesTo(string $phone): array
    {
        return array_values(array_filter($this->sentMessages(), fn (array $message): bool => $message['to'] === $phone));
    }

    private function deliver(): void
    {
        $this->artisan('lms:send-request-notifications')->assertSuccessful();
    }

    public function test_request_to_archive_to_reminder_to_renewal(): void
    {
        $type = DocumentType::factory()->create([
            'name' => 'Perjanjian Kerja Sama', 'has_expiry' => true,
            'request_pic_id' => $this->reviewer->id, 'request_reviewer_id' => $this->reviewer->id, 'request_approver_id' => $this->approver->id,
        ]);

        // 1. Pengaju membuka form publik dan mengirim pengajuan.
        $this->get(route('requests.public.create'))->assertOk()->assertSee('Perjanjian Kerja Sama')->assertSee('PT Alur Lengkap');
        $response = $this->post(route('requests.public.store'), [
            'requester_name' => 'Budi Pengaju', 'requester_phone' => '081355550000', 'requester_division' => 'Operasional',
            'request_reason' => 'Kerja sama distribusi', 'title' => 'Kerja Sama Distribusi 2026',
            'company_id' => $this->company->id, 'document_type_id' => $type->id,
            'attachments' => ['supporting-document' => UploadedFile::fake()->create('pendukung.pdf', 50, 'application/pdf')],
        ]);
        $request = DocumentRequest::sole();
        $response->assertRedirect(route('requests.public.status', ['token' => $request->public_token]));
        $this->token = $request->public_token;
        $this->assertSame('submitted', $request->status);
        $this->assertSame($this->reviewer->id, $request->pic_user_id);
        $this->assertSame($this->approver->id, $request->approver_id);

        // 2. Pengaju dan pemeriksa sama-sama diberi tahu; halaman status memperlihatkan tahap dan riwayat.
        $this->deliver();
        $this->assertCount(1, $this->messagesTo('6281355550000'));
        $this->assertCount(1, $this->messagesTo('6281200000001'));
        $this->assertStringContainsString('Kerja Sama Distribusi 2026', $this->messagesTo('6281355550000')[0]['text']);
        $this->get(route('requests.public.status', ['token' => $this->token]))->assertOk()
            ->assertSee('Pengajuan diterima')->assertSee('aria-current="step"', false)->assertDontSee($this->reviewer->name);

        // 3. Tidak ada yang bertindak selama 2 hari: pemeriksa diingatkan, tanpa duplikat saat command diulang.
        $this->travelTo(now()->addDays(2));
        $this->artisan('lms:send-request-reminders')->expectsOutputToContain('1 pengingat pengajuan')->assertSuccessful();
        $this->artisan('lms:send-request-reminders')->expectsOutputToContain('0 pengingat pengajuan')->assertSuccessful();
        $this->deliver();
        $reminder = collect($this->messagesTo('6281200000001'))->last();
        $this->assertStringContainsString('Menunggu 2 hari', $reminder['text']);
        $this->assertStringContainsString('Tahap: Diperiksa', $reminder['text']);

        // 4. Pemeriksa meminta revisi; pengaju diberi tahu beserta catatannya.
        $this->actingAs($this->reviewer);
        Livewire::test(EditDocumentRequest::class, ['record' => $request->getRouteKey()])
            ->callAction('revise', ['note' => 'Mohon lengkapi lampiran NIB.'])->assertHasNoActionErrors();
        $this->assertSame('revision', $request->fresh()->status);
        $this->deliver();
        $this->assertStringContainsString('Mohon lengkapi lampiran NIB.', collect($this->messagesTo('6281355550000'))->last()['text']);
        $this->get(route('requests.public.status', ['token' => $this->token]))->assertOk()->assertSee('Revisi ke-1')->assertSee('Mohon lengkapi lampiran NIB.')->assertSee('Kirim revisi');

        // 5. Pengaju mengirim revisi lewat tautannya; pemeriksa diberi tahu lagi.
        $this->post(route('requests.public.revise', ['token' => $this->token]), [
            'requester_name' => 'Budi Pengaju', 'requester_phone' => '081355550000', 'requester_division' => 'Operasional',
            'request_reason' => 'Kerja sama distribusi (revisi)', 'title' => 'Kerja Sama Distribusi 2026',
        ])->assertRedirect();
        $this->assertSame('submitted', $request->fresh()->status);
        $this->deliver();
        $this->assertCount(2, array_filter($this->messagesTo('6281200000001'), fn (array $m): bool => str_contains($m['text'], 'Pengajuan baru')));

        // 6. Pemeriksa meneruskan, penyetuju menyetujui. Pengaju mengikuti tiap tahap.
        Livewire::test(EditDocumentRequest::class, ['record' => $request->getRouteKey()])->callAction('review')->assertHasNoActionErrors();
        $this->assertSame('review', $request->fresh()->status);
        $this->actingAs($this->approver);
        Livewire::test(EditDocumentRequest::class, ['record' => $request->getRouteKey()])->callAction('approve')->assertHasNoActionErrors();
        $this->assertSame('approved', $request->fresh()->status);
        $this->deliver();
        $this->assertStringContainsString('disetujui', strtolower(collect($this->messagesTo('6281355550000'))->last()['text']));

        // 7. Dokumen final yang sudah ditandatangani diunggah dan diarsipkan.
        Livewire::test(EditDocumentRequest::class, ['record' => $request->getRouteKey()])->callAction('archive', [
            'number' => 'PKS/2026/001', 'issued_date' => '2026-10-09', 'expiry_date' => '2026-10-29',
            'file' => UploadedFile::fake()->createWithContent('final-ttd.pdf', $this->pdfContent()),
        ])->assertHasNoActionErrors();
        $request->refresh();
        $this->assertSame('archived', $request->status);
        $document = Document::sole();
        $this->assertSame($document->id, $request->document_id);
        $this->assertSame('PKS/2026/001', $document->document_number);
        $this->assertSame('PT Alur Lengkap', $document->company->name);
        $this->assertSame('PT Alur Lengkap', $document->counterparty);
        $this->assertSame($this->reviewer->id, $document->pic_user_id);
        $this->assertSame(1, $document->currentVersion->version_number);
        Storage::disk('s3')->assertExists($document->currentVersion->file_path);
        $this->deliver();
        $this->get(route('requests.public.status', ['token' => $this->token]))->assertOk()->assertSee('Selesai, dokumen final diarsipkan')->assertDontSee('aria-current="step"', false);

        // 8. Dokumen sampai ke tim legal dan masuk daftar.
        $this->actingAs($this->legal);
        Livewire::test(ListDocuments::class)->assertCanSeeTableRecords([$document])
            ->searchTable('PKS/2026/001')->assertCanSeeTableRecords([$document]);
        $this->assertSame('expiring', $document->fresh()->expiry_status);

        // 9. Sisa 18 hari: jadwal H-30 terlewat dan dikirim terlambat ke PIC, sekali saja.
        $this->artisan('lms:send-reminders')->assertSuccessful();
        $this->artisan('lms:send-reminders')->assertSuccessful();
        $expiryMessages = array_values(array_filter($this->messagesTo('6281200000001'), fn (array $m): bool => str_contains($m['text'], 'Pengingat masa berlaku dokumen')));
        $this->assertCount(1, $expiryMessages);
        $this->assertStringContainsString('Sisa waktu: 18 hari', $expiryMessages[0]['text']);
        $this->assertStringContainsString('Kerja Sama Distribusi 2026', $expiryMessages[0]['text']);

        // 10. Sisa 7 hari: jadwal H-7 terkirim tepat waktu.
        $this->travelTo(now()->setDate(2026, 10, 22)->setTime(8, 0));
        $this->artisan('lms:send-reminders')->assertSuccessful();
        $expiryMessages = array_values(array_filter($this->messagesTo('6281200000001'), fn (array $m): bool => str_contains($m['text'], 'Pengingat masa berlaku dokumen')));
        $this->assertCount(2, $expiryMessages);
        $this->assertStringContainsString('Sisa waktu: 7 hari', $expiryMessages[1]['text']);
        $this->assertSame([30, 7], ReminderLog::orderByDesc('offset_days')->pluck('offset_days')->all());

        // 11. Tim legal memperpanjang: form terisi dari versi aktif, versi lama menjadi arsip.
        $this->actingAs($this->legal);
        Livewire::test(ViewDocument::class, ['record' => $document->getRouteKey()])->mountAction('renew')
            ->assertActionDataSet(['issued_date' => '2026-10-29', 'expiry_date' => '2026-11-18', 'change_note' => 'Perpanjangan masa berlaku dokumen.'])
            ->setActionData(['file' => UploadedFile::fake()->createWithContent('final-ttd-v2.pdf', $this->pdfContent())])
            ->callMountedAction()->assertHasNoActionErrors();
        $document->refresh();
        $this->assertSame(2, $document->currentVersion->version_number);
        $this->assertSame('2026-11-18', $document->currentVersion->expiry_date->toDateString());
        $this->assertSame([false, true], $document->versions()->orderBy('version_number')->pluck('is_current')->all());
        $this->assertSame(2, $document->versions()->count());

        // 12. Pengingat untuk versi baru dihitung ulang dari awal; versi lama tidak diingatkan lagi.
        $this->travelTo(now()->setDate(2026, 10, 23)->setTime(8, 0));
        $before = count($this->sentMessages());
        $this->artisan('lms:send-reminders')->assertSuccessful();
        $newVersionLogs = ReminderLog::where('document_version_id', $document->current_version_id)->get();
        $this->assertSame([30], $newVersionLogs->pluck('offset_days')->all());
        $this->assertGreaterThan($before, count($this->sentMessages()));
        $this->assertStringContainsString('Sisa waktu: 26 hari', collect($this->sentMessages())->last()['text']);

        // 13. Ekspor Excel memuat dokumen dengan versi aktif terbaru.
        $path = tempnam(sys_get_temp_dir(), 'lms-flow-');
        app(DocumentSpreadsheetExporter::class)->write(Document::query(), $path);
        $reader = new Reader;
        $reader->open($path);
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
            break;
        }
        $reader->close();
        @unlink($path);
        $this->assertCount(2, $rows);
        $this->assertSame(['Kerja Sama Distribusi 2026', 'PKS/2026/001', 'PT Alur Lengkap'], array_slice($rows[1], 0, 3));
        $this->assertSame('v2', $rows[1][10]);
        $this->assertSame(26, $rows[1][9]);
    }

    public function test_failed_message_is_recovered_from_the_history_page_without_duplicates(): void
    {
        $type = DocumentType::factory()->create([
            'has_expiry' => true, 'request_pic_id' => $this->reviewer->id, 'request_reviewer_id' => $this->reviewer->id, 'request_approver_id' => $this->approver->id,
        ]);
        config(['testing.waghub_status' => 503]);
        $this->post(route('requests.public.store'), [
            'requester_name' => 'Citra', 'requester_phone' => '081366660000', 'requester_division' => 'Keuangan', 'request_reason' => 'Uji pemulihan',
            'title' => 'Pengajuan Uji Pemulihan', 'company_id' => $this->company->id, 'document_type_id' => $type->id,
            'attachments' => ['supporting-document' => UploadedFile::fake()->create('pendukung.pdf', 10, 'application/pdf')],
        ])->assertRedirect();
        $request = DocumentRequest::sole();
        $this->deliver();
        $notification = DocumentRequestNotification::where('document_request_id', $request->id)->where('recipient_kind', 'applicant')->sole();
        $this->assertSame('failed', $notification->status);
        $sentBefore = count($this->sentMessages());

        config(['testing.waghub_status' => 202]);
        $this->actingAs($this->admin);
        Livewire::test(WhatsAppDeliveryHistory::class)->assertSee('Kirim ulang')
            ->call('resend', 'notification', $notification->id)->assertSee('Dikirim ulang oleh '.$this->admin->name);

        $this->assertSame('accepted', $notification->fresh()->status);
        $resent = collect($this->sentMessages())->slice($sentBefore)->firstWhere('to', '6281366660000');
        $this->assertSame($notification->event_key, $resent['key']);
        $this->deliver();
        $this->assertSame('accepted', $notification->fresh()->status);
        $this->assertCount(2, array_filter($this->messagesTo('6281366660000'), fn (array $m): bool => $m['key'] === $notification->event_key));
    }

    public function test_rejection_ends_the_flow_and_stops_reminders(): void
    {
        $type = DocumentType::factory()->create([
            'has_expiry' => true, 'request_pic_id' => $this->reviewer->id, 'request_reviewer_id' => $this->reviewer->id, 'request_approver_id' => $this->approver->id,
        ]);
        $this->post(route('requests.public.store'), [
            'requester_name' => 'Dewi', 'requester_phone' => '081377770000', 'requester_division' => 'HR', 'request_reason' => 'Uji penolakan',
            'title' => 'Pengajuan Ditolak', 'company_id' => $this->company->id, 'document_type_id' => $type->id,
            'attachments' => ['supporting-document' => UploadedFile::fake()->create('pendukung.pdf', 10, 'application/pdf')],
        ])->assertRedirect();
        $request = DocumentRequest::sole();
        $this->deliver();

        $this->actingAs($this->reviewer);
        Livewire::test(EditDocumentRequest::class, ['record' => $request->getRouteKey()])->callAction('reject', ['note' => 'Tidak memenuhi syarat.'])->assertHasNoActionErrors();
        $this->deliver();
        $this->assertStringContainsString('Tidak memenuhi syarat.', collect($this->messagesTo('6281377770000'))->last()['text']);
        $this->get(route('requests.public.status', ['token' => $request->public_token]))->assertOk()
            ->assertSee('Alasan penolakan')->assertSee('Tidak memenuhi syarat.')->assertSee('dihentikan');

        $this->travelTo(now()->addDays(10));
        $this->artisan('lms:send-request-reminders')->expectsOutputToContain('0 pengingat pengajuan')->assertSuccessful();
        $this->assertSame(0, Document::count());
    }
}
