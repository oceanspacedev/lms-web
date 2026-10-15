<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\DocumentVersion;
use App\Models\User;
use Carbon\CarbonImmutable;
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
        $this->travelTo(CarbonImmutable::parse('2026-10-12 02:15:30', 'UTC'));
    }

    private function document(string $title = 'Perjanjian Sewa Gudang', string $type = 'Perjanjian Kerja Sama', ?string $number = null): Document
    {
        return Document::archive([
            'company_id' => (Company::firstWhere('name', 'Abc Sejahtera') ?? Company::factory()->create(['name' => 'Abc Sejahtera', 'legal_form' => 'PT']))->id,
            'document_type_id' => (DocumentType::firstWhere('name', $type) ?? DocumentType::factory()->withoutExpiry()->create(['name' => $type]))->id,
            'pic_user_id' => User::factory()->create()->id,
            'title' => $title,
            'document_number' => $number,
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

    public function test_file_name_contains_company_type_number_title_version_and_upload_time(): void
    {
        $document = $this->document('PKS PT. Abc & Co (2026)', 'Izin Usaha/NIB', '001/PKS/X/2026');

        $this->assertSame(
            "izin-usaha-nib/pt-abc-sejahtera_izin-usaha-nib_001-pks-x-2026_pks-pt-abc-co-2026_dok{$document->id}-v1_20261012-091530.pdf",
            $document->currentVersion->file_path,
        );
        $this->assertSame(
            "izin-usaha-nib/pt-abc-sejahtera_izin-usaha-nib_001-pks-x-2026_pks-pt-abc-co-2026_dok{$document->id}-v3_20270101-070000.docx",
            $document->storagePath(3, 'DOCX', CarbonImmutable::parse('2027-01-01 00:00:00', 'UTC')),
        );
        $this->assertEquals(now(), $document->currentVersion->created_at);
    }

    public function test_legal_form_is_not_repeated_when_already_in_company_name(): void
    {
        $company = Company::factory()->create(['name' => 'PT Maju Jaya', 'legal_form' => 'PT']);
        $document = $this->document();
        $document->forceFill(['company_id' => $company->id])->save();

        $this->assertStringStartsWith('perjanjian-kerja-sama/pt-maju-jaya_perjanjian-kerja-sama_', $document->storagePath(1, 'pdf'));
    }

    public function test_file_name_skips_unusable_parts_and_limits_each_part(): void
    {
        $symbols = $this->document('!!!', '***');
        $long = $this->document(str_repeat('Perjanjian Panjang ', 10));

        $this->assertSame("lainnya/pt-abc-sejahtera_dok{$symbols->id}-v1_20261012-091530.pdf", $symbols->currentVersion->file_path);
        foreach (explode('_', pathinfo($long->currentVersion->file_path, PATHINFO_FILENAME)) as $part) {
            $this->assertLessThanOrEqual(50, strlen($part));
            $this->assertStringEndsNotWith('-', $part);
        }
    }

    public function test_command_moves_legacy_files_and_keeps_their_contents(): void
    {
        $document = $this->document();
        $document->appendVersion(['change_note' => 'Revisi'], UploadedFile::fake()->createWithContent('baru.pdf', 'versi 2'), User::factory()->create());
        $trashed = $this->document('Akta Lama', 'Akta Pendirian');
        $trashed->delete();
        $legacy = DocumentVersion::orderBy('id')->get()->mapWithKeys(fn (DocumentVersion $version): array => [$version->id => $this->moveToLegacyPath($version)]);
        $contents = $legacy->map(fn (string $path): string => Storage::disk('s3')->get($path));
        $this->travel(3)->days();

        $this->artisan('lms:reorganize-files')->expectsOutputToContain('Dipindahkan: 3, sudah rapi: 0, gagal: 0.')->assertSuccessful();

        foreach (DocumentVersion::orderBy('id')->get() as $version) {
            $this->assertSame(Document::withTrashed()->find($version->document_id)->storagePath($version->version_number, 'pdf', $version->created_at), $version->file_path);
            $this->assertSame($contents[$version->id], Storage::disk('s3')->get($version->file_path));
            Storage::disk('s3')->assertMissing($legacy[$version->id]);
        }
        $this->assertSame([
            "akta-pendirian/pt-abc-sejahtera_akta-pendirian_akta-lama_dok{$trashed->id}-v1_20261012-091530.pdf",
            "perjanjian-kerja-sama/pt-abc-sejahtera_perjanjian-kerja-sama_perjanjian-sewa-gudang_dok{$document->id}-v1_20261012-091530.pdf",
            "perjanjian-kerja-sama/pt-abc-sejahtera_perjanjian-kerja-sama_perjanjian-sewa-gudang_dok{$document->id}-v2_20261012-091530.pdf",
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
        $this->assertStringStartsWith('perjanjian-kerja-sama/pt-abc-sejahtera_perjanjian-kerja-sama_ada_dok', $present->fresh()->file_path);
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
