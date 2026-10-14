<?php

namespace Tests\Feature;

use App\Filament\Resources\Documents\DocumentResource;
use App\Filament\Resources\Documents\Pages\ImportDocuments;
use App\Filament\Resources\Documents\Pages\ListDocuments;
use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentImport;
use App\Models\DocumentType;
use App\Models\ReminderLog;
use App\Models\ReminderTemplate;
use App\Models\User;
use App\Services\DocumentImporter;
use App\Services\DocumentImportTemplate;
use Carbon\CarbonImmutable;
use Database\Seeders\DocumentSeeder;
use Database\Seeders\ReminderLogSeeder;
use DateTimeInterface;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;
use ZipArchive;

class DocumentImportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private User $pic;

    private Company $company;

    private DocumentType $expiringType;

    private DocumentType $permanentType;

    /** @var list<string> */
    private array $tempFiles = [];

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-14 09:00', 'Asia/Jakarta'));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Storage::fake('s3');
        Storage::fake('local');
        $this->seed(DocumentSeeder::class);
        $this->seed(ReminderLogSeeder::class);
        ReminderTemplate::factory()->create(['schedule_mode' => 'specific_days', 'scheduled_days' => [30, 7], 'overdue_days' => [1, 7], 'body' => ReminderTemplate::DEFAULT_BODY]);
        $this->user = User::factory()->create();
        $this->user->givePermissionTo(['ViewAny:Document', 'View:Document', 'Create:Document']);
        $this->actingAs($this->user);
        $this->pic = User::factory()->create(['name' => 'Pak PIC', 'email' => 'pic@contoh.test', 'phone' => '081234567890']);
        $this->company = Company::factory()->create(['name' => 'Maju Jaya', 'legal_form' => 'PT']);
        $this->expiringType = DocumentType::factory()->create(['name' => 'Perjanjian Sewa', 'has_expiry' => true]);
        $this->permanentType = DocumentType::factory()->withoutExpiry()->create(['name' => 'Akta Pendirian']);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    private function tempPath(): string
    {
        return $this->tempFiles[] = tempnam(sys_get_temp_dir(), 'lms-import-');
    }

    /** @return array<string, mixed> */
    private function row(array $overrides = []): array
    {
        $this->sequence++;

        return [
            'title' => "Sewa Gudang {$this->sequence}",
            'document_number' => "SEWA-{$this->sequence}",
            'company' => 'PT Maju Jaya',
            'document_type' => 'Perjanjian Sewa',
            'counterparty' => 'CV Mitra',
            'pic_email' => 'pic@contoh.test',
            'issued_date' => '01/01/2026',
            'expiry_date' => '31/12/2027',
            'notes' => null,
            'file_name' => "sewa-{$this->sequence}.pdf",
            ...$overrides,
        ];
    }

    /** @param list<array<string, mixed>> $rows */
    private function xlsx(array $rows, ?array $headers = null): string
    {
        $path = $this->tempPath();
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('Dokumen');
        $writer->addRow(Row::fromValues($headers ?? DocumentImportTemplate::headers()));
        $dateStyle = (new Style)->setFormat('dd/mm/yyyy');
        foreach ($rows as $row) {
            $writer->addRow(new Row(array_map(fn (string $key): Cell => ($row[$key] ?? null) instanceof DateTimeInterface
                ? Cell::fromValue($row[$key], $dateStyle) : Cell::fromValue($row[$key] ?? ''), array_keys(DocumentImporter::COLUMNS))));
        }
        $writer->close();

        return $path;
    }

    /** @param array<string, string|null> $files Nama entri => isi (null untuk folder). */
    private function zip(array $files): string
    {
        $path = $this->tempPath();
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($files as $name => $content) {
            $content === null ? $zip->addEmptyDir($name) : $zip->addFromString($name, $content);
        }
        $zip->close();

        return $path;
    }

    private function pdf(string $name = 'dokumen'): string
    {
        return "%PDF-1.4\n% {$name}\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";
    }

    /** @param list<array<string, mixed>> $rows */
    private function zipFor(array $rows, array $extra = []): string
    {
        $files = [];
        foreach ($rows as $row) {
            if (filled($row['file_name'] ?? null)) {
                $files[$row['file_name']] = $this->pdf($row['file_name']);
            }
        }

        return $this->zip([...$files, ...$extra]);
    }

    /** @param list<array<string, mixed>> $rows */
    private function check(array $rows, ?string $zipPath = null): DocumentImport
    {
        return app(DocumentImporter::class)->check($this->xlsx($rows), 'arsip.xlsx', [['path' => $zipPath ?? $this->zipFor($rows), 'name' => 'dokumen.zip']], $this->user);
    }

    private function importAll(DocumentImport $import, bool $skipPastReminders = true): DocumentImport
    {
        $importer = app(DocumentImporter::class);
        $importer->start($import, $skipPastReminders);
        for ($i = 0; $i < 50 && $import->refresh()->status === 'importing'; $i++) {
            $importer->processChunk($import, $this->user);
        }

        return $import->refresh();
    }

    /** @return array<string, mixed> */
    private function rowAt(DocumentImport $import, int $line): array
    {
        return collect($import->rows)->firstWhere('line', $line);
    }

    /** @return array<string, list<list<mixed>>> */
    private function readWorkbook(string $path): array
    {
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

    private function downloadedContent(mixed $component): string
    {
        $path = $this->tempPath();
        file_put_contents($path, base64_decode(data_get($component->effects, 'download.content')));

        return $path;
    }

    public function test_template_lists_columns_rules_and_only_active_master_data(): void
    {
        Company::factory()->inactive()->create(['name' => 'Lama Tutup', 'legal_form' => 'CV']);
        DocumentType::factory()->inactive()->create(['name' => 'Jenis Usang']);
        User::factory()->deactivated()->create(['email' => 'keluar@contoh.test']);

        $component = Livewire::test(ImportDocuments::class)->call('downloadTemplate')->assertFileDownloaded('template-impor-dokumen.xlsx');
        $sheets = $this->readWorkbook($this->downloadedContent($component));

        $this->assertSame(['Dokumen', 'Petunjuk'], array_keys($sheets));
        $this->assertSame(['Judul*', 'Nomor Dokumen', 'Badan Usaha*', 'Jenis Dokumen*', 'Pihak Lawan', 'Email PIC*', 'Tanggal Terbit', 'Tanggal Berakhir', 'Catatan', 'Nama File*'], $sheets['Dokumen'][0]);
        $this->assertCount(1, $sheets['Dokumen']);

        $guide = collect($sheets['Petunjuk'])->map(fn (array $row): string => implode(' | ', $row));
        $this->assertTrue($guide->contains('PT Maju Jaya'));
        $this->assertTrue($guide->contains('Perjanjian Sewa | Ada, Tanggal Berakhir wajib'));
        $this->assertTrue($guide->contains('Akta Pendirian | Tanpa masa berlaku'));
        $this->assertTrue($guide->contains('pic@contoh.test | Pak PIC'));
        $this->assertFalse($guide->contains(fn (string $line): bool => str_contains($line, 'Lama Tutup') || str_contains($line, 'Jenis Usang') || str_contains($line, 'keluar@contoh.test')));
        $this->assertTrue($guide->contains(fn (string $line): bool => str_contains($line, 'dd/mm/yyyy')));
    }

    public function test_check_is_a_dry_run_that_saves_no_document(): void
    {
        $import = $this->check([$this->row(), $this->row()]);

        $this->assertSame('checked', $import->status);
        $this->assertSame(2, $import->countRows('ready'));
        $this->assertSame([2, 3], collect($import->rows)->pluck('line')->all());
        $this->assertDatabaseCount('documents', 0);
        $this->assertDatabaseCount('document_versions', 0);
        $this->assertDatabaseCount('document_activities', 0);
        $this->assertSame([], Storage::disk('s3')->allFiles());
        $this->assertCount(2, Storage::disk('local')->files($import->directory().'/files'));
    }

    public function test_each_row_problem_gets_an_indonesian_message(): void
    {
        Company::factory()->inactive()->create(['name' => 'Tutup', 'legal_form' => 'CV']);
        DocumentType::factory()->inactive()->create(['name' => 'Jenis Usang']);
        User::factory()->deactivated()->create(['email' => 'keluar@contoh.test']);
        $rows = [
            $this->row(['company' => 'Tidak Ada']),
            $this->row(['company' => 'CV Tutup']),
            $this->row(['document_type' => 'Jenis Asing']),
            $this->row(['document_type' => 'Jenis Usang']),
            $this->row(['pic_email' => 'siapa@contoh.test']),
            $this->row(['pic_email' => 'keluar@contoh.test']),
            $this->row(['expiry_date' => null]),
            $this->row(['issued_date' => '01/06/2027', 'expiry_date' => '01/01/2027']),
            $this->row(['file_name' => 'hilang.pdf']),
            $this->row(['file_name' => 'catatan.txt']),
            $this->row(['file_name' => 'ganda.pdf']),
            $this->row(['file_name' => 'sama.pdf']),
            $this->row(['file_name' => 'sama.pdf']),
            $this->row(['issued_date' => '31/02/2026']),
            $this->row(['title' => null]),
            $this->row(['file_name' => 'palsu.pdf']),
        ];
        $zip = $this->zipFor(array_slice($rows, 0, 8), [
            'catatan.txt' => 'teks', 'a/ganda.pdf' => $this->pdf('a'), 'b/ganda.pdf' => $this->pdf('b'), 'sama.pdf' => $this->pdf('sama'),
            'sewa-14.pdf' => $this->pdf(), 'sewa-15.pdf' => $this->pdf(), 'palsu.pdf' => 'ini bukan pdf',
        ]);

        $import = $this->check($rows, $zip);

        $expected = [
            2 => 'Badan Usaha "Tidak Ada" tidak ditemukan',
            3 => 'Badan Usaha "CV Tutup" sudah nonaktif.',
            4 => 'Jenis Dokumen "Jenis Asing" tidak ditemukan',
            5 => 'Jenis Dokumen "Jenis Usang" sudah nonaktif.',
            6 => 'Email PIC "siapa@contoh.test" tidak terdaftar sebagai user.',
            7 => 'PIC "keluar@contoh.test" sudah nonaktif. Pilih user aktif.',
            8 => 'Tanggal Berakhir wajib diisi karena jenis dokumen "Perjanjian Sewa" memiliki masa berlaku.',
            9 => 'Tanggal Berakhir tidak boleh sebelum Tanggal Terbit.',
            10 => 'File "hilang.pdf" tidak ditemukan di ZIP.',
            11 => 'Jenis file .txt tidak diizinkan.',
            12 => 'Ada lebih dari satu file bernama "ganda.pdf" di ZIP.',
            13 => 'Nama File "sama.pdf" dipakai di lebih dari satu baris (baris 13, 14).',
            14 => 'Nama File "sama.pdf" dipakai di lebih dari satu baris (baris 13, 14).',
            15 => 'Tanggal Terbit "31/02/2026" tidak valid. Gunakan format dd/mm/yyyy',
            16 => 'Judul wajib diisi.',
            17 => 'Isi file "palsu.pdf" tidak sesuai jenisnya atau rusak.',
        ];
        foreach ($expected as $line => $message) {
            $row = $this->rowAt($import, $line);
            $this->assertSame('error', $row['status'], "Baris {$line}");
            $this->assertStringContainsString($message, implode(' ', $row['messages']), "Baris {$line}");
        }
        $this->assertSame(0, $import->countRows('ready'));
        $this->assertSame(16, $import->failed_count);
        $this->assertDatabaseCount('documents', 0);
    }

    public function test_company_matches_name_or_legal_form_and_name_case_insensitively_and_expiry_is_ignored_for_permanent_types(): void
    {
        $import = $this->check([
            $this->row(['company' => 'maju jaya']),
            $this->row(['company' => 'pt  MAJU jaya', 'pic_email' => 'PIC@Contoh.test', 'document_type' => 'perjanjian sewa']),
            $this->row(['document_type' => 'Akta Pendirian', 'expiry_date' => '01/01/2030']),
        ]);

        $this->assertSame(3, $import->countRows('ready'));
        $this->assertSame([$this->company->id, $this->expiringType->id, $this->pic->id], [
            $this->rowAt($import, 3)['data']['company_id'], $this->rowAt($import, 3)['data']['document_type_id'], $this->rowAt($import, 3)['data']['pic_user_id'],
        ]);
        $this->assertNull($this->rowAt($import, 4)['data']['expiry_date']);
        $this->assertStringContainsString('Tanggal Berakhir diabaikan', implode(' ', $this->rowAt($import, 4)['messages']));
    }

    public function test_excel_date_cells_and_text_dates_are_both_accepted(): void
    {
        $import = $this->check([
            $this->row(['issued_date' => new \DateTimeImmutable('2026-02-03'), 'expiry_date' => new \DateTimeImmutable('2028-04-05')]),
            $this->row(['issued_date' => '3/2/2026', 'expiry_date' => '2028-04-05']),
        ]);

        foreach ([2, 3] as $line) {
            $row = $this->rowAt($import, $line);
            $this->assertSame('ready', $row['status']);
            $this->assertSame(['2026-02-03', '2028-04-05'], [$row['data']['issued_date'], $row['data']['expiry_date']]);
            $this->assertSame(['03/02/2026', '05/04/2028'], [$row['values']['issued_date'], $row['values']['expiry_date']]);
        }
    }

    public function test_import_creates_document_first_version_stored_file_activity_and_batch_change_note(): void
    {
        $import = $this->importAll($this->check([$this->row([
            'title' => 'Sewa Kantor Pusat', 'document_number' => 'SK-77', 'counterparty' => 'PT Pemilik Gedung',
            'notes' => 'Arsip lemari 3', 'file_name' => 'Kontrak Kantor.pdf',
        ])]));

        $this->assertSame('completed', $import->status);
        $this->assertNotNull($import->completed_at);
        $this->assertSame([1, 0, 0], [$import->imported_count, $import->skipped_count, $import->failed_count]);
        $document = Document::sole();
        $this->assertSame($document->id, $this->rowAt($import, 2)['document_id']);
        $this->assertSame(['Sewa Kantor Pusat', 'SK-77', 'PT Pemilik Gedung', 'Arsip lemari 3'], [$document->title, $document->document_number, $document->counterparty, $document->notes]);
        $this->assertSame([$this->company->id, $this->expiringType->id, $this->pic->id], [$document->company_id, $document->document_type_id, $document->pic_user_id]);
        $version = $document->currentVersion;
        $this->assertSame(1, $version->version_number);
        $this->assertSame("Diimpor dari arsip lama (batch #{$import->id})", $version->change_note);
        $this->assertSame('Kontrak Kantor.pdf', $version->file_name);
        $this->assertSame('application/pdf', $version->mime_type);
        $this->assertSame(['2026-01-01', '2027-12-31'], [$version->issued_date->toDateString(), $version->expiry_date->toDateString()]);
        $this->assertSame($this->user->id, $version->uploaded_by);
        Storage::disk('s3')->assertExists($version->file_path);
        $this->assertSame($this->pdf('Kontrak Kantor.pdf'), Storage::disk('s3')->get($version->file_path));
        $this->assertDatabaseHas('document_activities', ['document_id' => $document->id, 'document_version_id' => $version->id, 'user_id' => $this->user->id, 'event' => 'uploaded']);
        Storage::disk('local')->assertMissing($import->directory());
    }

    public function test_existing_documents_and_repeated_rows_are_skipped_and_reimporting_the_same_batch_adds_nothing(): void
    {
        $existing = Document::archive([
            'company_id' => $this->company->id, 'document_type_id' => $this->expiringType->id, 'pic_user_id' => $this->pic->id,
            'title' => 'Sewa Lama', 'document_number' => 'SEWA-LAMA', 'expiry_date' => '2027-01-01',
        ], UploadedFile::fake()->create('lama.pdf', 1, 'application/pdf'), $this->user);
        Document::archive([
            'company_id' => $this->company->id, 'document_type_id' => $this->permanentType->id, 'pic_user_id' => $this->pic->id, 'title' => 'Akta Notaris',
        ], UploadedFile::fake()->create('akta.pdf', 1, 'application/pdf'), $this->user);
        $rows = [
            $this->row(['document_number' => 'sewa-lama']),
            $this->row(['document_number' => 'BARU-1']),
            $this->row(['document_number' => 'BARU-1', 'title' => 'Judul lain']),
            $this->row(['document_type' => 'Akta Pendirian', 'document_number' => null, 'title' => 'akta notaris']),
        ];
        $xlsx = $this->xlsx($rows);
        $zip = $this->zipFor($rows);

        $import = app(DocumentImporter::class)->check($xlsx, 'arsip.xlsx', [['path' => $zip, 'name' => 'a.zip']], $this->user);
        $this->assertSame(['skipped', 'ready', 'skipped', 'skipped'], collect($import->rows)->pluck('status')->all());
        $this->assertStringContainsString("Sudah ada di arsip: \"Sewa Lama\" (ID {$existing->id})", $this->rowAt($import, 2)['messages'][0]);
        $this->assertSame('Sama dengan baris 3 di file ini.', $this->rowAt($import, 4)['messages'][0]);
        $this->assertSame('Dilewati (sudah ada)', DocumentImport::ROW_STATUSES['skipped']);

        $this->importAll($import);
        $this->assertDatabaseCount('documents', 3);

        $again = app(DocumentImporter::class)->check($xlsx, 'arsip.xlsx', [['path' => $zip, 'name' => 'a.zip']], $this->user);
        $this->assertSame(['skipped', 'skipped', 'skipped', 'skipped'], collect($again->rows)->pluck('status')->all());
        $this->assertFalse(app(DocumentImporter::class)->start($again, true));
        $this->assertDatabaseCount('documents', 3);
    }

    public function test_row_already_imported_by_another_batch_after_checking_is_skipped_at_import_time(): void
    {
        $rows = [$this->row()];
        $first = $this->check($rows);
        $second = $this->check($rows);

        $this->importAll($first);
        $second = $this->importAll($second);

        $this->assertDatabaseCount('documents', 1);
        $this->assertSame('skipped', $this->rowAt($second, 2)['status']);
    }

    public function test_zip_paths_are_flattened_and_folders_mac_metadata_and_hidden_files_are_ignored(): void
    {
        $rows = [$this->row(['file_name' => 'evil.pdf']), $this->row(['file_name' => 'Kontrak A.pdf'])];
        $zip = $this->zip([
            '../../evil.pdf' => $this->pdf('evil'),
            'arsip/' => null,
            'arsip/2019/KONTRAK A.PDF' => $this->pdf('a'),
            '__MACOSX/arsip/2019/._KONTRAK A.PDF' => 'metadata',
            '.DS_Store' => 'x',
            'arsip/.hidden.pdf' => $this->pdf('hidden'),
        ]);

        $import = $this->check($rows, $zip);

        $this->assertSame(['ready', 'ready'], collect($import->rows)->pluck('status')->all());
        $this->assertSame(0, $import->notes['unused_count']);
        foreach ($import->rows as $row) {
            $this->assertStringStartsWith($import->directory().'/files/', $row['file']['stored']);
        }
        $this->assertSame(['evil.pdf', 'KONTRAK A.PDF'], collect($import->rows)->pluck('file.name')->all());
        $root = Storage::disk('local')->path('');
        $this->assertSame([], array_values(array_filter(File::allFiles(dirname($root, 2)), fn ($file): bool => $file->getFilename() === 'evil.pdf')));

        $this->importAll($import);
        $this->assertSame(['evil.pdf', 'KONTRAK A.PDF'], Document::with('currentVersion')->orderBy('id')->get()->pluck('currentVersion.file_name')->all());
    }

    public function test_zip_entry_count_and_extracted_size_limits_are_enforced(): void
    {
        config(['lms.import_max_rows' => 2]);
        $rows = [$this->row(), $this->row()];
        try {
            $this->check($rows, $this->zipFor($rows, ['lain.pdf' => $this->pdf()]));
            $this->fail('ZIP dengan file melebihi batas harus ditolak.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Isi ZIP lebih dari 2 file', $exception->errors()['zips'][0]);
        }

        try {
            $this->check([...$rows, $this->row()]);
            $this->fail('Excel dengan baris melebihi batas harus ditolak.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('lebih dari 2 baris', $exception->errors()['excel'][0]);
        }

        config(['lms.import_max_rows' => 200, 'lms.import_max_zip_kb' => 1]);
        try {
            $this->check([$this->row(['file_name' => 'besar.pdf'])], $this->zip(['besar.pdf' => $this->pdf().str_repeat('x', 2048)]));
            $this->fail('ZIP yang isinya melebihi batas ukuran harus ditolak.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Total isi ZIP setelah diekstrak melebihi', $exception->errors()['zips'][0]);
        }

        config(['lms.import_max_zip_kb' => 512000, 'lms.max_upload_size_kb' => 1]);
        $import = $this->check([$this->row(['file_name' => 'besar.pdf'])], $this->zip(['besar.pdf' => $this->pdf().str_repeat('x', 2048)]));
        $this->assertStringContainsString('Ukuran file "besar.pdf" melebihi', $this->rowAt($import, 2)['messages'][0]);
        $this->assertSame([], Storage::disk('local')->files($import->directory().'/files'));

        $this->assertSame(1, DocumentImport::count());
        $this->assertCount(1, Storage::disk('local')->directories('imports'));
    }

    public function test_invalid_excel_or_zip_is_rejected_with_a_clear_message(): void
    {
        $zip = $this->zip(['lain.pdf' => $this->pdf()]);
        foreach ([
            'excel' => [$this->zip(['bukan.txt' => 'x']), $zip, 'File Excel tidak bisa dibaca'],
            'zips' => [$this->xlsx([$this->row()]), $this->tempPath(), 'bukan ZIP yang valid'],
        ] as $key => [$excel, $zipPath, $message]) {
            try {
                app(DocumentImporter::class)->check($excel, 'arsip.xlsx', [['path' => $zipPath, 'name' => 'dokumen.zip']], $this->user);
                $this->fail("{$key} harus ditolak.");
            } catch (ValidationException $exception) {
                $this->assertStringContainsString($message, $exception->errors()[$key][0]);
            }
        }

        try {
            app(DocumentImporter::class)->check($this->xlsx([$this->row()], ['Judul', 'Badan Usaha']), 'arsip.xlsx', [['path' => $zip, 'name' => 'a.zip']], $this->user);
            $this->fail('Kolom wajib yang hilang harus ditolak.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('Kolom wajib tidak ditemukan: Jenis Dokumen, Email PIC, Nama File', $exception->errors()['excel'][0]);
        }
    }

    public function test_chunked_import_can_be_resumed_without_duplicates(): void
    {
        $rows = array_map(fn (): array => $this->row(), range(1, 5));
        $import = $this->check($rows);
        $importer = app(DocumentImporter::class);
        $importer->start($import, true);

        $importer->processChunk($import, $this->user, 2);
        $this->assertSame([2, 3, 'importing'], [$import->refresh()->processedCount(), $import->countRows('ready'), $import->status]);

        $lock = Cache::lock('document-import-'.$import->id, 600);
        $lock->get();
        $importer->processChunk($import, $this->user, 2);
        $this->assertSame(2, $import->refresh()->processedCount());
        $lock->release();

        $component = Livewire::withQueryParams(['batch' => $import->id])->test(ImportDocuments::class)->assertSee('Lanjutkan impor')->assertSee('2 dari 5 dokumen diproses');
        $component->call('processNextChunk');
        $this->assertSame('completed', $import->refresh()->status);
        $this->assertSame(5, $import->imported_count);
        $this->assertDatabaseCount('documents', 5);
        $component->assertSee('Laporan impor');
    }

    public function test_master_data_changed_after_checking_fails_the_row_with_a_message(): void
    {
        $import = $this->check([$this->row()]);
        $this->company->update(['is_active' => false]);

        $import = $this->importAll($import);

        $this->assertSame('failed', $this->rowAt($import, 2)['status']);
        $this->assertSame(['Badan usaha tidak ditemukan atau sudah nonaktif.'], $this->rowAt($import, 2)['messages']);
        $this->assertSame(1, $import->failed_count);
        $this->assertDatabaseCount('documents', 0);
    }

    private function fakeWaghub(): void
    {
        config(['services.waghub.url' => 'https://waghub.test', 'services.waghub.token' => 'test-token', 'services.waghub.purpose' => 'test-purpose']);
        Http::preventStrayRequests();
        Http::fake(['waghub.test/*' => Http::response(['status' => 'accepted'], 202)]);
    }

    /** @return list<array<string, mixed>> */
    private function reminderRows(): array
    {
        return [
            $this->row(['title' => 'Segera Berakhir', 'expiry_date' => '20/10/2026']),
            $this->row(['title' => 'Sudah Lewat', 'expiry_date' => '01/10/2026']),
            $this->row(['title' => 'Masih Lama', 'expiry_date' => '14/10/2027']),
            $this->row(['title' => 'Tanpa Masa', 'document_type' => 'Akta Pendirian', 'expiry_date' => null]),
        ];
    }

    public function test_skipping_past_reminders_cancels_reached_schedules_so_nothing_old_is_sent(): void
    {
        $this->fakeWaghub();
        $import = $this->check($this->reminderRows());
        $this->assertSame(['due' => 2, 'expired' => 1], app(DocumentImporter::class)->reminderImpact($import));

        $this->importAll($import, skipPastReminders: true);

        $this->assertSame([[7, 'cancelled', 'Dilewati saat impor'], [-7, 'cancelled', 'Dilewati saat impor']],
            ReminderLog::orderBy('id')->get()->map(fn (ReminderLog $log): array => [$log->offset_days, $log->status, $log->error_message])->all());
        $this->artisan('lms:send-reminders')->assertSuccessful();
        Http::assertNothingSent();
        $this->assertSame(2, ReminderLog::count());

        // Jadwal berikutnya tetap berjalan: sehari setelah berakhir, pengingat kedaluwarsa hari ke-1 dikirim.
        $this->travelTo(CarbonImmutable::parse('2026-10-21 09:00', 'Asia/Jakarta'));
        $this->artisan('lms:send-reminders')->assertSuccessful();
        Http::assertSentCount(1);
        $this->assertSame('accepted', ReminderLog::where('offset_days', -1)->sole()->status);
    }

    public function test_without_skipping_past_reminders_catch_up_is_sent_as_usual(): void
    {
        $this->fakeWaghub();

        $this->importAll($this->check($this->reminderRows()), skipPastReminders: false);

        $this->assertSame(0, ReminderLog::count());
        $this->artisan('lms:send-reminders')->assertSuccessful();
        Http::assertSentCount(2);
        $this->assertSame([-7, 7], ReminderLog::orderBy('offset_days')->pluck('offset_days')->all());
    }

    public function test_page_runs_the_whole_flow_from_upload_to_report(): void
    {
        $rows = [...$this->reminderRows(), $this->row(['company' => 'Tidak Ada'])];
        $component = Livewire::test(ImportDocuments::class)
            ->assertSee('Unduh template')->assertSee('Periksa file')
            ->fillForm([
                'excel' => UploadedFile::fake()->createWithContent('arsip.xlsx', file_get_contents($this->xlsx($rows))),
                'zips' => [UploadedFile::fake()->createWithContent('dokumen.zip', file_get_contents($this->zipFor($rows)))],
            ])
            ->call('check')->assertHasNoFormErrors()
            ->assertSee('Hasil pemeriksaan')->assertSee('Impor 4 dokumen')
            ->assertSee('2 dokumen akan langsung dikirimi pengingat, 1 sudah kedaluwarsa.')
            ->assertSee('Lewati pengingat yang jadwalnya sudah lewat')
            ->assertSee('Badan Usaha &quot;Tidak Ada&quot; tidak ditemukan', false)
            ->assertSet('skipPastReminders', true);
        $import = DocumentImport::sole();
        $component->assertSet('importId', $import->id);
        $this->assertSame([], collect(Storage::disk('local')->files('livewire-tmp'))->reject(fn (string $file): bool => str_ends_with($file, '.json'))->values()->all());
        $this->assertDatabaseCount('documents', 0);

        $component->set('skipPastReminders', false)->call('startImport')->call('processNextChunk');

        $import->refresh();
        $this->assertSame('completed', $import->status);
        $this->assertFalse($import->skip_past_reminders);
        $this->assertDatabaseCount('documents', 4);
        $component->assertSee('Laporan impor')->assertSee('Unduh baris gagal (Excel)');
        Storage::disk('local')->assertMissing($import->directory());
    }

    public function test_page_reports_file_level_problems_on_the_upload_fields(): void
    {
        Livewire::test(ImportDocuments::class)
            ->fillForm([
                'excel' => UploadedFile::fake()->createWithContent('arsip.xlsx', file_get_contents($this->xlsx([$this->row()], ['Judul']))),
                'zips' => [UploadedFile::fake()->createWithContent('dokumen.zip', file_get_contents($this->zip(['lain.pdf' => $this->pdf()])))],
            ])
            ->call('check')->assertHasFormErrors(['excel']);

        Livewire::test(ImportDocuments::class)->call('check')->assertHasFormErrors(['excel' => 'required', 'zips' => 'required']);
        $this->assertDatabaseCount('document_imports', 0);
    }

    public function test_failed_rows_can_be_downloaded_and_imported_again_after_fixing(): void
    {
        $rows = [$this->row(['title' => 'Benar']), $this->row(['title' => 'Salah PIC', 'pic_email' => 'siapa@contoh.test'])];
        $import = $this->check($rows);

        $component = Livewire::withQueryParams(['batch' => $import->id])->test(ImportDocuments::class)
            ->assertSee('Unduh baris bermasalah')
            ->call('downloadFailedRows')->assertFileDownloaded();
        $this->assertMatchesRegularExpression("/^impor-gagal-batch-{$import->id}-\d{8}-\d{6}\.xlsx$/", data_get($component->effects, 'download.name'));
        $sheet = $this->readWorkbook($this->downloadedContent($component))['Dokumen'];

        $this->assertSame([...DocumentImportTemplate::headers(), 'Keterangan'], $sheet[0]);
        $this->assertCount(2, $sheet);
        $this->assertSame(['Salah PIC', 'siapa@contoh.test', '01/01/2026', '31/12/2027'], [$sheet[1][0], $sheet[1][5], $sheet[1][6], $sheet[1][7]]);
        $this->assertStringContainsString('Baris 3: Email PIC "siapa@contoh.test" tidak terdaftar', $sheet[1][10]);

        $fixed = $this->tempPath();
        $writer = new Writer;
        $writer->openToFile($fixed);
        $writer->getCurrentSheet()->setName('Dokumen');
        $writer->addRow(Row::fromValues($sheet[0]));
        $sheet[1][5] = 'pic@contoh.test';
        $writer->addRow(Row::fromValues($sheet[1]));
        $writer->close();
        $again = app(DocumentImporter::class)->check($fixed, 'perbaikan.xlsx', [['path' => $this->zipFor($rows), 'name' => 'a.zip']], $this->user);
        $this->assertSame(['ready'], collect($again->rows)->pluck('status')->all());
    }

    public function test_access_requires_create_document_permission_and_own_batch(): void
    {
        Livewire::test(ListDocuments::class)->assertActionVisible('import');
        $this->get(DocumentResource::getUrl('import'))->assertSuccessful()->assertSee('Impor Dokumen Lama');
        $import = $this->check([$this->row()]);

        $other = User::factory()->create();
        $other->givePermissionTo(['ViewAny:Document', 'View:Document', 'Create:Document']);
        $this->actingAs($other);
        Livewire::withQueryParams(['batch' => $import->id])->test(ImportDocuments::class)->assertSet('importId', null)->assertSee('Periksa file');

        $viewer = User::factory()->create();
        $viewer->givePermissionTo(['ViewAny:Document', 'View:Document']);
        $this->actingAs($viewer);
        Livewire::test(ListDocuments::class)->assertActionHidden('import');
        Livewire::test(ImportDocuments::class)->assertForbidden();
        $this->get(DocumentResource::getUrl('import'))->assertForbidden();
    }

    public function test_cancelled_batch_and_daily_cleanup_remove_temporary_files(): void
    {
        $cancelled = $this->check([$this->row()]);
        app(DocumentImporter::class)->cancel($cancelled);
        $this->assertSame('cancelled', $cancelled->refresh()->status);
        Storage::disk('local')->assertMissing($cancelled->directory());

        $stale = $this->check([$this->row()]);
        $fresh = $this->check([$this->row()]);
        Storage::disk('local')->makeDirectory('imports/sisa-gagal');
        $this->travel(25)->hours();
        $fresh->touch();
        $old = now()->subHours(25)->getTimestamp();
        foreach ([$stale->directory(), $fresh->directory(), 'imports/sisa-gagal'] as $directory) {
            touch(Storage::disk('local')->path($directory), $old);
        }

        $this->artisan('lms:clean-import-files')->assertSuccessful();

        $this->assertSame('expired', $stale->refresh()->status);
        $this->assertSame('checked', $fresh->refresh()->status);
        Storage::disk('local')->assertMissing($stale->directory());
        Storage::disk('local')->assertMissing('imports/sisa-gagal');
        Storage::disk('local')->assertExists($fresh->directory());
        $this->assertSame('ready', collect(app(DocumentImporter::class)->failedRows($stale))->first()['status']);
    }
}
