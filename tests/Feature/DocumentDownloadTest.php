<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\User;
use Database\Seeders\DocumentSeeder;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentDownloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('s3');
        $this->seed(DocumentSeeder::class);
    }

    private function document(string $extension = 'pdf', string $mime = 'application/pdf'): Document
    {
        return Document::archive([
            'company_id' => Company::factory()->create()->id,
            'document_type_id' => DocumentType::factory()->withoutExpiry()->create()->id,
            'pic_user_id' => User::factory()->create()->id,
            'title' => 'Dokumen Unduhan',
        ], UploadedFile::fake()->create('dokumen.'.$extension, 1, $mime), User::factory()->create());
    }

    private function signInAsViewer(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['ViewAny:Document', 'View:Document']);
        $this->actingAs($user);
    }

    public function test_authorized_download_uses_temporary_url_and_attachment_headers(): void
    {
        $document = $this->document();
        $this->signInAsViewer();
        $this->freezeTime();
        $captured = null;
        Storage::disk('s3')->buildTemporaryUrlsUsing(function (string $path, DateTimeInterface $expiration, array $options) use (&$captured): string {
            $captured = compact('path', 'expiration', 'options');

            return 'https://storage.test/lms/file.pdf?signature=temporary';
        });

        $this->get(route('documents.download', $document))
            ->assertRedirect('https://storage.test/lms/file.pdf?signature=temporary')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame($document->currentVersion->file_path, $captured['path']);
        $this->assertSame(now()->addMinutes(5)->getTimestamp(), $captured['expiration']->getTimestamp());
        $this->assertSame('attachment; filename=dokumen.pdf', $captured['options']['ResponseContentDisposition']);
        $this->assertSame('application/pdf', $captured['options']['ResponseContentType']);
    }

    public function test_preview_is_inline_for_pdf_and_attachment_for_word(): void
    {
        $pdf = $this->document();
        $word = $this->document('doc', 'application/msword');
        $this->signInAsViewer();
        $dispositions = [];
        Storage::disk('s3')->buildTemporaryUrlsUsing(function (string $path, DateTimeInterface $expiration, array $options) use (&$dispositions): string {
            $dispositions[] = $options['ResponseContentDisposition'];

            return 'https://storage.test/signed';
        });
        $this->get(route('documents.download', ['document' => $pdf, 'preview' => 1]))->assertRedirect();
        $this->get(route('documents.download', ['document' => $word, 'preview' => 1]))->assertRedirect();
        $this->assertStringStartsWith('inline;', $dispositions[0]);
        $this->assertStringStartsWith('attachment;', $dispositions[1]);
    }

    public function test_guest_and_unpermitted_user_cannot_download(): void
    {
        $document = $this->document();
        $this->get(route('documents.download', $document))->assertRedirect(route('filament.admin.auth.login'));
        $this->actingAs(User::factory()->create());
        $this->get(route('documents.download', $document))->assertForbidden();
    }

    public function test_missing_file_and_soft_deleted_document_return_not_found(): void
    {
        $document = $this->document();
        $this->signInAsViewer();
        Storage::disk('s3')->delete($document->currentVersion->file_path);
        $this->get(route('documents.download', $document))->assertNotFound();
        $document->delete();
        $this->get(route('documents.download', $document))->assertNotFound();
    }

    public function test_version_from_another_document_cannot_be_downloaded_through_wrong_document(): void
    {
        $first = $this->document();
        $second = $this->document();
        $first->current_version_id = $second->current_version_id;
        $first->save();
        $this->signInAsViewer();
        $this->get(route('documents.download', $first))->assertNotFound();
    }
}
