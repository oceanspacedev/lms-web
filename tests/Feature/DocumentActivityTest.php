<?php

namespace Tests\Feature;

use App\Filament\Resources\Documents\Pages\EditDocument;
use App\Filament\Resources\Documents\Pages\ViewDocument;
use App\Filament\Resources\Documents\RelationManagers\ActivitiesRelationManager;
use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentActivity;
use App\Models\DocumentType;
use App\Models\User;
use Database\Seeders\DocumentSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class DocumentActivityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('s3');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->seed(DocumentSeeder::class);
    }

    private function document(User $uploader): Document
    {
        return Document::archive([
            'company_id' => Company::factory()->create()->id,
            'document_type_id' => DocumentType::factory()->withoutExpiry()->create()->id,
            'pic_user_id' => $uploader->id, 'title' => 'Audit Kontrak',
        ], UploadedFile::fake()->create('kontrak.pdf', 1, 'application/pdf'), $uploader);
    }

    public function test_upload_and_version_update_record_the_correct_actor_and_version(): void
    {
        $uploader = User::factory()->create();
        $document = $this->document($uploader);
        $editor = User::factory()->create();
        $version = $document->appendVersion(['change_note' => 'Revisi kontrak'], UploadedFile::fake()->create('revisi.pdf', 1, 'application/pdf'), $editor);
        $this->assertDatabaseHas('document_activities', ['event' => 'uploaded', 'document_id' => $document->id, 'user_id' => $uploader->id]);
        $this->assertDatabaseHas('document_activities', ['event' => 'version_updated', 'document_version_id' => $version->id, 'user_id' => $editor->id]);
        $this->assertDatabaseCount('document_activities', 2);
    }

    public function test_metadata_edit_records_actor_and_unchanged_save_does_not_add_activity(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['ViewAny:Document', 'View:Document', 'Update:Document']);
        $document = $this->document($user);
        $this->actingAs($user);
        Livewire::test(EditDocument::class, ['record' => $document->id])->fillForm(['title' => 'Judul Baru'])->call('save')->assertHasNoFormErrors();
        $this->assertDatabaseHas('document_activities', ['event' => 'metadata_updated', 'user_id' => $user->id]);
        Livewire::test(EditDocument::class, ['record' => $document->id])->call('save')->assertHasNoFormErrors();
        $this->assertDatabaseCount('document_activities', 2);
    }

    public function test_download_and_preview_record_actor_but_denied_and_missing_files_do_not(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['ViewAny:Document', 'View:Document']);
        $document = $this->document($user);
        Storage::disk('s3')->buildTemporaryUrlsUsing(fn (): string => 'https://storage.test/signed');
        $this->actingAs(User::factory()->create())->get(route('documents.download', $document))->assertForbidden();
        $this->assertDatabaseCount('document_activities', 1);
        $this->actingAs($user)->get(route('documents.download', $document))->assertRedirect();
        $this->get(route('documents.download', ['document' => $document, 'preview' => 1]))->assertRedirect();
        $this->assertDatabaseHas('document_activities', ['event' => 'downloaded', 'user_id' => $user->id, 'document_version_id' => $document->current_version_id]);
        $this->assertDatabaseHas('document_activities', ['event' => 'previewed', 'user_id' => $user->id]);
        Storage::disk('s3')->delete($document->currentVersion->file_path);
        $this->get(route('documents.download', $document))->assertNotFound();
        $this->assertDatabaseCount('document_activities', 3);
    }

    public function test_audit_failure_rolls_back_upload_and_cleans_up_file(): void
    {
        Event::listen('eloquent.creating: '.DocumentActivity::class, function (): void {
            throw new RuntimeException('Audit unavailable');
        });
        try {
            $this->document(User::factory()->create());
            $this->fail('Upload harus dibatalkan saat audit gagal.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        }
        $this->assertDatabaseCount('documents', 0);
        $this->assertDatabaseCount('document_versions', 0);
        $this->assertDatabaseCount('document_activities', 0);
        $this->assertSame([], Storage::disk('s3')->allFiles());
    }

    public function test_history_is_read_only_and_follows_document_permission(): void
    {
        $user = User::factory()->create();
        $document = $this->document($user);
        $this->actingAs($user);
        $this->assertFalse(ActivitiesRelationManager::canViewForRecord($document, ViewDocument::class));
        $user->givePermissionTo(['ViewAny:Document', 'View:Document']);
        Livewire::test(ActivitiesRelationManager::class, ['ownerRecord' => $document, 'pageClass' => ViewDocument::class])
            ->assertCanSeeTableRecords($document->activities)->assertSee('Dokumen diunggah');
        $this->assertTrue(ActivitiesRelationManager::canViewForRecord($document, ViewDocument::class));
        $this->expectException(LogicException::class);
        $document->activities->first()->update(['event' => 'downloaded']);
    }
}
