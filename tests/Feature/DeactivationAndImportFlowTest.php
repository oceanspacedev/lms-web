<?php

namespace Tests\Feature;

use App\Filament\Auth\WhatsAppLogin;
use App\Filament\Resources\DocumentRequests\Pages\EditDocumentRequest;
use App\Filament\Resources\Documents\Pages\CreateDocument;
use App\Filament\Resources\Documents\Pages\ImportDocuments;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentActivity;
use App\Models\DocumentImport;
use App\Models\DocumentRequest;
use App\Models\DocumentType;
use App\Models\DocumentVersion;
use App\Models\ReminderLog;
use App\Models\ReminderTemplate;
use App\Models\User;
use App\Services\DocumentImporter;
use App\Services\DocumentImportTemplate;
use App\Services\UserDeactivator;
use Database\Seeders\DummyAccountSeeder;
use DateTimeInterface;
use Filament\Actions\Testing\TestAction;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use ZipArchive;

/**
 * Alur ujung ke ujung Work 52 (nonaktifkan user + alih tugas) dan Work 53 (impor dokumen lama), lewat halaman dan
 * aksi Filament yang sama dengan yang dipakai di browser, command terjadwal, dan WagHub palsu. Memakai akun dummy per role.
 */
class DeactivationAndImportFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $reviewer;

    private User $approver;

    private User $legal;

    private User $admin;

    private User $viewer;

    private Company $company;

    /** @var list<string> */
    private array $tempFiles = [];

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 9)->setTime(8, 0));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Storage::fake('local');
        Storage::fake('s3');
        // Waktu dimajukan berhari-hari; tanpa ini Livewire menghapus unggahan sementara yang dianggap lebih dari 24 jam.
        config(['livewire.temporary_file_upload.cleanup' => false]);
        config(['services.waghub.url' => 'https://waghub.test', 'services.waghub.token' => 'test-token', 'services.waghub.purpose' => 'test-purpose', 'services.waghub.otp_purpose' => 'test-otp']);
        Http::preventStrayRequests();
        Http::fake(['waghub.test/*' => Http::response(['status' => 'accepted'], 202)]);

        $this->seed(DummyAccountSeeder::class);
        $this->reviewer = $this->dummy('dummy-pemeriksa', '6281200000001');
        $this->approver = $this->dummy('dummy-penyetuju', '6281200000002');
        $this->legal = $this->dummy('dummy-legal', '6281200000003');
        $this->admin = $this->dummy('super-admin', '6281200000004');
        $this->viewer = $this->dummy('dummy-viewer', '6281200000005');
        $this->company = Company::factory()->create(['name' => 'Alur Impor', 'legal_form' => 'PT', 'is_active' => true]);
        DocumentType::factory()->create(['name' => 'Perjanjian Sewa', 'has_expiry' => true]);
        DocumentType::factory()->withoutExpiry()->create(['name' => 'Akta Pendirian']);
        ReminderTemplate::factory()->create([
            'schedule_mode' => 'specific_days', 'scheduled_days' => [30, 7], 'overdue_days' => [1, 7], 'body' => ReminderTemplate::DEFAULT_BODY,
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    private function dummy(string $slug, string $phone): User
    {
        $user = User::where('email', $slug.'@'.DummyAccountSeeder::EMAIL_DOMAIN)->firstOrFail();
        $user->update(['phone' => $phone]);

        return $user;
    }

    private function tempPath(): string
    {
        return $this->tempFiles[] = tempnam(sys_get_temp_dir(), 'lms-flow-import-');
    }

    /**
     * Satu baris sheet "Dokumen" dengan nilai bawaan yang sah.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function row(array $overrides = []): array
    {
        $this->sequence++;

        return [
            'title' => "Arsip Sewa {$this->sequence}",
            'document_number' => "ARS-{$this->sequence}",
            'company' => 'PT Alur Impor',
            'document_type' => 'Perjanjian Sewa',
            'counterparty' => 'CV Mitra',
            'pic_email' => $this->reviewer->email,
            'issued_date' => '01/01/2026',
            'expiry_date' => '31/12/2027',
            'notes' => null,
            'file_name' => "arsip-{$this->sequence}.pdf",
            ...$overrides,
        ];
    }

    /** @param list<array<string, mixed>> $rows */
    private function xlsx(array $rows): string
    {
        $path = $this->tempPath();
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('Dokumen');
        $writer->addRow(Row::fromValues(DocumentImportTemplate::headers()));
        $dateStyle = (new Style)->setFormat('dd/mm/yyyy');
        foreach ($rows as $row) {
            $writer->addRow(new Row(array_map(fn (string $key): Cell => ($row[$key] ?? null) instanceof DateTimeInterface
                ? Cell::fromValue($row[$key], $dateStyle) : Cell::fromValue($row[$key] ?? ''), array_keys(DocumentImporter::COLUMNS))));
        }
        $writer->close();

        return $path;
    }

    /**
     * ZIP berisi file PDF untuk baris yang diberikan, disimpan di folder $folder di dalam ZIP.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function zipFor(array $rows, string $folder = ''): string
    {
        $path = $this->tempPath();
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($rows as $row) {
            $zip->addFromString($folder.$row['file_name'], $this->pdf($row['file_name']));
        }
        $zip->close();

        return $path;
    }

    private function pdf(string $name): string
    {
        return "%PDF-1.4\n% {$name}\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";
    }

    /** @return array<string, list<list<mixed>>> */
    private function readWorkbook(Testable $component): array
    {
        $path = $this->tempPath();
        file_put_contents($path, base64_decode(data_get($component->effects, 'download.content')));
        $reader = new Reader;
        $reader->open($path);
        $sheets = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            $rows = [];
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
            $sheets[$sheet->getName()] = $rows;
        }
        $reader->close();

        return $sheets;
    }

    /**
     * Unggah Excel + ZIP di halaman impor lalu jalankan pemeriksaan (dry run), seperti tombol "Periksa file".
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, string>  $zips  Nama ZIP => path file ZIP.
     */
    private function checkThroughPage(array $rows, array $zips): Testable
    {
        return Livewire::test(ImportDocuments::class)
            ->fillForm([
                'excel' => UploadedFile::fake()->createWithContent('arsip-lama.xlsx', file_get_contents($this->xlsx($rows))),
                'zips' => array_values(array_map(fn (string $path, string $name): UploadedFile => UploadedFile::fake()->createWithContent($name, file_get_contents($path)), $zips, array_keys($zips))),
            ])
            ->call('check')
            ->assertHasNoFormErrors();
    }

    /** @return array<string, mixed> */
    private function rowAt(DocumentImport $import, int $line): array
    {
        return collect($import->rows)->firstWhere('line', $line);
    }

    /** @return list<array{to: string, text: string}> Isi pesan WagHub yang terkirim, urut. */
    private function sentMessages(): array
    {
        return Http::recorded()->map(fn (array $pair): array => [
            'to' => $pair[0]['recipient']['value'], 'text' => $pair[0]['message']['text'],
        ])->values()->all();
    }

    /** @return list<array{to: string, text: string}> */
    private function messagesTo(string $phone, ?string $containing = null): array
    {
        return array_values(array_filter($this->sentMessages(), fn (array $message): bool => $message['to'] === $phone
            && ($containing === null || str_contains($message['text'], $containing))));
    }

    private function deliver(): void
    {
        $this->artisan('lms:send-request-notifications')->assertSuccessful();
    }

    private function logOut(): void
    {
        if (auth()->check()) {
            auth()->logout();
        }
        $this->app['auth']->forgetGuards();
        $this->assertGuest();
    }

    /** @param array<string, mixed> $overrides */
    private function submitPublicRequest(DocumentType $type, string $title, array $overrides = []): DocumentRequest
    {
        $this->post(route('requests.public.store'), [
            'requester_name' => 'Budi Pengaju', 'requester_phone' => '081355550000', 'requester_division' => 'Operasional',
            'request_reason' => 'Kerja sama vendor', 'title' => $title, 'partner_name' => 'PT Mitra Vendor',
            'company_id' => $this->company->id, 'document_type_id' => $type->id,
            'attachments' => ['supporting-document' => UploadedFile::fake()->create('pendukung.pdf', 20, 'application/pdf')],
            ...$overrides,
        ])->assertRedirect();

        return DocumentRequest::where('title', $title)->sole();
    }

    public function test_legacy_import_from_template_to_reminders_report_and_safe_reimport(): void
    {
        $this->actingAs($this->legal);
        User::factory()->deactivated()->create(['name' => 'Mantan Staf', 'email' => 'keluar@contoh.test']);
        Company::factory()->inactive()->create(['name' => 'Sudah Tutup', 'legal_form' => 'CV']);
        $existing = Document::archive([
            'company_id' => $this->company->id, 'document_type_id' => DocumentType::where('name', 'Perjanjian Sewa')->value('id'), 'pic_user_id' => $this->reviewer->id,
            'title' => 'Sewa Gudang Lama', 'document_number' => 'SEWA-LAMA', 'issued_date' => '2025-01-01', 'expiry_date' => '2028-01-01',
        ], UploadedFile::fake()->create('lama.pdf', 1, 'application/pdf'), $this->legal);
        $storedBefore = count(Storage::disk('s3')->allFiles());

        // 1. Template memuat master data aktif saja.
        $template = Livewire::test(ImportDocuments::class)->assertSee('Unduh template')->assertSee('Periksa file')
            ->call('downloadTemplate')->assertFileDownloaded('template-impor-dokumen.xlsx');
        $sheets = $this->readWorkbook($template);
        $this->assertSame(DocumentImportTemplate::headers(), $sheets['Dokumen'][0]);
        $guide = collect($sheets['Petunjuk'])->map(fn (array $row): string => implode(' | ', $row));
        $this->assertTrue($guide->contains('PT Alur Impor'));
        $this->assertTrue($guide->contains('Perjanjian Sewa | Ada, Tanggal Berakhir wajib'));
        $this->assertTrue($guide->contains('Akta Pendirian | Tanpa masa berlaku'));
        foreach ([$this->reviewer, $this->approver, $this->legal, $this->viewer] as $user) {
            $this->assertTrue($guide->contains($user->email.' | '.$user->name), $user->email);
        }
        $this->assertFalse($guide->contains(fn (string $line): bool => str_contains($line, 'keluar@contoh.test') || str_contains($line, 'Sudah Tutup')));

        // 2. Excel berisi campuran baris; file dokumen tersebar di dua ZIP.
        $mixed = [
            $this->row(['title' => 'Sewa Kantor Pusat', 'document_number' => 'SK-001', 'counterparty' => 'PT Pemilik Gedung', 'notes' => 'Lemari 3', 'file_name' => 'Kontrak Kantor.pdf']),
            $this->row(['title' => 'Sewa Gudang Timur', 'document_number' => 'SK-002', 'expiry_date' => '05/10/2026', 'file_name' => 'gudang-timur.pdf']),
            $this->row(['title' => 'Sewa Ruko', 'document_number' => 'SK-003', 'expiry_date' => '20/10/2026', 'file_name' => 'ruko.pdf']),
            $this->row(['title' => 'Akta Pendirian Cabang', 'document_number' => null, 'document_type' => 'Akta Pendirian', 'expiry_date' => '01/01/2030', 'file_name' => 'akta.pdf']),
            $this->row(['title' => 'Sewa Parkir', 'document_number' => 'SK-005', 'file_name' => 'hilang.pdf']),
            $this->row(['title' => 'Sewa Lahan', 'document_number' => 'SK-006', 'pic_email' => 'keluar@contoh.test', 'file_name' => 'lahan.pdf']),
            $this->row(['title' => 'Sewa Kios', 'document_number' => 'SK-007', 'company' => 'PT Tidak Terdaftar', 'file_name' => 'kios.pdf']),
            $this->row(['title' => 'Sewa Gudang Lama (salinan)', 'document_number' => 'sewa-lama', 'file_name' => 'lama.pdf']),
            $this->row(['title' => 'Sewa Kantor Pusat (dobel)', 'document_number' => 'SK-001', 'file_name' => 'kantor-dobel.pdf']),
        ];
        $fillers = array_map(fn (): array => $this->row(), range(1, 21));
        $rows = [...$mixed, ...$fillers];
        $zips = [
            'dokumen-1.zip' => $this->zipFor(array_values(array_filter($mixed, fn (array $row): bool => $row['file_name'] !== 'hilang.pdf'))),
            'dokumen-2.zip' => $this->zipFor($fillers, 'arsip/2019/'),
        ];

        // 3. Pemeriksaan (dry run): status dan pesan per baris, peringatan WhatsApp, belum ada yang tersimpan.
        $page = $this->checkThroughPage($rows, $zips)
            ->assertSee('Hasil pemeriksaan')->assertSee('Impor 25 dokumen')
            ->assertSee('2 dokumen akan langsung dikirimi pengingat, 1 sudah kedaluwarsa.')
            ->assertSee('Lewati pengingat yang jadwalnya sudah lewat')
            ->assertSee('PIC "keluar@contoh.test" sudah nonaktif. Pilih user aktif.')
            ->assertSet('skipPastReminders', true);
        $import = DocumentImport::sole();
        $page->assertSet('importId', $import->id);
        $this->assertSame(['checked', 'arsip-lama.xlsx', ['dokumen-1.zip', 'dokumen-2.zip']], [$import->status, $import->excel_name, $import->zip_names]);
        $this->assertSame([30, 25, 2, 3], [$import->total_rows, $import->countRows('ready'), $import->skipped_count, $import->failed_count]);
        $expected = [
            2 => ['ready', null],
            3 => ['ready', null],
            4 => ['ready', null],
            5 => ['ready', 'Tanggal Berakhir diabaikan karena jenis dokumen "Akta Pendirian" tidak memiliki masa berlaku.'],
            6 => ['error', 'File "hilang.pdf" tidak ditemukan di ZIP.'],
            7 => ['error', 'PIC "keluar@contoh.test" sudah nonaktif. Pilih user aktif.'],
            8 => ['error', 'Badan Usaha "PT Tidak Terdaftar" tidak ditemukan.'],
            9 => ['skipped', "Sudah ada di arsip: \"Sewa Gudang Lama\" (ID {$existing->id})."],
            10 => ['skipped', 'Sama dengan baris 2 di file ini.'],
        ];
        foreach ($expected as $line => [$status, $message]) {
            $row = $this->rowAt($import, $line);
            $this->assertSame($status, $row['status'], "Baris {$line}");
            $message === null
                ? $this->assertSame([], $row['messages'], "Baris {$line}")
                : $this->assertStringContainsString($message, implode(' ', $row['messages']), "Baris {$line}");
        }
        $this->assertSame(array_fill(0, 21, 'ready'), collect($import->rows)->where('line', '>=', 11)->pluck('status')->all());
        $this->assertSame([1, 1, 0], [Document::count(), DocumentVersion::count(), ReminderLog::count()]);
        $this->assertCount($storedBefore, Storage::disk('s3')->allFiles());
        Http::assertNothingSent();

        // 4. Impor bertahap 20 baris per langkah, seperti browser; koneksi putus setelah langkah pertama.
        $page->call('startImport')->assertReturned(true)->call('processNextChunk')->assertReturned(true);
        $import->refresh();
        $this->assertSame(['importing', 20, 5, 21], [$import->status, $import->processedCount(), $import->countRows('ready'), Document::count()]);

        // 5. Halaman dibuka lagi: impor yang belum selesai ditawarkan, lalu dilanjutkan lewat ?batch= tanpa duplikat.
        Livewire::test(ImportDocuments::class)->assertSee('Ada impor yang belum selesai')->assertSee("Buka batch #{$import->id}");
        $report = Livewire::withQueryParams(['batch' => $import->id])->test(ImportDocuments::class)
            ->assertSee('Lanjutkan impor')->assertSee('20 dari 25 dokumen diproses')
            ->call('processNextChunk')->assertReturned(false)
            ->assertNotified('Impor selesai')
            ->assertSee('Laporan impor')->assertSee('Unduh baris gagal (Excel)');
        $import->refresh();
        $this->assertSame(['completed', 25, 2, 3], [$import->status, $import->imported_count, $import->skipped_count, $import->failed_count]);
        $this->assertNotNull($import->completed_at);
        Storage::disk('local')->assertMissing($import->directory());

        // 6. Hasil impor: dokumen, versi pertama, file, aktivitas, dan catatan perubahan per batch.
        $this->assertSame(26, Document::count());
        $importedIds = collect($import->rows)->where('status', 'imported')->pluck('document_id');
        $this->assertCount(25, $importedIds->unique());
        $this->assertFalse(Document::whereIn('title', ['Sewa Gudang Lama (salinan)', 'Sewa Kantor Pusat (dobel)', 'Sewa Parkir', 'Sewa Lahan', 'Sewa Kios'])->exists());
        $office = Document::where('document_number', 'SK-001')->sole();
        $this->assertSame($office->id, $this->rowAt($import, 2)['document_id']);
        $this->assertSame(['Sewa Kantor Pusat', 'PT Pemilik Gedung', 'Lemari 3', $this->company->id, $this->reviewer->id],
            [$office->title, $office->counterparty, $office->notes, $office->company_id, $office->pic_user_id]);
        $version = $office->currentVersion;
        $this->assertSame([1, "Diimpor dari arsip lama (batch #{$import->id})", 'Kontrak Kantor.pdf', $this->legal->id],
            [$version->version_number, $version->change_note, $version->file_name, $version->uploaded_by]);
        $this->assertSame(['2026-01-01', '2027-12-31'], [$version->issued_date->toDateString(), $version->expiry_date->toDateString()]);
        $this->assertSame($this->pdf('Kontrak Kantor.pdf'), Storage::disk('s3')->get($version->file_path));
        $this->assertNull(Document::where('title', 'Akta Pendirian Cabang')->sole()->currentVersion->expiry_date);
        $this->assertSame(25, DocumentVersion::whereIn('document_id', $importedIds)->where('version_number', 1)->where('change_note', $import->changeNote())->count());
        $this->assertSame(25, DocumentActivity::whereIn('document_id', $importedIds)->where('event', 'uploaded')->where('user_id', $this->legal->id)->count());
        $this->assertCount($storedBefore + 25, Storage::disk('s3')->allFiles());

        // 7. Jadwal pengingat yang sudah tercapai dicatat dibatalkan; pengiriman berikutnya tidak mengirim apa pun.
        $this->assertSame([[-1, 'cancelled', DocumentImporter::SKIPPED_REMINDER_MESSAGE], [30, 'cancelled', DocumentImporter::SKIPPED_REMINDER_MESSAGE]],
            ReminderLog::orderBy('offset_days')->get()->map(fn (ReminderLog $log): array => [$log->offset_days, $log->status, $log->error_message])->all());
        $this->artisan('lms:send-reminders')->assertSuccessful();
        Http::assertNothingSent();

        // 8. Jadwal berikutnya tetap berjalan: H+7 untuk dokumen yang sudah lewat, lalu H-7 untuk dokumen yang segera berakhir.
        $this->travelTo(now()->setDate(2026, 10, 12)->setTime(8, 0));
        $this->artisan('lms:send-reminders')->assertSuccessful();
        $this->assertCount(1, $this->sentMessages());
        $overdue = $this->messagesTo('6281200000001', 'Sewa Gudang Timur');
        $this->assertCount(1, $overdue);
        $this->assertStringContainsString('Terlambat: 7 hari', $overdue[0]['text']);

        $this->travelTo(now()->setDate(2026, 10, 13)->setTime(8, 0));
        $this->artisan('lms:send-reminders')->assertSuccessful();
        $this->artisan('lms:send-reminders')->assertSuccessful();
        $this->assertCount(2, $this->sentMessages());
        $soon = $this->messagesTo('6281200000001', 'Sewa Ruko');
        $this->assertCount(1, $soon);
        $this->assertStringContainsString('Sisa waktu: 7 hari', $soon[0]['text']);
        $this->assertSame([[-7, 'accepted'], [-1, 'cancelled'], [7, 'accepted'], [30, 'cancelled']],
            ReminderLog::orderBy('offset_days')->get()->map(fn (ReminderLog $log): array => [$log->offset_days, $log->status])->all());

        // 9. Unduhan baris gagal hanya berisi baris yang perlu diperbaiki, dengan kolom Keterangan.
        $report->call('downloadFailedRows')->assertFileDownloaded();
        $failed = $this->readWorkbook($report)['Dokumen'];
        $this->assertSame([...DocumentImportTemplate::headers(), DocumentImportTemplate::FAILED_NOTE_HEADER], $failed[0]);
        $this->assertCount(4, $failed);
        $this->assertSame(['Sewa Parkir', 'Sewa Lahan', 'Sewa Kios'], array_column(array_slice($failed, 1), 0));
        $this->assertStringStartsWith('Baris 6: File "hilang.pdf" tidak ditemukan di ZIP.', $failed[1][10]);
        $this->assertStringStartsWith('Baris 7: PIC "keluar@contoh.test" sudah nonaktif.', $failed[2][10]);
        $this->assertStringStartsWith('Baris 8: Badan Usaha "PT Tidak Terdaftar" tidak ditemukan.', $failed[3][10]);

        // 10. Batch yang sama diimpor ulang: yang sudah masuk dilewati, tidak ada dokumen ganda.
        $again = $this->checkThroughPage($rows, $zips)->assertSee('Tidak ada dokumen yang bisa diimpor')->assertSee('Dilewati (sudah ada)')
            ->assertDontSee('akan langsung dikirimi pengingat');
        $second = DocumentImport::latest('id')->first();
        $this->assertNotSame($import->id, $second->id);
        $this->assertSame([0, 27, 3], [$second->countRows('ready'), $second->skipped_count, $second->failed_count]);
        $this->assertSame("Sudah ada di arsip: \"Sewa Kantor Pusat\" (ID {$office->id}).", $this->rowAt($second, 2)['messages'][0]);
        $this->assertSame(['error', 'error', 'error'], [$this->rowAt($second, 6)['status'], $this->rowAt($second, 7)['status'], $this->rowAt($second, 8)['status']]);
        $again->call('startImport')->assertReturned(false);
        $this->assertSame('checked', $second->fresh()->status);
        $this->assertSame([26, 26, 4], [Document::count(), DocumentVersion::count(), ReminderLog::count()]);
        $this->assertCount(2, $this->sentMessages());
    }

    public function test_import_without_skipping_past_reminders_sends_the_catch_up_on_the_next_run(): void
    {
        $this->actingAs($this->legal);
        $rows = [
            $this->row(['title' => 'Sewa Gudang Timur', 'expiry_date' => '05/10/2026']),
            $this->row(['title' => 'Sewa Ruko', 'expiry_date' => '20/10/2026']),
            $this->row(['title' => 'Sewa Kantor Pusat', 'expiry_date' => '31/12/2027']),
        ];

        $this->checkThroughPage($rows, ['dokumen.zip' => $this->zipFor($rows)])
            ->assertSee('2 dokumen akan langsung dikirimi pengingat, 1 sudah kedaluwarsa.')
            ->set('skipPastReminders', false)
            ->call('startImport')->assertReturned(true)
            ->call('processNextChunk')->assertReturned(false)
            ->assertNotified('Impor selesai');

        $import = DocumentImport::sole();
        $this->assertSame(['completed', false, 3], [$import->status, $import->skip_past_reminders, $import->imported_count]);
        $this->assertSame(0, ReminderLog::count());

        $this->artisan('lms:send-reminders')->assertSuccessful();
        $this->artisan('lms:send-reminders')->assertSuccessful();

        $this->assertCount(2, $this->sentMessages());
        $this->assertStringContainsString('Terlambat: 4 hari', $this->messagesTo('6281200000001', 'Sewa Gudang Timur')[0]['text']);
        $this->assertStringContainsString('Sisa waktu: 11 hari', $this->messagesTo('6281200000001', 'Sewa Ruko')[0]['text']);
        $this->assertSame([[-1, 'accepted'], [30, 'accepted']],
            ReminderLog::orderBy('offset_days')->get()->map(fn (ReminderLog $log): array => [$log->offset_days, $log->status])->all());
    }

    public function test_deactivated_leaver_hands_every_task_to_replacements_end_to_end(): void
    {
        $leaver = $this->reviewer;
        $secondApprover = User::factory()->create(['name' => 'Penyetuju Cadangan', 'phone' => '6281200000006']);
        $secondApprover->assignRole('dummy_penyetuju');
        $vendorType = DocumentType::factory()->create([
            'name' => 'Perjanjian Vendor', 'has_expiry' => true,
            'request_pic_id' => $leaver->id, 'request_reviewer_id' => $leaver->id, 'request_approver_id' => $leaver->id,
        ]);

        // 1. Sebelum keluar, staf masih bisa meminta kode masuk WhatsApp.
        $otp = Livewire::test(WhatsAppLogin::class)->fillForm(['phone' => '081200000001'])->call('submit')->assertHasNoFormErrors()->assertSet('step', 'code');
        $this->assertCount(1, $this->messagesTo('6281200000001', 'Kode masuk LMS Legal'));
        preg_match('/\b(\d{6})\b/', $this->messagesTo('6281200000001')[0]['text'], $match);
        $oldCode = $match[1];

        // 2. Dokumen lama diimpor dengan staf ini sebagai PIC.
        $this->actingAs($this->legal);
        $rows = [$this->row(['title' => 'Sewa Gudang Barat', 'document_number' => 'SGB-01', 'pic_email' => $leaver->email, 'expiry_date' => '18/11/2026'])];
        $this->checkThroughPage($rows, ['dokumen.zip' => $this->zipFor($rows)])
            ->call('startImport')->assertReturned(true)->call('processNextChunk')->assertReturned(false);
        $document = Document::where('title', 'Sewa Gudang Barat')->sole();
        $this->assertSame($leaver->id, $document->pic_user_id);

        // 3. Pengajuan publik masuk dengan staf ini sebagai PIC, pemeriksa, dan penyetuju.
        $this->logOut();
        $request = $this->submitPublicRequest($vendorType, 'Kontrak Vendor Katering');
        $this->assertSame([$leaver->id, $leaver->id, $leaver->id], [$request->pic_user_id, $request->reviewer_id, $request->approver_id]);
        DB::table('sessions')->insert([
            ['id' => 'sesi-staf-keluar', 'user_id' => $leaver->id, 'payload' => '', 'last_activity' => now()->timestamp],
            ['id' => 'sesi-admin', 'user_id' => $this->admin->id, 'payload' => '', 'last_activity' => now()->timestamp],
        ]);
        $leaverMessagesBefore = count($this->messagesTo('6281200000001'));

        // 4. Admin menonaktifkan staf dan memilih pengganti per kelompok tugas, termasuk "Tim persetujuan".
        $this->actingAs($this->admin);
        Livewire::test(EditUser::class, ['record' => $leaver->getRouteKey()])
            ->mountAction('deactivate')
            ->assertMountedActionModalSee(['1 dokumen sebagai PIC', '1 pengajuan berjalan', '1 jenis dokumen'])
            ->assertFormFieldExists('approver', fn (Select $field): bool => array_key_first($field->getOptions()) === UserDeactivator::APPROVAL_TEAM
                && ! array_key_exists($leaver->id, $field->getOptions()))
            ->setActionData(['document_pic' => $this->viewer->id, 'reviewer' => $this->legal->id, 'approver' => UserDeactivator::APPROVAL_TEAM])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('User dinonaktifkan');

        $leaver->refresh();
        $this->assertFalse($leaver->isActive());
        $this->assertSame($this->admin->id, $leaver->deactivated_by);
        $this->assertSame(['sesi-admin'], DB::table('sessions')->pluck('id')->all());
        $this->assertSame($this->viewer->id, $document->fresh()->pic_user_id);
        $this->assertTrue(DocumentActivity::where('document_id', $document->id)->where('event', 'pic_reassigned')->where('user_id', $this->admin->id)->exists());
        $request->refresh();
        $this->assertSame([$this->legal->id, $this->legal->id, null], [$request->pic_user_id, $request->reviewer_id, $request->approver_id]);
        $vendorType->refresh();
        $this->assertSame([$this->legal->id, $this->legal->id, null], [$vendorType->request_pic_id, $vendorType->request_reviewer_id, $vendorType->request_approver_id]);

        // 5. Sesi yang masih terbuka ditolak; password dan kode WhatsApp tidak bisa dipakai, dan kode baru tidak dikirim.
        $this->actingAs($leaver)->get(Filament::getPanel('admin')->getUrl())->assertForbidden();
        $this->logOut();
        Livewire::test(Login::class)->fillForm(['email' => $leaver->email, 'password' => DummyAccountSeeder::PASSWORD])
            ->call('authenticate')->assertHasFormErrors(['email']);
        $this->assertGuest();
        $otp->fillForm(['code' => $oldCode])->call('submit')->assertHasFormErrors(['code']);
        $this->assertGuest();
        $this->travel(2)->minutes();
        Livewire::test(WhatsAppLogin::class)->fillForm(['phone' => '081200000001'])->call('submit')->assertHasNoFormErrors()->assertSet('step', 'code');
        $this->assertCount(1, $this->messagesTo('6281200000001', 'Kode masuk LMS Legal'));

        // 6. Pengajuan menggantung 2 hari: pengingat ke pemeriksa pengganti, bukan ke staf yang keluar.
        $this->travelTo(now()->setDate(2026, 10, 11)->setTime(8, 0));
        $this->artisan('lms:send-request-reminders')->expectsOutputToContain('1 pengingat pengajuan')->assertSuccessful();
        $this->deliver();
        $stale = $this->messagesTo('6281200000003', 'Pengajuan menunggu tindakan');
        $this->assertCount(1, $stale);
        $this->assertStringContainsString('Kontrak Vendor Katering', $stale[0]['text']);
        $this->assertStringContainsString('Menunggu 2 hari', $stale[0]['text']);
        $this->assertStringContainsString('Tahap: Diperiksa', $stale[0]['text']);
        $this->assertCount($leaverMessagesBefore, $this->messagesTo('6281200000001'));

        // 7. Pemeriksa pengganti benar-benar bisa meneruskan, lalu penyetuju mana pun dari tim bisa menyetujui.
        $this->actingAs($this->legal);
        Livewire::test(EditDocumentRequest::class, ['record' => $request->getRouteKey()])->assertActionVisible('review')
            ->callAction('review')->assertHasNoActionErrors();
        $request->refresh();
        $this->assertSame('review', $request->status);
        $this->assertTrue(Gate::forUser($this->approver)->allows('approve', $request));
        $this->assertTrue(Gate::forUser($secondApprover)->allows('approve', $request));
        $this->actingAs($secondApprover);
        Livewire::test(EditDocumentRequest::class, ['record' => $request->getRouteKey()])->assertActionVisible('approve')
            ->callAction('approve')->assertHasNoActionErrors();
        $request->refresh();
        $this->assertSame('approved', $request->status);
        $this->assertSame($secondApprover->id, collect($request->history)->last()['user_id']);

        // 8. Pengajuan baru untuk jenis dokumen yang sama langsung diarahkan ke pengganti.
        $this->logOut();
        $newRequest = $this->submitPublicRequest($vendorType, 'Kontrak Vendor Kebersihan', ['requester_phone' => '081366660000']);
        $this->assertSame([$this->legal->id, $this->legal->id, null], [$newRequest->pic_user_id, $newRequest->reviewer_id, $newRequest->approver_id]);
        $this->deliver();
        $this->assertCount(1, $this->messagesTo('6281200000003', 'Kontrak Vendor Kebersihan'));
        $this->assertCount($leaverMessagesBefore, $this->messagesTo('6281200000001'));

        // 9. Jadwal H-30 dokumen impor: pengingat dikirim ke nomor PIC baru.
        $this->travelTo(now()->setDate(2026, 10, 19)->setTime(8, 0));
        $this->artisan('lms:send-reminders')->assertSuccessful();
        Http::assertSent(fn ($sent): bool => $sent['recipient']['value'] === '6281200000005' && str_contains($sent['message']['text'], 'Sewa Gudang Barat'));
        $expiry = $this->messagesTo('6281200000005', 'Pengingat masa berlaku dokumen');
        $this->assertCount(1, $expiry);
        $this->assertStringContainsString('Sisa waktu: 30 hari', $expiry[0]['text']);
        $this->assertStringContainsString('PIC: '.$this->viewer->name, $expiry[0]['text']);
        $this->assertCount($leaverMessagesBefore, $this->messagesTo('6281200000001'));

        // 10. Staf nonaktif tidak ditawarkan sebagai PIC dan tidak diterima sebagai PIC pada impor baru.
        $this->actingAs($this->legal);
        Livewire::test(CreateDocument::class)
            ->assertFormFieldExists('pic_user_id', fn (Select $field): bool => ! array_key_exists($leaver->id, $field->getOptions())
                && array_key_exists($this->viewer->id, $field->getOptions()))
            ->fillForm(['pic_user_id' => $leaver->id])->call('create')->assertHasFormErrors(['pic_user_id']);
        $template = Livewire::test(ImportDocuments::class)->call('downloadTemplate');
        $guide = collect($this->readWorkbook($template)['Petunjuk'])->map(fn (array $row): string => implode(' | ', $row));
        $this->assertTrue($guide->contains($this->viewer->email.' | '.$this->viewer->name));
        $this->assertFalse($guide->contains(fn (string $line): bool => str_contains($line, $leaver->email)));
        $rows = [$this->row(['title' => 'Sewa Gudang Utara', 'pic_email' => $leaver->email])];
        $this->checkThroughPage($rows, ['dokumen.zip' => $this->zipFor($rows)])
            ->assertSee('Tidak ada dokumen yang bisa diimpor')->assertSee("PIC \"{$leaver->email}\" sudah nonaktif. Pilih user aktif.");
        $this->assertSame('error', $this->rowAt(DocumentImport::latest('id')->first(), 2)['status']);

        // 11. Diaktifkan kembali: bisa masuk lagi dengan password maupun kode WhatsApp; tugas tidak dikembalikan.
        $this->actingAs($this->admin);
        Livewire::test(EditUser::class, ['record' => $leaver->getRouteKey()])->assertActionHidden('deactivate')
            ->callAction('reactivate')->assertNotified('User diaktifkan kembali');
        $this->assertTrue($leaver->fresh()->isActive());
        $this->assertNull($leaver->fresh()->deactivated_by);
        $this->assertSame($this->viewer->id, $document->fresh()->pic_user_id);
        $this->logOut();
        Livewire::test(Login::class)->fillForm(['email' => $leaver->email, 'password' => DummyAccountSeeder::PASSWORD])
            ->call('authenticate')->assertHasNoFormErrors();
        $this->assertAuthenticatedAs($leaver);
        $this->logOut();
        $login = Livewire::test(WhatsAppLogin::class)->fillForm(['phone' => '081200000001'])->call('submit')->assertHasNoFormErrors();
        $codes = $this->messagesTo('6281200000001', 'Kode masuk LMS Legal');
        $this->assertCount(2, $codes);
        preg_match('/\b(\d{6})\b/', $codes[1]['text'], $match);
        $login->fillForm(['code' => $match[1]])->call('submit')->assertHasNoFormErrors()->assertRedirect(Filament::getUrl());
        $this->assertAuthenticatedAs($leaver);

        // 12. Dinonaktifkan lagi, nomornya dipakai staf baru, lalu aktivasi ulang ditolak karena nomor bentrok.
        $this->logOut();
        $this->actingAs($this->admin);
        Livewire::test(ListUsers::class)->callAction(TestAction::make('deactivate')->table($leaver))->assertNotified('User dinonaktifkan');
        $this->assertFalse($leaver->fresh()->isActive());
        Livewire::test(CreateUser::class)->fillForm([
            'name' => 'Pemeriksa Baru', 'email' => 'pemeriksa-baru@contoh.test', 'phone' => '081200000001',
            'roles' => [Role::findByName('dummy_pemeriksa', 'web')->id], 'password' => 'sandi-baru-123', 'password_confirmation' => 'sandi-baru-123',
        ])->call('create')->assertHasNoFormErrors();
        $this->assertSame('6281200000001', User::where('email', 'pemeriksa-baru@contoh.test')->sole()->phone);
        Livewire::test(EditUser::class, ['record' => $leaver->getRouteKey()])
            ->callAction('reactivate')->assertNotified('User belum bisa diaktifkan kembali');
        $this->assertFalse($leaver->fresh()->isActive());
    }
}
