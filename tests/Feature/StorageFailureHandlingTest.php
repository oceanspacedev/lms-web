<?php

namespace Tests\Feature;

use App\Filament\Resources\DocumentRequests\Pages\ListDocumentRequests;
use App\Filament\Resources\Documents\Pages\CreateDocument;
use App\Filament\Resources\Documents\Pages\ViewDocument;
use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentRequest;
use App\Models\DocumentType;
use App\Models\User;
use App\Support\StorageFailure;
use Aws\CommandInterface;
use Aws\S3\Exception\S3Exception;
use Database\Seeders\PermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToWriteFile;
use Livewire\Livewire;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * Bila S3 tidak bisa dihubungi saat menyimpan file, pengguna mendapat pesan jelas, data tidak berubah, dan error tetap dilaporkan.
 */
class StorageFailureHandlingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Storage::fake('local');
        Storage::fake('s3');
        $this->seed(PermissionSeeder::class);
        config(['services.waghub.url' => 'https://waghub.test', 'services.waghub.token' => 'test-token']);
        Http::preventStrayRequests();
        Http::fake(['waghub.test/*' => Http::response([], 202)]);
    }

    private function breakS3(): void
    {
        $disk = Mockery::mock(FilesystemAdapter::class)->shouldIgnoreMissing();
        // Unggahan dari form Filament (Livewire) memakai put(); UploadedFile biasa memakai putFileAs().
        $disk->shouldReceive('put', 'putFileAs')->andThrow(UnableToWriteFile::atLocation('lms/dokumen.pdf', 'Connection timed out'));
        Storage::set('s3', $disk);
        Exceptions::fake();
    }

    private function storageNotification(string $title): Notification
    {
        return Notification::make()->title($title)->body(StorageFailure::MESSAGE)->danger();
    }

    /** @param list<string> $permissions */
    private function signIn(array $permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);
        $this->actingAs($user);

        return $user;
    }

    private function document(): Document
    {
        return Document::archive([
            'company_id' => Company::factory()->create()->id,
            'document_type_id' => DocumentType::factory()->withoutExpiry()->create()->id,
            'pic_user_id' => User::factory()->create()->id,
            'title' => 'Dokumen Lama',
        ], UploadedFile::fake()->create('lama.pdf', 1, 'application/pdf'), User::factory()->create());
    }

    public function test_create_document_shows_storage_message_and_saves_nothing(): void
    {
        $this->signIn(['ViewAny:Document', 'Create:Document']);
        $this->breakS3();

        Livewire::test(CreateDocument::class)
            ->fillForm([
                'company_id' => Company::factory()->create()->id,
                'document_type_id' => DocumentType::factory()->withoutExpiry()->create()->id,
                'pic_user_id' => User::factory()->create()->id,
                'title' => 'Perjanjian Kemitraan',
                'file' => UploadedFile::fake()->create('kontrak.pdf', 64, 'application/pdf'),
            ])
            ->call('create')
            ->assertNotified($this->storageNotification('Dokumen belum tersimpan'));

        $this->assertDatabaseCount('documents', 0);
        $this->assertDatabaseCount('document_versions', 0);
        Exceptions::assertReported(UnableToWriteFile::class);
    }

    public function test_new_version_shows_storage_message_and_keeps_current_version(): void
    {
        $document = $this->document();
        $this->signIn(['ViewAny:Document', 'View:Document', 'Update:Document']);
        $this->breakS3();

        Livewire::test(ViewDocument::class, ['record' => $document->getRouteKey()])
            ->callAction('updateVersion', ['change_note' => 'Revisi', 'file' => UploadedFile::fake()->create('baru.pdf', 1, 'application/pdf')])
            ->assertNotified($this->storageNotification('Versi baru belum tersimpan'));

        $this->assertSame(1, $document->fresh()->currentVersion->version_number);
        $this->assertDatabaseCount('document_versions', 1);
        Exceptions::assertReported(UnableToWriteFile::class);
    }

    public function test_complete_with_scan_shows_storage_message_and_keeps_request_in_scanning(): void
    {
        $legal = $this->signIn(['ViewAny:DocumentRequest', 'View:DocumentRequest', 'Review:DocumentRequest', 'Create:Document']);
        $request = DocumentRequest::factory()->scanning()->create(['reviewer_id' => $legal->id]);
        $this->breakS3();

        Livewire::test(ListDocumentRequests::class)
            ->callAction(TestAction::make('completeWithScan')->table($request), [
                'scan' => UploadedFile::fake()->createWithContent('scan.pdf', "%PDF-1.4\n%%EOF\n"), 'expiry_date' => '2027-10-09',
            ])->assertHasNoActionErrors()
            ->assertNotified($this->storageNotification('Pengajuan belum diselesaikan'));

        $request->refresh();
        $this->assertSame('scanning', $request->status);
        $this->assertNull($request->document_id);
        $this->assertNull($request->scan_path);
        $this->assertDatabaseCount('documents', 0);
        Exceptions::assertReported(UnableToWriteFile::class);
    }

    public function test_other_failures_keep_the_general_message(): void
    {
        $this->assertFalse(StorageFailure::caused(new RuntimeException('Bug lain')));
        $this->assertTrue(StorageFailure::caused(UnableToWriteFile::atLocation('a.pdf')));
        $this->assertTrue(StorageFailure::caused(new RuntimeException('Bungkus', 0, new S3Exception('Timeout', Mockery::mock(CommandInterface::class)))));
    }

    /**
     * Unggahan Livewire mengabaikan nilai balik put(); tanpa `throw`, kegagalan S3 akan dianggap berhasil.
     */
    public function test_s3_disk_throws_on_failure_instead_of_returning_false(): void
    {
        $this->assertTrue(config('filesystems.disks.s3.throw'));
    }
}
