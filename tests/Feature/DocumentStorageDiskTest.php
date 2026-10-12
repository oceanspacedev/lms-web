<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\User;
use Database\Seeders\DocumentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DocumentStorageDiskTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('s3');
        Storage::fake('local');
        Storage::fake('public');
        $this->seed(DocumentSeeder::class);
    }

    private function document(string $content = "%PDF-1.4\nversi 1"): Document
    {
        return Document::archive([
            'company_id' => Company::factory()->create()->id,
            'document_type_id' => (DocumentType::firstWhere('name', 'Akta Pendirian') ?? DocumentType::factory()->withoutExpiry()->create(['name' => 'Akta Pendirian']))->id,
            'pic_user_id' => User::factory()->create()->id,
            'title' => 'Akta PT Uji',
        ], UploadedFile::fake()->createWithContent('Akta Asli.pdf', $content), User::factory()->create());
    }

    private function signInAsViewer(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['ViewAny:Document', 'View:Document']);
        $this->actingAs($user);

        return $user;
    }

    /** @return array<string, array{string}> */
    public static function disks(): array
    {
        return ['local' => ['local'], 'public' => ['public'], 's3' => ['s3']];
    }

    #[DataProvider('disks')]
    public function test_new_files_follow_the_filesystem_disk_setting(string $disk): void
    {
        config(['filesystems.default' => $disk]);

        $version = $this->document()->currentVersion;

        $this->assertSame($disk, $version->disk);
        $this->assertSame("akta-pendirian/akta-pt-uji-dok{$version->document_id}-v1.pdf", $version->file_path);
        Storage::disk($disk)->assertExists($version->file_path);
        foreach (array_diff(['local', 'public', 's3'], [$disk]) as $other) {
            $this->assertSame([], Storage::disk($other)->allFiles());
        }
    }

    public function test_local_file_is_streamed_after_authorization(): void
    {
        config(['filesystems.default' => 'local']);
        $document = $this->document('isi lokal');
        $user = $this->signInAsViewer();

        $response = $this->get(route('documents.download', $document))->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->assertSame('isi lokal', $response->streamedContent());
        $this->assertStringStartsWith('attachment; filename="Akta Asli.pdf"', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertDatabaseHas('document_activities', ['document_id' => $document->id, 'user_id' => $user->id, 'event' => 'downloaded']);
    }

    public function test_local_pdf_preview_is_inline(): void
    {
        config(['filesystems.default' => 'local']);
        $document = $this->document();
        $this->signInAsViewer();

        $response = $this->get(route('documents.download', [$document, 'preview' => 1]))->assertOk();

        $this->assertStringStartsWith('inline;', $response->headers->get('Content-Disposition'));
    }

    public function test_unauthorized_user_cannot_download_local_file(): void
    {
        config(['filesystems.default' => 'local']);
        $document = $this->document();
        $this->actingAs(User::factory()->create());

        $this->get(route('documents.download', $document))->assertForbidden();
    }

    public function test_missing_local_file_returns_not_found(): void
    {
        config(['filesystems.default' => 'local']);
        $document = $this->document();
        Storage::disk('local')->delete($document->currentVersion->file_path);
        $this->signInAsViewer();

        $this->get(route('documents.download', $document))->assertNotFound();
    }

    public function test_switching_disk_keeps_old_versions_downloadable_from_their_own_disk(): void
    {
        config(['filesystems.default' => 's3']);
        $document = $this->document('versi s3');
        $old = $document->currentVersion;
        config(['filesystems.default' => 'local']);
        $new = $document->appendVersion(['change_note' => 'Pindah ke lokal'], UploadedFile::fake()->createWithContent('baru.pdf', 'versi lokal'), User::factory()->create());
        Storage::disk('s3')->buildTemporaryUrlsUsing(fn (string $path): string => "https://storage.test/{$path}?signature=temporary");
        $this->signInAsViewer();

        $this->assertSame(['s3', 'local'], [$old->disk, $new->disk]);
        $this->get(route('documents.download', [$document, 'version' => $old->id]))
            ->assertRedirect("https://storage.test/{$old->file_path}?signature=temporary");
        $this->assertSame('versi lokal', $this->get(route('documents.download', $document))->assertOk()->streamedContent());
    }

    public function test_failed_save_removes_file_from_the_configured_disk(): void
    {
        config(['filesystems.default' => 'local']);
        DB::listen(function ($query): void {
            if (str_starts_with($query->sql, 'insert into "document_versions"')) {
                throw new \RuntimeException('Gagal simpan versi');
            }
        });

        try {
            $this->document();
            $this->fail('Penyimpanan versi seharusnya gagal.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Gagal simpan versi', $exception->getMessage());
        }

        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_recorded_disk_cannot_be_changed(): void
    {
        $version = $this->document()->currentVersion;

        $this->expectException(LogicException::class);
        $version->update(['disk' => 'local']);
    }

    public function test_existing_versions_default_to_s3(): void
    {
        $document = $this->document();
        $id = DB::table('document_versions')->insertGetId([
            'document_id' => $document->id, 'version_number' => 9, 'file_path' => 'lama.pdf', 'file_name' => 'lama.pdf',
            'file_size' => 1, 'mime_type' => 'application/pdf', 'is_current' => false, 'uploaded_by' => User::factory()->create()->id,
        ]);

        $this->assertSame('s3', DB::table('document_versions')->where('id', $id)->value('disk'));
    }

    public function test_reorganize_command_moves_files_on_their_own_disk(): void
    {
        config(['filesystems.default' => 'local']);
        $version = $this->document('isi lokal')->currentVersion;
        $target = $version->file_path;
        Storage::disk('local')->move($target, 'lms/documents/1/1/v1/lama.pdf');
        DB::table('document_versions')->where('id', $version->id)->update(['file_path' => 'lms/documents/1/1/v1/lama.pdf']);
        config(['filesystems.default' => 's3']);

        $this->artisan('lms:reorganize-files')->expectsOutputToContain('Dipindahkan: 1, sudah rapi: 0, gagal: 0.')->assertSuccessful();

        $this->assertSame($target, $version->fresh()->file_path);
        $this->assertSame('isi lokal', Storage::disk('local')->get($target));
        $this->assertSame([], Storage::disk('s3')->allFiles());
    }
}
