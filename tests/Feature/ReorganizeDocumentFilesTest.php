<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\DocumentVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReorganizeDocumentFilesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('s3');
    }

    private function document(string $title = 'Perjanjian Sewa Gudang', string $type = 'Perjanjian Kerja Sama'): Document
    {
        return Document::archive([
            'company_id' => Company::factory()->create()->id,
            'document_type_id' => (DocumentType::firstWhere('name', $type) ?? DocumentType::factory()->withoutExpiry()->create(['name' => $type]))->id,
            'pic_user_id' => User::factory()->create()->id,
            'title' => $title,
        ], UploadedFile::fake()->createWithContent('asli.pdf', "%PDF-1.4\n{$title}"), User::factory()->create());
    }

    /**
     * Pindahkan file versi ke path struktur lama, seperti data sebelum Work 48.
     */
    private function moveToLegacyPath(DocumentVersion $version): string
    {
        $companyId = Document::withTrashed()->find($version->document_id)->company_id;
        $legacy = "lms/documents/{$companyId}/{$version->document_id}/v{$version->version_number}/5ea46c5e-2e68-42a5-ae1b-7847cdb3757f.pdf";
        Storage::disk('s3')->move($version->file_path, $legacy);
        DB::table('document_versions')->where('id', $version->id)->update(['file_path' => $legacy]);

        return $legacy;
    }

    public function test_storage_path_groups_files_by_document_type_with_readable_names(): void
    {
        $document = $this->document('PKS PT. Abc & Co (2026)', 'Izin Usaha/NIB');

        $this->assertSame("izin-usaha-nib/pks-pt-abc-co-2026-dok{$document->id}-v3.docx", $document->storagePath(3, 'DOCX'));
    }

    public function test_storage_path_falls_back_for_unusable_names_and_limits_title_length(): void
    {
        $symbols = $this->document('!!!', '***');
        $long = $this->document(str_repeat('Perjanjian Panjang ', 10));

        $this->assertSame("lainnya/dokumen-dok{$symbols->id}-v1.pdf", $symbols->currentVersion->file_path);
        $this->assertLessThanOrEqual(80, strlen(explode('-dok', basename($long->currentVersion->file_path))[0]));
        $this->assertStringNotContainsString('--', $long->currentVersion->file_path);
    }

    public function test_command_moves_legacy_files_and_keeps_their_contents(): void
    {
        $document = $this->document();
        $document->appendVersion(['change_note' => 'Revisi'], UploadedFile::fake()->createWithContent('baru.pdf', 'versi 2'), User::factory()->create());
        $trashed = $this->document('Akta Lama', 'Akta Pendirian');
        $trashed->delete();
        $legacy = DocumentVersion::orderBy('id')->get()->mapWithKeys(fn (DocumentVersion $version): array => [$version->id => $this->moveToLegacyPath($version)]);
        $contents = $legacy->map(fn (string $path): string => Storage::disk('s3')->get($path));

        $this->artisan('lms:reorganize-files')->expectsOutputToContain('Dipindahkan: 3, sudah rapi: 0, gagal: 0.')->assertSuccessful();

        foreach (DocumentVersion::orderBy('id')->get() as $version) {
            $this->assertSame(Document::withTrashed()->find($version->document_id)->storagePath($version->version_number, 'pdf'), $version->file_path);
            $this->assertSame($contents[$version->id], Storage::disk('s3')->get($version->file_path));
            Storage::disk('s3')->assertMissing($legacy[$version->id]);
        }
        $this->assertSame([
            "akta-pendirian/akta-lama-dok{$trashed->id}-v1.pdf",
            "perjanjian-kerja-sama/perjanjian-sewa-gudang-dok{$document->id}-v1.pdf",
            "perjanjian-kerja-sama/perjanjian-sewa-gudang-dok{$document->id}-v2.pdf",
        ], Storage::disk('s3')->allFiles());

        $this->artisan('lms:reorganize-files')->expectsOutputToContain('Dipindahkan: 0, sudah rapi: 3, gagal: 0.')->assertSuccessful();
    }

    public function test_dry_run_changes_nothing(): void
    {
        $version = $this->document()->currentVersion;
        $legacy = $this->moveToLegacyPath($version);

        $this->artisan('lms:reorganize-files', ['--dry-run' => true])
            ->expectsOutputToContain($legacy)
            ->expectsOutputToContain('Akan dipindahkan: 1, sudah rapi: 0, gagal: 0.')
            ->assertSuccessful();

        $this->assertSame($legacy, $version->fresh()->file_path);
        $this->assertSame([$legacy], Storage::disk('s3')->allFiles());
    }

    public function test_missing_source_is_reported_without_blocking_other_files(): void
    {
        $missing = $this->document('Hilang')->currentVersion;
        $missingLegacy = $this->moveToLegacyPath($missing);
        Storage::disk('s3')->delete($missingLegacy);
        $present = $this->document('Ada')->currentVersion;
        $this->moveToLegacyPath($present);

        $this->artisan('lms:reorganize-files')
            ->expectsOutputToContain('file asal tidak ditemukan')
            ->expectsOutputToContain('Dipindahkan: 1, sudah rapi: 0, gagal: 1.')
            ->assertFailed();

        $this->assertSame($missingLegacy, $missing->fresh()->file_path);
        Storage::disk('s3')->assertExists($present->fresh()->file_path);
        $this->assertStringStartsWith('perjanjian-kerja-sama/ada-dok', $present->fresh()->file_path);
    }

    public function test_interrupted_move_is_completed_on_rerun(): void
    {
        $version = $this->document()->currentVersion;
        $target = $version->file_path;
        DB::table('document_versions')->where('id', $version->id)->update(['file_path' => 'lms/documents/1/1/v1/lama.pdf']);

        $this->artisan('lms:reorganize-files')->expectsOutputToContain('Dipindahkan: 1')->assertSuccessful();

        $this->assertSame($target, $version->fresh()->file_path);
        Storage::disk('s3')->assertExists($target);
    }

    public function test_conflicting_target_is_not_overwritten(): void
    {
        $version = $this->document()->currentVersion;
        $target = $version->file_path;
        $legacy = 'lms/documents/1/1/v1/lama.pdf';
        Storage::disk('s3')->put($legacy, 'isi lain');
        DB::table('document_versions')->where('id', $version->id)->update(['file_path' => $legacy]);
        $original = Storage::disk('s3')->get($target);

        $this->artisan('lms:reorganize-files')->expectsOutputToContain('tujuan sudah berisi file lain')->assertFailed();

        $this->assertSame($legacy, $version->fresh()->file_path);
        $this->assertSame($original, Storage::disk('s3')->get($target));
        Storage::disk('s3')->assertExists($legacy);
    }
}
