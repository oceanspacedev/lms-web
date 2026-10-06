<?php

namespace Tests\Feature;

use App\Filament\Resources\Documents\DocumentResource;
use App\Filament\Resources\Documents\Pages\CreateDocument;
use App\Filament\Resources\Documents\Pages\EditDocument;
use App\Filament\Resources\Documents\Pages\ListDocuments;
use App\Filament\Resources\Documents\Pages\ViewDocument;
use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\User;
use Database\Seeders\DocumentSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DocumentResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Storage::fake('s3');
        $this->seed(DocumentSeeder::class);
    }

    private function signInWithPermissions(array $permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);
        $this->actingAs($user);

        return $user;
    }

    private function documentData(): array
    {
        return [
            'company_id' => Company::factory()->create()->id,
            'document_type_id' => DocumentType::factory()->create()->id,
            'pic_user_id' => User::factory()->create()->id,
            'title' => 'Perjanjian Kemitraan',
            'document_number' => 'PKS-2026-001',
            'issued_date' => '2026-01-01',
            'expiry_date' => '2027-01-01',
        ];
    }

    public function test_filament_create_uploads_file_and_links_first_version(): void
    {
        $user = $this->signInWithPermissions(['ViewAny:Document', 'Create:Document']);
        Livewire::test(CreateDocument::class)
            ->fillForm([...$this->documentData(), 'file' => UploadedFile::fake()->create('kontrak.pdf', 64, 'application/pdf')])
            ->call('create')->assertHasNoFormErrors();
        $document = Document::sole();
        $this->assertSame($user->id, $document->currentVersion->uploaded_by);
        $this->assertSame(1, $document->currentVersion->version_number);
        Storage::disk('s3')->assertExists($document->currentVersion->file_path);
    }

    public function test_filament_requires_expiry_for_expiring_type_and_requires_file(): void
    {
        $this->signInWithPermissions(['ViewAny:Document', 'Create:Document']);
        Livewire::test(CreateDocument::class)
            ->fillForm([...$this->documentData(), 'expiry_date' => null])
            ->call('create')->assertHasFormErrors(['expiry_date' => 'required', 'file' => 'required']);
        $this->assertDatabaseCount('documents', 0);
    }

    public function test_existing_private_file_path_cannot_be_submitted_as_a_new_upload(): void
    {
        $this->signInWithPermissions(['ViewAny:Document', 'Create:Document']);
        Storage::fake('local');
        Storage::disk('local')->put('other-private.pdf', '%PDF-1.4 private');
        Livewire::test(CreateDocument::class)
            ->fillForm([...$this->documentData(), 'file' => ['other-private.pdf']])
            ->call('create')->assertHasFormErrors(['file']);
        $this->assertDatabaseCount('documents', 0);
        Storage::disk('local')->assertExists('other-private.pdf');
        $this->assertSame([], Storage::disk('s3')->allFiles());
    }

    public function test_inactive_master_data_is_rejected(): void
    {
        $this->signInWithPermissions(['ViewAny:Document', 'Create:Document']);
        $company = Company::factory()->inactive()->create();
        $type = DocumentType::factory()->inactive()->create();
        Livewire::test(CreateDocument::class)
            ->fillForm([...$this->documentData(), 'company_id' => $company->id, 'document_type_id' => $type->id,
                'file' => UploadedFile::fake()->create('test.pdf', 1, 'application/pdf')])
            ->call('create')->assertHasFormErrors(['company_id', 'document_type_id']);
        $this->assertDatabaseCount('documents', 0);
    }

    public function test_metadata_edit_does_not_modify_version_or_move_file(): void
    {
        $user = $this->signInWithPermissions(['ViewAny:Document', 'Update:Document']);
        $document = Document::archive($this->documentData(), UploadedFile::fake()->create('test.pdf', 1, 'application/pdf'), $user);
        $path = $document->currentVersion->file_path;
        Livewire::test(EditDocument::class, ['record' => $document->getRouteKey()])
            ->fillForm(['title' => 'Judul Baru', 'notes' => 'Catatan terbaru'])
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame('Judul Baru', $document->fresh()->title);
        $this->assertSame($path, $document->fresh()->currentVersion->file_path);
        $this->assertDatabaseCount('document_versions', 1);
    }

    public static function documentViews(): array
    {
        return ['table' => ['table'], 'grid' => ['grid']];
    }

    #[DataProvider('documentViews')]
    public function test_list_searches_title_and_number_and_filters_company_and_type(string $view): void
    {
        $user = $this->signInWithPermissions(['ViewAny:Document', 'View:Document']);
        $first = Document::archive($this->documentData(), UploadedFile::fake()->create('test.pdf', 1, 'application/pdf'), $user);
        $second = Document::archive([...$this->documentData(), 'title' => 'Akta Perusahaan', 'document_number' => 'AKTA-009'], UploadedFile::fake()->create('akta.pdf', 1, 'application/pdf'), $user);
        Livewire::test(ListDocuments::class)
            ->call('setDocumentView', $view)
            ->assertCanSeeTableRecords([$first, $second])
            ->searchTable('Kemitraan')->assertCanSeeTableRecords([$first])->assertCanNotSeeTableRecords([$second])
            ->searchTable('AKTA-009')->assertCanSeeTableRecords([$second])->assertCanNotSeeTableRecords([$first])
            ->searchTable('')->filterTable('company', $first->company_id)
            ->assertCanSeeTableRecords([$first])->assertCanNotSeeTableRecords([$second])
            ->filterTable('company', null)->filterTable('document_type', $second->document_type_id)
            ->assertCanSeeTableRecords([$second])->assertCanNotSeeTableRecords([$first]);
    }

    #[DataProvider('documentViews')]
    public function test_document_browser_filters_pic_and_file_format_and_searches_file_names(string $view): void
    {
        $user = $this->signInWithPermissions(['ViewAny:Document', 'View:Document']);
        $firstData = $this->documentData();
        $first = Document::archive($firstData, UploadedFile::fake()->create('kemitraan-final.pdf', 1, 'application/pdf'), $user);
        $second = Document::archive($this->documentData(), UploadedFile::fake()->create('akta.docx', 1, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'), $user);

        Livewire::test(ListDocuments::class)
            ->call('setDocumentView', $view)
            ->searchTable('kemitraan-final.pdf')
            ->assertCanSeeTableRecords([$first])->assertCanNotSeeTableRecords([$second])
            ->searchTable('')->filterTable('pic', $firstData['pic_user_id'])
            ->assertCanSeeTableRecords([$first])->assertCanNotSeeTableRecords([$second])
            ->filterTable('pic', null)->filterTable('file_format', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')
            ->assertCanSeeTableRecords([$second])->assertCanNotSeeTableRecords([$first])
            ->filterTable('file_format', 'application/pdf')
            ->assertCanSeeTableRecords([$first])->assertCanNotSeeTableRecords([$second]);
    }

    public function test_switching_views_preserves_search_filters_sort_and_pagination(): void
    {
        $this->signInWithPermissions(['ViewAny:Document', 'View:Document']);
        $data = $this->documentData();
        $documents = Document::factory()->count(13)->create([
            'company_id' => $data['company_id'],
            'title' => 'Arsip Kemitraan',
        ]);

        $component = Livewire::test(ListDocuments::class)
            ->searchTable('Kemitraan')->filterTable('company', $data['company_id'])
            ->sortTable('title')->set('tableRecordsPerPage', 5)->call('gotoPage', 2)
            ->callTableAction('gridView')
            ->assertSet('documentView', 'grid')->assertSet('tableSearch', 'Kemitraan')
            ->assertSeeHtml('class="document-grid"')->assertSeeHtml('class="document-card__menu"')
            ->assertDontSeeHtml('class="document-card__details"')->assertDontSee('Versi 1')
            ->assertCountTableRecords(13);

        $this->assertSame(2, $component->instance()->getTablePage());
        $this->assertSame('title', $component->instance()->getTableSortColumn());
        $this->assertSame((string) $data['company_id'], (string) $component->instance()->tableFilters['company']['value']);
        $this->assertCount(5, $component->instance()->getTableRecords());

        $component->callTableAction('tableView')->assertSet('documentView', 'table')
            ->assertDontSeeHtml('class="document-grid"')->assertCountTableRecords($documents->count());
        $this->assertSame(2, $component->instance()->getTablePage());
    }

    public function test_grid_respects_record_permissions_and_handles_empty_search_results(): void
    {
        $user = $this->signInWithPermissions(['ViewAny:Document']);
        $document = Document::archive($this->documentData(), UploadedFile::fake()->create('test.pdf', 1, 'application/pdf'), $user);

        Livewire::test(ListDocuments::class)->callTableAction('gridView')
            ->assertCanSeeTableRecords([$document])->assertSee('test.pdf')
            ->assertDontSeeHtml('href="'.DocumentResource::getUrl('view', ['record' => $document]).'"')
            ->assertDontSeeHtml('href="'.route('documents.download', $document).'"')
            ->searchTable('TidakAdaDokumenIni')->assertCanNotSeeTableRecords([$document])
            ->assertSee('Belum ada dokumen');
    }

    public function test_metadata_can_be_edited_after_linked_master_data_is_deactivated(): void
    {
        $user = $this->signInWithPermissions(['ViewAny:Document', 'Update:Document']);
        $document = Document::archive($this->documentData(), UploadedFile::fake()->create('test.pdf', 1, 'application/pdf'), $user);
        $document->company->update(['is_active' => false]);
        $document->documentType->update(['is_active' => false]);
        Livewire::test(EditDocument::class, ['record' => $document->getRouteKey()])
            ->fillForm(['title' => 'Judul Setelah Nonaktif'])->call('save')->assertHasNoFormErrors();
        $this->assertSame('Judul Setelah Nonaktif', $document->fresh()->title);
        $this->assertDatabaseCount('document_versions', 1);
    }

    public function test_viewer_can_view_but_cannot_create_or_edit(): void
    {
        $user = $this->signInWithPermissions(['ViewAny:Document', 'View:Document']);
        $document = Document::archive($this->documentData(), UploadedFile::fake()->create('test.pdf', 1, 'application/pdf'), $user);
        Livewire::test(ViewDocument::class, ['record' => $document->getRouteKey()])
            ->assertSuccessful()->assertSee('test.pdf')
            ->assertSee('Informasi Dokumen')->assertSee('File Aktif')
            ->assertSee($document->title)->assertSee('Tidak ada catatan');
        Livewire::test(CreateDocument::class)->assertForbidden();
        Livewire::test(EditDocument::class, ['record' => $document->getRouteKey()])->assertForbidden();
        $this->assertFalse(DocumentResource::canForceDelete($document));
    }

    public function test_user_without_permissions_cannot_access_documents(): void
    {
        $this->actingAs(User::factory()->create());
        $this->assertFalse(DocumentResource::canAccess());
        Livewire::test(ListDocuments::class)->assertForbidden();
        Livewire::test(CreateDocument::class)->assertForbidden();
    }
}
