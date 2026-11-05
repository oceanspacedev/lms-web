<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentRequest;
use App\Models\DocumentType;
use App\Models\DocumentVersion;
use App\Models\ReminderLog;
use App\Models\ReminderTemplate;
use App\Models\TrashLog;
use App\Models\User;
use App\Services\Trash\TrashManager;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DocumentForceDeleteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 6)->setTime(1, 0));
        Storage::fake('s3');
    }

    private function manager(): TrashManager
    {
        return app(TrashManager::class);
    }

    /** Dokumen dengan dua versi, masing-masing dengan file di S3. */
    private function documentWithTwoVersions(?Company $company = null, string $title = 'Kontrak Salah Unggah'): Document
    {
        $uploader = User::factory()->create();
        $document = Document::archive([
            'company_id' => ($company ?? Company::factory()->create())->id,
            'document_type_id' => DocumentType::factory()->withoutExpiry()->create()->id,
            'pic_user_id' => User::factory()->create()->id,
            'title' => $title,
        ], UploadedFile::fake()->create('kontrak.pdf', 1, 'application/pdf'), $uploader);
        $document->appendVersion(['change_note' => 'Revisi'], UploadedFile::fake()->create('kontrak-revisi.pdf', 1, 'application/pdf'), $uploader);

        return $document->refresh();
    }

    /** @return list<string> */
    private function filePaths(Document $document): array
    {
        return DocumentVersion::where('document_id', $document->id)->orderBy('id')->pluck('file_path')->all();
    }

    private function completedRequestFor(Document $document): DocumentRequest
    {
        $request = DocumentRequest::factory()->create();
        $request->forceFill(['document_id' => $document->id, 'status' => 'archived'])->save();

        return $request;
    }

    private function forceDeleteError(Document $document, User $actor): string
    {
        try {
            $this->manager()->forceDelete($document, $actor);
        } catch (ValidationException $exception) {
            return collect($exception->errors())->flatten()->first();
        }

        $this->fail('Hapus permanen seharusnya ditolak.');
    }

    public function test_force_delete_removes_versions_activities_reminder_logs_and_s3_files(): void
    {
        $actor = User::factory()->create();
        $document = $this->documentWithTwoVersions();
        $versionIds = $document->versions()->pluck('id')->all();
        foreach ($versionIds as $versionId) {
            ReminderLog::factory()->create(['document_version_id' => $versionId]);
        }
        $paths = $this->filePaths($document);
        $this->assertCount(2, $paths);
        Storage::disk('s3')->assertExists($paths);
        $this->manager()->trash($document, $actor);

        $this->manager()->forceDelete($document, $actor);

        $this->assertDatabaseMissing('documents', ['id' => $document->id]);
        $this->assertDatabaseMissing('document_versions', ['document_id' => $document->id]);
        $this->assertDatabaseMissing('document_activities', ['document_id' => $document->id]);
        $this->assertSame(0, ReminderLog::whereIn('document_version_id', $versionIds)->count());
        foreach ($paths as $path) {
            Storage::disk('s3')->assertMissing($path);
        }
        $log = TrashLog::where('action', 'force_deleted')->sole();
        $this->assertSame(['document', $document->id, 'Kontrak Salah Unggah', $actor->id, null], [$log->subject_type, $log->subject_id, $log->subject_label, $log->user_id, $log->details]);
    }

    public function test_trashing_does_not_touch_files(): void
    {
        $actor = User::factory()->create();
        $document = $this->documentWithTwoVersions();
        $paths = $this->filePaths($document);

        $this->manager()->trash($document, $actor);

        $this->assertSoftDeleted($document);
        Storage::disk('s3')->assertExists($paths);
        $this->assertSame(2, DocumentVersion::where('document_id', $document->id)->count());
        $this->assertDatabaseHas('document_activities', ['document_id' => $document->id, 'event' => 'deleted', 'user_id' => $actor->id, 'document_version_id' => $document->current_version_id]);
        $this->assertDatabaseHas('trash_logs', ['subject_type' => 'document', 'subject_id' => $document->id, 'action' => 'deleted', 'user_id' => $actor->id]);
    }

    public function test_force_delete_blocked_while_active_request_points_to_document(): void
    {
        $actor = User::factory()->create();
        $document = $this->documentWithTwoVersions();
        $request = $this->completedRequestFor($document);

        $this->assertSame(["Dokumen ini hasil pengajuan #{$request->id}."], $this->manager()->usage($document)->notes);
        $this->manager()->trash($document, $actor);

        $this->assertSame(
            "Tidak bisa dihapus permanen: masih dipakai 1 pengajuan (#{$request->id}). Hapus atau pindahkan data itu dulu.",
            $this->forceDeleteError($document, $actor),
        );
        $this->assertSoftDeleted($document);
        $this->assertSame(2, DocumentVersion::where('document_id', $document->id)->count());
        Storage::disk('s3')->assertExists($this->filePaths($document));
        $this->assertDatabaseMissing('trash_logs', ['action' => 'force_deleted']);
    }

    public function test_force_delete_when_file_removal_fails_still_removes_rows_and_logs_left_files(): void
    {
        $actor = User::factory()->create();
        $document = $this->documentWithTwoVersions();
        $paths = $this->filePaths($document);
        $this->manager()->trash($document, $actor);
        $fake = Storage::disk('s3');
        Storage::set('s3', new class($fake->getDriver(), $fake->getAdapter(), $fake->getConfig()) extends FilesystemAdapter
        {
            public function delete($paths): bool
            {
                return false;
            }
        });

        $this->manager()->forceDelete($document, $actor);

        $this->assertDatabaseMissing('documents', ['id' => $document->id]);
        $this->assertDatabaseMissing('document_versions', ['document_id' => $document->id]);
        $this->assertDatabaseMissing('document_activities', ['document_id' => $document->id]);
        $this->assertSame(
            array_map(fn (string $path): array => ['disk' => 's3', 'path' => $path], $paths),
            TrashLog::where('action', 'force_deleted')->sole()->details['left_files'],
        );
        Storage::disk('s3')->assertExists($paths);
    }

    public function test_company_force_delete_cascades_its_trashed_documents_with_files(): void
    {
        $actor = User::factory()->create();
        $company = Company::factory()->create(['name' => 'PT Induk']);
        $document = $this->documentWithTwoVersions($company, 'Kontrak Lama');
        $paths = $this->filePaths($document);
        $this->manager()->trash($document, $actor);
        $this->manager()->trash($company, $actor);

        $this->manager()->forceDelete($company, $actor);

        $this->assertDatabaseMissing('companies', ['id' => $company->id]);
        $this->assertDatabaseMissing('documents', ['id' => $document->id]);
        $this->assertDatabaseMissing('document_versions', ['document_id' => $document->id]);
        foreach ($paths as $path) {
            Storage::disk('s3')->assertMissing($path);
        }
        $logs = TrashLog::where('action', 'force_deleted')->orderBy('id')->get()
            ->map(fn (TrashLog $log): array => [$log->subject_type, $log->subject_id, $log->details['via']['id'] ?? null])->all();
        $this->assertSame([['document', $document->id, $company->id], ['company', $company->id, null]], $logs);
    }

    public function test_restore_reopens_reminders_like_before(): void
    {
        ReminderTemplate::factory()->create(['schedule_mode' => 'specific_days', 'scheduled_days' => [30, 7], 'body' => ReminderTemplate::DEFAULT_BODY]);
        $actor = User::factory()->create();
        $document = Document::factory()->create(['document_type_id' => DocumentType::factory()->create(['has_expiry' => true])->id]);
        $version = DocumentVersion::factory()->create(['document_id' => $document->id, 'expiry_date' => today('Asia/Jakarta')->addDays(30)]);
        $document->forceFill(['current_version_id' => $version->id])->save();
        $this->manager()->trash($document->refresh(), $actor);
        $log = ReminderLog::factory()->create([
            'document_version_id' => $version->id, 'offset_days' => 30, 'status' => 'cancelled', 'attempts' => 1, 'sent_at' => null,
            'error_message' => ReminderLog::DOCUMENT_DELETED_MESSAGE,
        ]);

        $this->manager()->restore($document, $actor);

        $this->assertNotSoftDeleted($document);
        $this->assertSame(['pending', 0, null], [$log->fresh()->status, $log->fresh()->attempts, $log->fresh()->error_message]);
        $this->assertDatabaseHas('document_activities', ['document_id' => $document->id, 'event' => 'restored', 'user_id' => $actor->id]);
        $this->assertDatabaseHas('trash_logs', ['subject_type' => 'document', 'subject_id' => $document->id, 'action' => 'restored', 'user_id' => $actor->id]);
    }
}
