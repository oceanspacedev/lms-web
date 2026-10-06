<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\ReminderLog;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DummyBackupRestoreTest extends TestCase
{
    public function test_sqlite_snapshot_and_all_dummy_file_versions_can_be_restored_together(): void
    {
        $source = tempnam(sys_get_temp_dir(), 'lms-uat-source-');
        $snapshot = tempnam(sys_get_temp_dir(), 'lms-uat-snapshot-');
        $restored = tempnam(sys_get_temp_dir(), 'lms-uat-restored-');
        $this->assertIsString($source);
        $this->assertIsString($snapshot);
        $this->assertIsString($restored);
        config(['database.connections.dummy_backup' => ['driver' => 'sqlite', 'database' => $source, 'prefix' => '', 'foreign_key_constraints' => true],
            'database.connections.dummy_restore' => ['driver' => 'sqlite', 'database' => $restored, 'prefix' => '', 'foreign_key_constraints' => true]]);
        DB::setDefaultConnection('dummy_backup');
        Storage::fake('s3');
        Storage::fake('dummy-restored');
        try {
            $this->artisan('migrate', ['--database' => 'dummy_backup', '--force' => true, '--no-interaction' => true])->assertSuccessful();
            $user = User::factory()->create(['name' => 'PIC Dummy', 'email' => 'pic@example.test']);
            $document = Document::archive([
                'company_id' => Company::factory()->create(['name' => 'PT Dummy UAT'])->id,
                'document_type_id' => DocumentType::factory()->withoutExpiry()->create()->id,
                'pic_user_id' => $user->id, 'title' => 'Dokumen Dummy Backup',
            ], UploadedFile::fake()->createWithContent('dummy-v1.pdf', "%PDF-1.4\nDummy versi 1"), $user);
            $document->appendVersion(['change_note' => 'Revisi dummy'], UploadedFile::fake()->createWithContent('dummy-v2.pdf', "%PDF-1.4\nDummy versi 2"), $user);
            ReminderLog::create(['document_version_id' => $document->current_version_id, 'offset_days' => 30, 'status' => 'accepted', 'attempts' => 1]);
            $files = [];
            foreach (Storage::disk('s3')->allFiles('lms/documents') as $path) {
                $files[$path] = Storage::disk('s3')->get($path);
            }
            DB::statement('VACUUM INTO ?', [$snapshot]);
            $this->assertSame('ok', DB::selectOne('PRAGMA integrity_check')->integrity_check);
            $document->update(['title' => 'Perubahan setelah snapshot']);
            $this->assertTrue(copy($snapshot, $restored));
            foreach ($files as $path => $contents) {
                Storage::disk('dummy-restored')->put($path, $contents);
            }
            $connection = DB::connection('dummy_restore');
            $this->assertSame('Dokumen Dummy Backup', $connection->table('documents')->value('title'));
            $this->assertSame(2, $connection->table('document_versions')->count());
            $this->assertSame(2, $connection->table('document_activities')->count());
            $this->assertSame(1, $connection->table('reminder_logs')->count());
            $this->assertSame($document->current_version_id, $connection->table('documents')->value('current_version_id'));
            $this->assertSame('ok', $connection->selectOne('PRAGMA integrity_check')->integrity_check);
            $this->assertSame([], $connection->select('PRAGMA foreign_key_check'));
            foreach ($connection->table('document_versions')->get() as $version) {
                $this->assertTrue(Storage::disk('dummy-restored')->exists($version->file_path));
                $this->assertSame(hash('sha256', $files[$version->file_path]), hash('sha256', Storage::disk('dummy-restored')->get($version->file_path)));
            }
        } finally {
            DB::purge('dummy_backup');
            DB::purge('dummy_restore');
            File::delete([$source, $snapshot, $restored]);
        }
    }
}
