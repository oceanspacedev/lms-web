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
        $this->assertSame('kontrak-soon.pdf', $row[11]);
        $this->assertSame('2026-10-09', $row[12]->format('Y-m-d'));

        $this->assertSame(['Kedaluwarsa', -3], [$byTitle['Kontrak Lewat'][8], $byTitle['Kontrak Lewat'][9]]);
        $this->assertSame(['Aktif', 365], [$byTitle['Kontrak Jauh'][8], $byTitle['Kontrak Jauh'][9]]);
        $this->assertSame(['Tanpa Masa Tenggang', '', ''], [$byTitle['Akta Tetap'][8], $byTitle['Akta Tetap'][7], $byTitle['Akta Tetap'][9]]);
        $this->assertNotNull($expired);
        $this->assertNotNull($far);
        $this->assertNotNull($noExpiry);
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
