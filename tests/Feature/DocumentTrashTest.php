<?php

namespace Tests\Feature;

use App\Filament\Resources\Documents\DocumentResource;
use App\Filament\Resources\Documents\Pages\EditDocument;
use App\Filament\Resources\Documents\Pages\ListDocuments;
use App\Filament\Resources\Documents\Pages\ViewDocument;
use App\Filament\Resources\Documents\RelationManagers\ActivitiesRelationManager;
use App\Filament\Resources\Documents\RelationManagers\VersionsRelationManager;
use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\TrashLog;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class DocumentTrashTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 5)->setTime(15, 0));
        Storage::fake('s3');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->seed(PermissionSeeder::class);
    }

    /** @param list<string> $permissions */
    private function signInWith(array $permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);
        $this->actingAs($user);

        return $user;
    }

    /** Pulihkan hanya untuk super admin; peran super admin mendapat semua izin dari PermissionSeeder. */
    private function signInAsSuperAdmin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(config('filament-shield.super_admin.name'));
        $this->actingAs($user);

        return $user;
    }

    private function document(string $title = 'Kontrak Dihapus'): Document
    {
        return Document::archive([
            'company_id' => Company::factory()->create()->id,
            'document_type_id' => DocumentType::factory()->withoutExpiry()->create()->id,
            'pic_user_id' => User::factory()->create()->id,
            'title' => $title,
        ], UploadedFile::fake()->create('kontrak.pdf', 1, 'application/pdf'), User::factory()->create());
    }

    private function trashedDocument(string $title = 'Kontrak Dihapus'): Document
    {
        $document = $this->document($title);
        $document->delete();

        return $document;
    }

    public function test_deleting_a_document_hides_it_from_the_list_and_the_trashed_filter_shows_it(): void
    {
        $this->signInWith(['ViewAny:Document', 'View:Document', 'Update:Document', 'Delete:Document']);
        $document = $this->document();
        $other = $this->document('Dokumen Lain');

        Livewire::test(EditDocument::class, ['record' => $document->getRouteKey()])
            ->callAction('delete')->assertHasNoActionErrors();

        $this->assertSoftDeleted($document);
        $list = Livewire::test(ListDocuments::class)
            ->assertCanSeeTableRecords([$other])->assertCanNotSeeTableRecords([$document]);
        $this->assertNotContains($document->id, $list->instance()->getTableQueryForExport()->pluck('documents.id')->all());
        $list->assertTableFilterHidden('trashed');

        $this->signInAsSuperAdmin();
        Livewire::test(ListDocuments::class)
            ->filterTable('trashed', false)->assertCanSeeTableRecords([$document])->assertCanNotSeeTableRecords([$other])
            ->filterTable('trashed', true)->assertCanSeeTableRecords([$document, $other])
            ->filterTable('trashed', null)->assertCanSeeTableRecords([$other])->assertCanNotSeeTableRecords([$document]);
    }

    public function test_delete_confirmation_explains_what_happens_to_the_document(): void
    {
        $this->signInWith(['ViewAny:Document', 'View:Document', 'Update:Document', 'Delete:Document']);
        $document = $this->document();

        Livewire::test(EditDocument::class, ['record' => $document->getRouteKey()])
            ->mountAction('delete')
            ->assertMountedActionModalSee([
                'Hapus dokumen ini?',
                'Data dipindahkan ke tempat sampah dan hilang dari daftar. Super admin bisa memulihkannya atau menghapusnya permanen.',
                'Pengingat masa berlakunya berhenti dan file tidak ikut dihapus.',
                'Ya, hapus',
            ])
            ->assertMountedActionModalDontSee('izin memulihkan dokumen');
        $this->assertNotSoftDeleted($document);
    }

    public function test_restoring_from_the_list_brings_the_document_back_and_its_detail_page_works(): void
    {
        $this->signInAsSuperAdmin();
        $document = $this->trashedDocument();

        Livewire::test(ListDocuments::class)->assertCanNotSeeTableRecords([$document])
            ->filterTable('trashed', false)->assertCanSeeTableRecords([$document])
            ->callTableAction('restore', $document)->assertHasNoTableActionErrors()
            ->filterTable('trashed', null)->assertCanSeeTableRecords([$document]);

        $this->assertNotSoftDeleted($document);
        Livewire::test(ViewDocument::class, ['record' => $document->getRouteKey()])
            ->assertSuccessful()->assertDontSee('Dokumen ini sudah dihapus')
            ->assertActionVisible('download')->assertActionVisible('preview')->assertActionHidden('restore');
        $this->get(DocumentResource::getUrl('view', ['record' => $document]))->assertOk()->assertSee($document->title);
    }

    public function test_trashed_rows_offer_only_view_restore_and_force_delete(): void
    {
        $this->signInAsSuperAdmin();
        $trashed = $this->trashedDocument();
        $active = $this->document('Dokumen Aktif');

        Livewire::test(ListDocuments::class)->filterTable('trashed', true)
            ->assertCanSeeTableRecords([$trashed, $active])
            ->assertTableActionVisible('view', $trashed)->assertTableActionVisible('restore', $trashed)->assertTableActionVisible('forceDelete', $trashed)
            ->assertTableActionHidden('edit', $trashed)->assertTableActionHidden('download', $trashed)->assertTableActionHidden('delete', $trashed)
            ->assertTableActionVisible('view', $active)->assertTableActionVisible('edit', $active)->assertTableActionVisible('delete', $active)
            ->assertTableActionVisible('download', $active)->assertTableActionHidden('restore', $active)->assertTableActionHidden('forceDelete', $active);
        $this->assertTrue(DocumentResource::canForceDelete($trashed));
    }

    public function test_grid_marks_trashed_documents_as_deleted(): void
    {
        $this->signInAsSuperAdmin();
        $trashed = $this->trashedDocument();

        Livewire::test(ListDocuments::class)->call('setDocumentView', 'grid')->filterTable('trashed', false)
            ->assertCanSeeTableRecords([$trashed])->assertSee('Dihapus');
    }

    public function test_trashed_document_detail_page_opens_with_notice_and_only_view_safe_actions(): void
    {
        $this->signInAsSuperAdmin();
        $document = $this->trashedDocument();

        Livewire::test(ViewDocument::class, ['record' => $document->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('Dokumen ini sudah dihapus')->assertSee('Dihapus pada 05/10/2026 22:00 WIB')
            ->assertSee('Pulihkan dokumen untuk memakainya kembali')
            ->assertActionVisible('restore')->assertActionVisible('forceDelete')
            ->assertActionHidden('download')->assertActionHidden('preview')->assertActionHidden('edit')
            ->assertActionHidden('renew')->assertActionHidden('updateVersion')->assertActionHidden('delete');
        $this->get(DocumentResource::getUrl('view', ['record' => $document]))
            ->assertOk()->assertSee('Dokumen ini sudah dihapus')->assertSee('Pulihkan');
    }

    public function test_restoring_from_the_detail_page_clears_the_notice_and_returns_the_actions(): void
    {
        $this->signInAsSuperAdmin();
        $document = $this->trashedDocument();

        Livewire::test(ViewDocument::class, ['record' => $document->getRouteKey()])
            ->assertSee('Dokumen ini sudah dihapus')
            ->callAction('restore')->assertHasNoActionErrors()
            ->assertDontSee('Dokumen ini sudah dihapus')
            ->assertActionHidden('restore')->assertActionVisible('download')->assertActionVisible('preview')->assertActionVisible('edit');
        $this->assertNotSoftDeleted($document);
    }

    public function test_trashed_document_cannot_be_edited_until_restored(): void
    {
        $this->signInAsSuperAdmin();
        $document = $this->trashedDocument();

        Livewire::test(EditDocument::class, ['record' => $document->getRouteKey()])->assertForbidden();
        $this->assertFalse(DocumentResource::canEdit($document));

        $document->restore();
        $this->assertTrue(DocumentResource::canEdit($document));
        Livewire::test(EditDocument::class, ['record' => $document->getRouteKey()])->assertSuccessful();
    }

    public function test_only_super_admin_can_restore_even_with_the_restore_permission(): void
    {
        $this->signInWith(['ViewAny:Document', 'View:Document', 'Update:Document', 'Delete:Document', 'Restore:Document', 'RestoreAny:Document']);
        $document = $this->trashedDocument();

        $this->assertFalse(DocumentResource::canRestore($document));
        Livewire::test(ListDocuments::class)->assertTableFilterHidden('trashed')
            ->set('tableFilters.trashed.value', '0')->assertCanNotSeeTableRecords([$document]);
        $this->get(DocumentResource::getUrl('view', ['record' => $document]))->assertNotFound();
        $this->assertSoftDeleted($document);
    }

    public function test_trashed_document_cannot_be_downloaded_until_restored(): void
    {
        $this->signInAsSuperAdmin();
        $document = $this->document();
        Storage::disk('s3')->buildTemporaryUrlsUsing(fn (): string => 'https://storage.test/signed');
        $this->get(route('documents.download', $document))->assertRedirect();

        $document->delete();
        $this->get(route('documents.download', $document))->assertNotFound();
        $this->get(route('documents.download', ['document' => $document, 'preview' => 1]))->assertNotFound();

        $document->restore();
        $this->get(route('documents.download', $document))->assertRedirect('https://storage.test/signed');
    }

    public function test_deleting_and_restoring_are_recorded_in_the_activity_history(): void
    {
        $user = $this->signInAsSuperAdmin();
        $document = $this->document();

        Livewire::test(EditDocument::class, ['record' => $document->getRouteKey()])->callAction('delete')->assertHasNoActionErrors();
        $this->assertDatabaseHas('document_activities', ['document_id' => $document->id, 'event' => 'deleted', 'user_id' => $user->id, 'document_version_id' => $document->current_version_id]);

        Livewire::test(ListDocuments::class)->filterTable('trashed', false)->callTableAction('restore', $document)->assertHasNoTableActionErrors();
        $this->assertDatabaseHas('document_activities', ['document_id' => $document->id, 'event' => 'restored', 'user_id' => $user->id]);

        $document->refresh();
        Livewire::test(EditDocument::class, ['record' => $document->getRouteKey()])->callAction('delete');
        Livewire::test(ViewDocument::class, ['record' => $document->getRouteKey()])->callAction('restore')->assertHasNoActionErrors();
        $this->assertSame(['uploaded', 'deleted', 'restored', 'deleted', 'restored'], $document->activities()->orderBy('id')->pluck('event')->all());
        $this->assertSame(['deleted', 'restored', 'deleted', 'restored'], TrashLog::where('subject_type', 'document')->where('subject_id', $document->id)->where('user_id', $user->id)->orderBy('id')->pluck('action')->all());

        Livewire::test(ActivitiesRelationManager::class, ['ownerRecord' => $document->fresh(), 'pageClass' => ViewDocument::class])
            ->assertSee('Dokumen dihapus')->assertSee('Dokumen dipulihkan');
    }

    public function test_restoring_from_the_detail_page_refreshes_the_version_history(): void
    {
        $this->signInAsSuperAdmin();
        $document = $this->trashedDocument();
        $version = $document->currentVersion;

        Livewire::test(ViewDocument::class, ['record' => $document->getRouteKey()])
            ->callAction('restore')->assertDispatched('document-version-updated');

        Livewire::test(VersionsRelationManager::class, ['ownerRecord' => Document::withTrashed()->find($document->id)->forceFill(['deleted_at' => now()]), 'pageClass' => ViewDocument::class])
            ->assertTableActionHidden('download', $version)
            ->dispatch('document-version-updated')
            ->assertTableActionVisible('download', $version)->assertDontSee('Dokumen sudah dihapus');
    }

    public function test_version_history_does_not_offer_downloads_for_a_trashed_document(): void
    {
        $this->signInAsSuperAdmin();
        $document = $this->trashedDocument();
        $version = $document->currentVersion;

        Livewire::test(VersionsRelationManager::class, ['ownerRecord' => $document, 'pageClass' => ViewDocument::class])
            ->assertCanSeeTableRecords([$version])->assertTableActionHidden('download', $version)
            ->assertSee('Dokumen sudah dihapus, jadi file belum dapat diunduh.');

        $document->restore();
        Livewire::test(VersionsRelationManager::class, ['ownerRecord' => $document->fresh(), 'pageClass' => ViewDocument::class])
            ->assertTableActionVisible('download', $version);
    }
}
