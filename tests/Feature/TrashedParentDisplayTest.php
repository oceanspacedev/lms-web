<?php

namespace Tests\Feature;

use App\Filament\Resources\Companies\Pages\CreateCompany;
use App\Filament\Resources\DocumentRequests\Pages\EditDocumentRequest;
use App\Filament\Resources\Documents\Pages\CreateDocument;
use App\Filament\Resources\Documents\Pages\EditDocument;
use App\Filament\Resources\Documents\Pages\ListDocuments;
use App\Filament\Resources\Documents\Pages\ViewDocument;
use App\Filament\Resources\DocumentTypes\Pages\CreateDocumentType;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentRequest;
use App\Models\DocumentType;
use App\Models\DocumentVersion;
use App\Models\ReminderTemplate;
use App\Models\User;
use App\Services\DocumentImporter;
use App\Services\DocumentImportTemplate;
use App\Services\DocumentSpreadsheetExporter;
use App\Support\ActiveReference;
use Database\Seeders\PermissionSeeder;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;
use ZipArchive;

/**
 * Induk (badan usaha, jenis dokumen, user) yang masuk tempat sampah tetap tampil di data lama dengan tanda "(dihapus)",
 * nilai lama tetap boleh disimpan, tetapi induk itu tidak muncul di pilihan baru.
 */
class TrashedParentDisplayTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 12)->setTime(8, 0));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Storage::fake('local');
        Storage::fake('s3');
        $this->seed(PermissionSeeder::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    /** @param list<string> $permissions */
    private function signIn(array $permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);
        $this->actingAs($user);

        return $user;
    }

    private function documentWithTrashedCompany(string $name = 'PT Terhapus'): Document
    {
        $company = Company::factory()->create(['name' => $name]);
        $document = Document::factory()->create(['company_id' => $company->id, 'title' => 'Kontrak Lama']);
        $company->delete();

        return $document;
    }

    private function runningRequest(array $attributes = []): DocumentRequest
    {
        $legal = User::factory()->create();
        $legal->givePermissionTo(['ViewAny:DocumentRequest', 'View:DocumentRequest', 'Review:DocumentRequest', 'Create:Document']);
        $this->actingAs($legal);

        return DocumentRequest::factory()->handover(2)->create([
            'reviewer_id' => $legal->id, 'company_id' => Company::factory()->create(['name' => 'PT Arsip Berjalan', 'legal_form' => 'PT'])->id,
            'document_type_id' => DocumentType::factory()->withoutExpiry()->create()->id,
            'history' => [['status' => 'submitted', 'user' => 'Pengaju', 'user_id' => null, 'at' => now()->toIso8601String(), 'note' => null]],
            ...$attributes,
        ]);
    }

    private function receive(DocumentRequest $request): Document
    {
        Storage::disk('local')->put($request->signed_path, '%PDF-1.4 scan');

        return $request->markReceived('PKS/2026/900', null, null, auth()->user());
    }

    public function test_document_with_trashed_company_still_shows_company_name_with_marker(): void
    {
        $this->signIn(['ViewAny:Document', 'View:Document']);
        $document = $this->documentWithTrashedCompany();

        Livewire::test(ViewDocument::class, ['record' => $document->getRouteKey()])->assertSee('PT Terhapus (dihapus)');
        Livewire::test(ListDocuments::class)->assertSee('PT Terhapus (dihapus)');
        $this->assertSame('PT Terhapus (dihapus)', $document->fresh()->company->displayName());
    }

    public function test_active_document_with_trashed_company_saves_without_changing_company(): void
    {
        $this->signIn(['ViewAny:Document', 'Update:Document']);
        $document = $this->documentWithTrashedCompany();
        $document->documentType->update(['is_active' => false]);
        $companyId = $document->company_id;

        Livewire::test(EditDocument::class, ['record' => $document->getRouteKey()])
            ->assertSchemaStateSet(['company_id' => $companyId])
            ->fillForm(['title' => 'Judul Baru'])->call('save')->assertHasNoFormErrors();

        $this->assertSame('Judul Baru', $document->fresh()->title);
        $this->assertSame($companyId, $document->fresh()->company_id);
    }

    public function test_trashed_company_is_not_offered_for_new_documents_or_public_form(): void
    {
        $this->signIn(['ViewAny:Document', 'Create:Document']);
        $trashed = Company::factory()->create(['name' => 'PT Sudah Dibuang']);
        $active = Company::factory()->create(['name' => 'PT Masih Dipakai']);
        $trashed->delete();
        DocumentType::factory()->create();

        Livewire::test(CreateDocument::class)->assertFormFieldExists('company_id', function (Select $field) use ($trashed, $active): bool {
            $options = $field->getOptions();

            return ! array_key_exists($trashed->id, $options) && array_key_exists($active->id, $options);
        });
        $this->get(route('requests.public.create'))->assertOk()->assertSee('PT Masih Dipakai')->assertDontSee('PT Sudah Dibuang');
    }

    public function test_trashed_company_cannot_be_chosen_for_a_new_document(): void
    {
        $this->signIn(['ViewAny:Document', 'Create:Document']);
        $company = Company::factory()->create();
        $company->delete();

        Livewire::test(CreateDocument::class)
            ->fillForm([
                'company_id' => $company->id, 'document_type_id' => DocumentType::factory()->withoutExpiry()->create()->id,
                'pic_user_id' => User::factory()->create()->id, 'title' => 'Dokumen Baru',
                'file' => UploadedFile::fake()->create('baru.pdf', 1, 'application/pdf'),
            ])->call('create')->assertHasFormErrors(['company_id']);
        $this->assertDatabaseCount('documents', 0);
    }

    public function test_running_request_with_trashed_company_can_still_be_received(): void
    {
        $request = $this->runningRequest();
        $companyId = $request->company_id;
        $request->company->delete();

        $document = $this->receive($request->fresh());

        $this->assertSame($companyId, $document->company_id);
        $this->assertSame('archived', $request->fresh()->status);
        $this->assertSame($document->id, $request->fresh()->document_id);
    }

    public function test_running_request_with_inactive_company_can_still_be_received(): void
    {
        $request = $this->runningRequest();
        $companyId = $request->company_id;
        $request->company->update(['is_active' => false]);

        $document = $this->receive($request->fresh());

        $this->assertSame($companyId, $document->company_id);
        $this->assertSame('archived', $request->fresh()->status);
    }

    public function test_running_request_with_trashed_document_type_can_still_be_received(): void
    {
        $request = $this->runningRequest();
        $typeId = $request->document_type_id;
        $request->documentType->delete();

        $document = $this->receive($request->fresh());

        $this->assertSame($typeId, $document->document_type_id);
    }

    public function test_request_screen_still_shows_trashed_parents(): void
    {
        $request = $this->runningRequest();
        $request->company->delete();
        $request->documentType->update(['name' => 'Perjanjian Usang']);
        $request->documentType->delete();

        Livewire::test(EditDocumentRequest::class, ['record' => $request->getRouteKey()])
            ->assertSee('PT Arsip Berjalan (dihapus)')->assertSee('Perjanjian Usang (dihapus)');
    }

    public function test_new_company_with_trashed_name_is_rejected_with_trash_message(): void
    {
        $this->signIn(['ViewAny:Company', 'Create:Company']);
        Company::factory()->create(['name' => 'PT Kembar'])->delete();

        Livewire::test(CreateCompany::class)
            ->fillForm(['name' => 'PT Kembar', 'legal_form' => 'PT'])->call('create')
            ->assertHasFormErrors(['name'])
            ->assertSee('Nama ini dipakai badan usaha di tempat sampah. Pulihkan data itu (super admin) atau pakai nama lain.');
        $this->assertDatabaseCount('companies', 1);
    }

    public function test_new_document_type_with_trashed_name_is_rejected_with_trash_message(): void
    {
        $this->signIn(['ViewAny:DocumentType', 'Create:DocumentType']);
        DocumentType::factory()->create(['name' => 'Jenis Kembar'])->delete();

        Livewire::test(CreateDocumentType::class)
            ->fillForm(['name' => 'Jenis Kembar'])->call('create')
            ->assertHasFormErrors(['name'])
            ->assertSee('Nama ini dipakai jenis dokumen di tempat sampah. Pulihkan data itu (super admin) atau pakai nama lain.');
    }

    public function test_new_user_with_trashed_email_is_rejected_with_trash_message(): void
    {
        $this->signIn(['ViewAny:User', 'Create:User']);
        User::factory()->create(['email' => 'lama@contoh.test'])->delete();

        Livewire::test(CreateUser::class)
            ->fillForm(['name' => 'Orang Baru', 'email' => 'lama@contoh.test', 'password' => 'rahasia-panjang-123'])->call('create')
            ->assertHasFormErrors(['email'])
            ->assertSee('Email ini dipakai user di tempat sampah. Pulihkan user itu (super admin) atau pakai email lain.');
    }

    public function test_export_and_archive_file_name_keep_trashed_company_name(): void
    {
        $document = $this->documentWithTrashedCompany('PT Nama Arsip');
        $version = DocumentVersion::factory()->create(['document_id' => $document->id]);
        $document->update(['current_version_id' => $version->id]);
        $path = $this->tempPath();

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
        $this->assertSame('PT Nama Arsip (dihapus)', $rows[1][2]);

        $request = $this->runningRequest();
        $request->company->update(['name' => 'Maju Bersama', 'legal_form' => 'CV']);
        $request->company->delete();
        $archived = $this->receive($request->fresh());

        $this->assertStringContainsString('cv-maju-bersama_', $archived->currentVersion->file_path);
    }

    public function test_import_row_with_trashed_company_name_fails_with_clear_message(): void
    {
        ReminderTemplate::factory()->create(['schedule_mode' => 'specific_days', 'scheduled_days' => [30], 'overdue_days' => [1]]);
        $user = $this->signIn(['ViewAny:Document', 'View:Document', 'Create:Document']);
        $pic = User::factory()->create(['email' => 'pic@contoh.test']);
        $type = DocumentType::factory()->withoutExpiry()->create(['name' => 'Akta Impor']);
        Company::factory()->create(['name' => 'Impor Terhapus', 'legal_form' => 'PT'])->delete();
        $row = ['Sewa Gudang', 'SEWA-1', 'PT Impor Terhapus', 'Akta Impor', 'CV Mitra', 'pic@contoh.test', '', '', '', 'sewa.pdf'];

        $import = app(DocumentImporter::class)->check($this->xlsx([$row]), 'arsip.xlsx', [['path' => $this->zip(['sewa.pdf' => "%PDF-1.4\n%%EOF\n"]), 'name' => 'dokumen.zip']], $user);

        $line = collect($import->rows)->first();
        $this->assertSame('error', $line['status']);
        $this->assertStringContainsString('Badan Usaha "PT Impor Terhapus" tidak ditemukan', implode(' ', $line['messages']));
        $this->assertNotNull($pic->id.$type->id);
    }

    public function test_document_table_company_filter_does_not_offer_trashed_company(): void
    {
        $this->signIn(['ViewAny:Document', 'View:Document']);
        $document = $this->documentWithTrashedCompany('PT Filter Terhapus');
        $active = Document::factory()->create(['company_id' => Company::factory()->create(['name' => 'PT Filter Aktif'])->id]);

        $fields = Livewire::test(ListDocuments::class)->instance()->getTableFiltersForm()->getFlatFields(withAbsoluteKeys: true);
        $options = $fields['tableFiltersForm.company.value']->getOptions();

        $this->assertArrayHasKey($active->company_id, $options);
        $this->assertArrayNotHasKey($document->company_id, $options);
    }

    public function test_active_reference_rule_accepts_only_active_values_or_the_kept_one(): void
    {
        $active = Company::factory()->create();
        $inactive = Company::factory()->inactive()->create();
        $trashed = Company::factory()->create();
        $trashed->delete();
        $passes = fn (int $id, ?int $keep): bool => Validator::make(['company_id' => $id], ['company_id' => [ActiveReference::rule('companies', $keep, ['is_active' => true])]])->passes();

        $this->assertTrue($passes($active->id, null));
        $this->assertFalse($passes($inactive->id, null));
        $this->assertFalse($passes($trashed->id, null));
        $this->assertFalse($passes(999999, null));
        $this->assertTrue($passes($inactive->id, $inactive->id));
        $this->assertTrue($passes($trashed->id, $trashed->id));
        $this->assertFalse($passes($trashed->id, $inactive->id));
        $this->assertSame([$active->id => $active->name, $trashed->id => $trashed->name.' (dihapus)'], ActiveReference::options(Company::where('is_active', true), $trashed->id, fn (Company $company): string => $company->name));
    }

    public function test_active_reference_unique_rule_distinguishes_trashed_and_active_conflicts(): void
    {
        $active = Company::factory()->create(['name' => 'PT Aktif']);
        Company::factory()->create(['name' => 'PT Sampah'])->delete();
        $errors = fn (string $name, ?int $ignore = null): array => Validator::make(['name' => $name], ['name' => [ActiveReference::uniqueRule(Company::class, 'name', $ignore, 'Pesan sampah.')]])->errors()->get('name');

        $this->assertSame(['Pesan sampah.'], $errors('PT Sampah'));
        $this->assertSame(['Name sudah dipakai.'], $errors('PT Aktif'));
        $this->assertSame([], $errors('PT Aktif', $active->id));
        $this->assertSame([], $errors('PT Baru'));
    }

    private function tempPath(): string
    {
        return $this->tempFiles[] = tempnam(sys_get_temp_dir(), 'lms-trashed-');
    }

    /** @param list<list<string>> $rows */
    private function xlsx(array $rows): string
    {
        $path = $this->tempPath();
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('Dokumen');
        $writer->addRow(Row::fromValues(DocumentImportTemplate::headers()));
        foreach ($rows as $row) {
            $writer->addRow(new Row(array_map(fn (string $value): Cell => Cell::fromValue($value), $row)));
        }
        $writer->close();

        return $path;
    }

    /** @param array<string, string> $files */
    private function zip(array $files): string
    {
        $path = $this->tempPath();
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return $path;
    }
}
