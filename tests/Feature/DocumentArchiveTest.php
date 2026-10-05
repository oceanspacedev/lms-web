<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\DocumentVersion;
use App\Models\User;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use LogicException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class DocumentArchiveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('s3');
    }

    private function archiveData(?DocumentType $type = null): array
    {
        return [
            'company_id' => Company::factory()->create()->id,
            'document_type_id' => ($type ?? DocumentType::factory()->create())->id,
            'pic_user_id' => User::factory()->create()->id,
            'title' => 'Perjanjian Uji',
            'issued_date' => '2026-01-01',
            'expiry_date' => '2027-01-01',
        ];
    }

    public function test_archive_creates_private_file_and_current_version_atomically(): void
    {
        $user = User::factory()->create();
        $file = UploadedFile::fake()->create('perjanjian.pdf', 64, 'application/pdf');
        $document = Document::archive($this->archiveData(), $file, $user);
        $version = $document->currentVersion;

        $this->assertNotNull($version);
        $this->assertSame(1, $version->version_number);
        $this->assertTrue($version->is_current);
        $this->assertSame($user->id, $version->uploaded_by);
        $this->assertSame('perjanjian.pdf', $version->file_name);
        $this->assertSame(65536, $version->file_size);
        $this->assertSame('application/pdf', $version->mime_type);
        $this->assertSame('2027-01-01', $version->expiry_date->toDateString());
        $this->assertStringStartsWith("lms/documents/{$document->company_id}/{$document->id}/v1/", $version->file_path);
        $this->assertStringEndsWith('.pdf', $version->file_path);
        Storage::disk('s3')->assertExists($version->file_path);
        $this->assertDatabaseCount('documents', 1);
        $this->assertDatabaseCount('document_versions', 1);
    }

    public function test_expiry_date_is_required_for_expiring_type(): void
    {
        $data = $this->archiveData();
        $data['expiry_date'] = null;
        try {
            Document::archive($data, UploadedFile::fake()->create('test.pdf', 1, 'application/pdf'), User::factory()->create());
            $this->fail('Tanggal berakhir wajib.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('expiry_date', $exception->errors());
        }
        $this->assertDatabaseCount('documents', 0);
        $this->assertSame([], Storage::disk('s3')->allFiles());
    }

    public function test_type_without_expiry_discards_expiry_date(): void
    {
        $document = Document::archive($this->archiveData(DocumentType::factory()->withoutExpiry()->create()),
            UploadedFile::fake()->create('akta.pdf', 1, 'application/pdf'), User::factory()->create());
        $this->assertNull($document->currentVersion->expiry_date);
    }

    public function test_expiry_cannot_precede_issued_date(): void
    {
        $data = $this->archiveData();
        $data['expiry_date'] = '2025-12-31';
        $this->expectException(ValidationException::class);
        Document::archive($data, UploadedFile::fake()->create('test.pdf', 1, 'application/pdf'), User::factory()->create());
    }

    public function test_upload_size_limit_is_configurable_and_rejects_oversize_file(): void
    {
        config(['lms.max_upload_size_kb' => 10]);
        $this->expectException(ValidationException::class);
        Document::archive($this->archiveData(), UploadedFile::fake()->create('large.pdf', 11, 'application/pdf'), User::factory()->create());
    }

    public function test_mime_and_extension_must_both_be_allowed(): void
    {
        $data = $this->archiveData();
        $user = User::factory()->create();
        foreach ([UploadedFile::fake()->create('script.php', 1, 'application/pdf'), UploadedFile::fake()->create('fake.pdf', 1, 'text/plain')] as $file) {
            try {
                Document::archive($data, $file, $user);
                $this->fail('File tidak valid harus ditolak.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('file', $exception->errors());
            }
        }
        $this->assertDatabaseCount('documents', 0);
    }

    #[DataProvider('allowedFiles')]
    public function test_supported_file_formats_can_be_archived(string $extension, string $mime): void
    {
        $document = Document::archive($this->archiveData(), UploadedFile::fake()->create('legal.'.$extension, 1, $mime), User::factory()->create());
        $this->assertSame($mime, $document->currentVersion->mime_type);
        Storage::disk('s3')->assertExists($document->currentVersion->file_path);
    }

    public static function allowedFiles(): array
    {
        return [
            'PDF' => ['pdf', 'application/pdf'],
            'DOC' => ['doc', 'application/msword'],
            'DOCX' => ['docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            'JPG' => ['jpg', 'image/jpeg'],
            'JPEG' => ['jpeg', 'image/jpeg'],
            'PNG' => ['png', 'image/png'],
        ];
    }

    public function test_archived_version_cannot_be_deleted(): void
    {
        $document = Document::archive($this->archiveData(), UploadedFile::fake()->create('test.pdf', 1, 'application/pdf'), User::factory()->create());
        $this->expectException(LogicException::class);
        $document->currentVersion->delete();
    }

    public function test_storage_failure_rolls_back_document_and_version(): void
    {
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('putFileAs')->once()->andThrow(new RuntimeException('Storage unavailable'));
        $disk->shouldReceive('delete')->once()->andReturn(true);
        Storage::shouldReceive('disk')->with('s3')->andReturn($disk);
        try {
            Document::archive($this->archiveData(), UploadedFile::fake()->create('test.pdf', 1, 'application/pdf'), User::factory()->create());
            $this->fail('Upload gagal harus membatalkan arsip.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Storage unavailable', $exception->getMessage());
        }
        $this->assertDatabaseCount('documents', 0);
        $this->assertDatabaseCount('document_versions', 0);
    }

    public function test_upload_explicitly_requests_private_visibility(): void
    {
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('putFileAs')->once()
            ->withArgs(fn (string $directory, UploadedFile $file, string $name, array $options): bool => $options['visibility'] === 'private' && str_starts_with($directory, 'lms/documents/') && str_ends_with($name, '.pdf'))
            ->andReturn('lms/documents/private.pdf');
        Storage::shouldReceive('disk')->with('s3')->andReturn($disk);
        $document = Document::archive($this->archiveData(), UploadedFile::fake()->create('test.pdf', 1, 'application/pdf'), User::factory()->create());
        $this->assertNotNull($document->current_version_id);
    }

    public function test_database_failure_removes_uploaded_file(): void
    {
        Event::listen('eloquent.creating: '.DocumentVersion::class, function (): void {
            throw new RuntimeException('Version insert failed');
        });
        try {
            Document::archive($this->archiveData(), UploadedFile::fake()->create('test.pdf', 1, 'application/pdf'), User::factory()->create());
            $this->fail('Penyimpanan versi gagal harus membatalkan arsip.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Version insert failed', $exception->getMessage());
        }
        $this->assertDatabaseCount('documents', 0);
        $this->assertDatabaseCount('document_versions', 0);
        $this->assertSame([], Storage::disk('s3')->allFiles());
    }

    public function test_file_metadata_is_immutable_and_document_deletion_is_soft(): void
    {
        $document = Document::archive($this->archiveData(), UploadedFile::fake()->create('test.pdf', 1, 'application/pdf'), User::factory()->create());
        $version = $document->currentVersion;
        $document->delete();
        $this->assertSoftDeleted($document);
        Storage::disk('s3')->assertExists($version->file_path);
        $this->assertDatabaseHas('document_versions', ['id' => $version->id]);
        $this->expectException(LogicException::class);
        $version->update(['file_path' => 'other.pdf']);
    }
}
