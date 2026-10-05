<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;
use Throwable;

class S3StorageTest extends TestCase
{
    use RefreshDatabase;

    #[Group('s3-live')]
    public function test_live_s3_upload_signed_download_and_private_access(): void
    {
        if (! getenv('LMS_TEST_REAL_S3')) {
            $this->markTestSkipped('Set LMS_TEST_REAL_S3=1 untuk uji S3 nyata dengan kredensial .env lokal.');
        }

        $this->assertNotEmpty(config('filesystems.disks.s3.key'), 'AWS_ACCESS_KEY_ID belum diisi.');
        $this->assertNotEmpty(config('filesystems.disks.s3.secret'), 'AWS_SECRET_ACCESS_KEY belum diisi.');
        $disk = Storage::disk('s3');
        $paths = [];
        $content = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n";

        try {
            $document = Document::archive([
                'company_id' => Company::factory()->create()->id,
                'document_type_id' => DocumentType::factory()->withoutExpiry()->create()->id,
                'pic_user_id' => User::factory()->create()->id,
                'title' => 'Uji koneksi S3 Work 5',
            ], UploadedFile::fake()->createWithContent('uji-lms.pdf', $content), User::factory()->create());
            $path = $document->currentVersion->file_path;
            $paths[] = $path;
            $this->assertTrue($disk->exists($path));
            $url = $disk->temporaryUrl($path, now()->addMinutes(5));
            $response = Http::timeout(20)->get($url);
            $this->assertSame(200, $response->status(), 'Signed URL gagal mengunduh file uji.');
            $this->assertTrue($response->body() === $content, 'Isi file unduhan tidak cocok.');

            $unsignedUrl = strtok($url, '?');
            $publicResponse = Http::timeout(20)->get($unsignedUrl);
            $this->assertContains($publicResponse->status(), [401, 403, 404], 'File dapat diakses tanpa signature; periksa akses public bucket.');
            $newContent = $content."\n% Version 2\n";
            $new = $document->appendVersion(['change_note' => 'Uji pembaruan S3 Work 6'], UploadedFile::fake()->createWithContent('uji-lms-v2.pdf', $newContent), User::factory()->create());
            $paths[] = $new->file_path;
            $this->assertSame(2, $new->version_number);
            $this->assertSame(1, $document->versions()->where('is_current', true)->count());
            foreach ([$path => $content, $new->file_path => $newContent] as $versionPath => $expectedContent) {
                $download = Http::timeout(20)->get($disk->temporaryUrl($versionPath, now()->addMinutes(5)));
                $this->assertSame(200, $download->status());
                $this->assertTrue($download->body() === $expectedContent, 'Isi versi dokumen tidak cocok.');
            }
        } catch (AssertionFailedError $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->fail('Uji S3 nyata gagal ('.$exception::class.'). Periksa kredensial, izin bucket, koneksi, dan sertifikat.');
        } finally {
            if ($paths !== []) {
                try {
                    $this->assertTrue($disk->delete($paths), 'File uji S3 belum dapat dibersihkan.');
                } catch (Throwable $exception) {
                    $this->fail('File uji S3 belum dapat dibersihkan ('.$exception::class.').');
                }
            }
        }
    }

    public function test_real_s3_adapter_generates_signed_url_for_lms_bucket_without_network(): void
    {
        $this->freezeTime();
        $disk = Storage::build([
            ...config('filesystems.disks.s3'),
            'key' => 'testing-access-key',
            'secret' => 'testing-secret-key',
            'region' => 'us-east-1',
            'bucket' => 'lms',
            'endpoint' => 'https://storage.test',
            'use_path_style_endpoint' => true,
        ]);
        $expiration = now()->addMinutes(5);
        $url = $disk->temporaryUrl('lms/documents/1/1/v1/file.pdf', $expiration, [
            'ResponseContentDisposition' => 'attachment; filename=file.pdf',
        ]);
        $parsed = parse_url($url);
        parse_str($parsed['query'], $query);

        $this->assertSame('https', $parsed['scheme']);
        $this->assertSame('storage.test', $parsed['host']);
        $this->assertSame('/lms/lms/documents/1/1/v1/file.pdf', $parsed['path']);
        $this->assertSame('AWS4-HMAC-SHA256', $query['X-Amz-Algorithm']);
        $signedAt = CarbonImmutable::createFromFormat('Ymd\THis\Z', $query['X-Amz-Date'], 'UTC');
        $this->assertSame((string) ($expiration->getTimestamp() - $signedAt->getTimestamp()), $query['X-Amz-Expires']);
        $this->assertLessThanOrEqual(300, (int) $query['X-Amz-Expires']);
        $this->assertGreaterThan(0, (int) $query['X-Amz-Expires']);
        $this->assertNotEmpty($query['X-Amz-Signature']);
        $this->assertSame('attachment; filename=file.pdf', $query['response-content-disposition']);
    }
}
