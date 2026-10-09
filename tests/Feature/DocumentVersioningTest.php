<?php

namespace Tests\Feature;

use App\Filament\Resources\Documents\Pages\ViewDocument;
use App\Filament\Resources\Documents\RelationManagers\VersionsRelationManager;
use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\DocumentVersion;
use App\Models\User;
use Database\Seeders\DocumentSeeder;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class DocumentVersioningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Storage::fake('s3');
        $this->seed(DocumentSeeder::class);
    }

    private function document(): Document
    {
        return Document::archive([
            'company_id' => Company::factory()->create()->id,
            'document_type_id' => DocumentType::factory()->create()->id,
            'pic_user_id' => User::factory()->create()->id,
            'title' => 'Dokumen Versioning', 'issued_date' => '2026-01-01', 'expiry_date' => '2027-01-01',
        ], UploadedFile::fake()->create('lama.pdf', 1, 'application/pdf'), User::factory()->create());
    }

    private function versionData(): array
    {
        return ['issued_date' => '2027-01-01', 'expiry_date' => '2028-01-01', 'change_note' => 'Perpanjangan perjanjian'];
    }

    private function update(Document $document): DocumentVersion
    {
        return $document->appendVersion($this->versionData(), UploadedFile::fake()->create('baru.pdf', 1, 'application/pdf'), User::factory()->create());
    }

    public function test_update_preserves_old_file_and_dates_and_has_one_current_version(): void
    {
        $document = $this->document();
        $old = $document->currentVersion;
        $new = $this->update($document);
        $this->assertSame(2, $new->version_number);
        $this->assertSame($new->id, $document->current_version_id);
        $this->assertSame('Perpanjangan perjanjian', $new->change_note);
        $this->assertFalse($old->fresh()->is_current);
        $this->assertSame('2027-01-01', $old->fresh()->expiry_date->toDateString());
        $this->assertSame('2028-01-01', $new->expiry_date->toDateString());
        Storage::disk('s3')->assertExists([$old->file_path, $new->file_path]);
        $this->assertSame(1, $document->versions()->where('is_current', true)->count());
        $stale = $document->fresh();
        $this->update($document);
        $this->assertSame(4, $this->update($stale)->version_number);
        $this->assertSame(1, $document->versions()->where('is_current', true)->count());
    }

    public function test_database_failure_restores_previous_version_and_removes_new_file(): void
    {
        $document = $this->document();
        $old = $document->currentVersion;
        Event::listen('eloquent.creating: '.DocumentVersion::class, function (): void {
            throw new RuntimeException('Simulated failure');
        });
        try {
            $this->update($document);
            $this->fail('Expected failure');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated failure', $exception->getMessage());
        }
        $this->assertTrue($old->fresh()->is_current);
        $this->assertSame($old->id, $document->fresh()->current_version_id);
        $this->assertSame([$old->file_path], Storage::disk('s3')->allFiles());
        $this->assertDatabaseCount('document_versions', 1);
    }

    public function test_note_and_expiry_validation_preserves_current_version(): void
    {
        $document = $this->document();
        try {
            $document->appendVersion([], UploadedFile::fake()->create('baru.pdf', 1, 'application/pdf'), User::factory()->create());
            $this->fail('Expected validation failure');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('change_note', $exception->errors());
            $this->assertArrayHasKey('expiry_date', $exception->errors());
        }
        $this->assertTrue($document->currentVersion->fresh()->is_current);
        $this->assertDatabaseCount('document_versions', 1);
    }

    public function test_database_rejects_two_current_versions(): void
    {
        $document = $this->document();
        $this->expectException(QueryException::class);
        DocumentVersion::factory()->create(['document_id' => $document->id, 'version_number' => 2]);
    }

    public function test_upload_failure_leaves_previous_version_active(): void
    {
        $document = $this->document();
        $old = $document->currentVersion;
        $disk = \Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('putFileAs')->once()->andThrow(new RuntimeException('Upload failed'));
        $disk->shouldReceive('delete')->once()->andReturn(true);
        Storage::set('s3', $disk);
        try {
            $this->update($document);
            $this->fail('Expected upload failure');
        } catch (RuntimeException $exception) {
            $this->assertSame('Upload failed', $exception->getMessage());
        }
        $this->assertTrue($old->fresh()->is_current);
        $this->assertSame($old->id, $document->fresh()->current_version_id);
        $this->assertDatabaseCount('document_versions', 1);
    }

    public function test_non_expiring_type_clears_expiry_on_new_version(): void
    {
        $document = $this->document();
        $document->documentType->update(['has_expiry' => false]);
        $this->assertNull($this->update($document)->expiry_date);
    }

    public function test_filament_requires_note_and_expiry_before_updating(): void
    {
        $document = $this->document();
        $user = User::factory()->create();
        $user->givePermissionTo(['ViewAny:Document', 'View:Document', 'Update:Document']);
        $this->actingAs($user);
        Livewire::test(ViewDocument::class, ['record' => $document->getRouteKey()])
            ->callAction('updateVersion', ['file' => UploadedFile::fake()->create('baru.pdf', 1, 'application/pdf')])
            ->assertHasActionErrors(['change_note' => 'required', 'expiry_date' => 'required']);
        $this->assertDatabaseCount('document_versions', 1);
    }

    public function test_filament_update_action_stores_new_version(): void
    {
        $document = $this->document();
        $user = User::factory()->create();
        $user->givePermissionTo(['ViewAny:Document', 'View:Document', 'Update:Document']);
        $this->actingAs($user);
        Livewire::test(ViewDocument::class, ['record' => $document->getRouteKey()])
            ->callAction('updateVersion', [...$this->versionData(), 'file' => UploadedFile::fake()->create('baru.pdf', 1, 'application/pdf')])
            ->assertHasNoActionErrors();
        $this->assertSame(2, $document->fresh()->currentVersion->version_number);
    }

    public function test_renewal_suggestion_starts_at_current_expiry_and_keeps_term(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 9)->setTime(9, 0));

        $this->assertSame([
            'issued_date' => '2027-01-01', 'expiry_date' => '2028-01-01', 'change_note' => 'Perpanjangan masa berlaku dokumen.',
        ], $this->document()->renewalSuggestion());
    }

    public function test_renewal_suggestion_starts_today_when_already_expired(): void
    {
        $document = $this->document();
        $this->travelTo(now()->setDate(2027, 3, 10)->setTime(9, 0));

        $suggestion = $document->renewalSuggestion();
        $this->assertSame('2027-03-10', $suggestion['issued_date']);
        $this->assertSame('2028-03-10', $suggestion['expiry_date']);
    }

    public function test_renewal_suggestion_falls_back_to_one_year_or_day_count(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 9)->setTime(9, 0));
        $withoutIssued = Document::archive([
            'company_id' => Company::factory()->create()->id, 'document_type_id' => DocumentType::factory()->create()->id,
            'pic_user_id' => User::factory()->create()->id, 'title' => 'Tanpa Tanggal Terbit', 'expiry_date' => '2027-02-28',
        ], UploadedFile::fake()->create('lama.pdf', 1, 'application/pdf'), User::factory()->create());
        $this->assertSame(['2027-02-28', '2028-02-28'], array_values(Arr::only($withoutIssued->renewalSuggestion(), ['issued_date', 'expiry_date'])));

        $document = $this->document();
        DB::table('document_versions')->where('id', $document->current_version_id)->update(['issued_date' => '2026-12-01', 'expiry_date' => '2027-03-11']);
        $suggestion = $document->fresh()->renewalSuggestion();
        $this->assertSame('2027-03-11', $suggestion['issued_date']);
        $this->assertSame('2027-06-19', $suggestion['expiry_date']);
    }

    public function test_renew_action_is_prefilled_and_stores_new_version(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 9)->setTime(9, 0));
        $document = $this->document();
        $user = User::factory()->create();
        $user->givePermissionTo(['ViewAny:Document', 'View:Document', 'Update:Document']);
        $this->actingAs($user);
        Livewire::test(ViewDocument::class, ['record' => $document->getRouteKey()])
            ->mountAction('renew')
            ->assertActionDataSet(['issued_date' => '2027-01-01', 'expiry_date' => '2028-01-01', 'change_note' => 'Perpanjangan masa berlaku dokumen.'])
            ->setActionData(['file' => UploadedFile::fake()->create('baru.pdf', 1, 'application/pdf')])
            ->callMountedAction()->assertHasNoActionErrors();
        $version = $document->fresh()->currentVersion;
        $this->assertSame(2, $version->version_number);
        $this->assertSame('2028-01-01', $version->expiry_date->toDateString());
        $this->assertSame('Perpanjangan masa berlaku dokumen.', $version->change_note);
    }

    public function test_renew_action_is_hidden_for_viewers_and_non_expiring_types(): void
    {
        $document = $this->document();
        $viewer = User::factory()->create();
        $viewer->givePermissionTo(['ViewAny:Document', 'View:Document']);
        $this->actingAs($viewer);
        Livewire::test(ViewDocument::class, ['record' => $document->getRouteKey()])->assertActionHidden('renew');

        $editor = User::factory()->create();
        $editor->givePermissionTo(['ViewAny:Document', 'View:Document', 'Update:Document']);
        $this->actingAs($editor);
        Livewire::test(ViewDocument::class, ['record' => $document->getRouteKey()])->assertActionVisible('renew');
        $document->documentType->update(['has_expiry' => false]);
        Livewire::test(ViewDocument::class, ['record' => $document->getRouteKey()])->assertActionHidden('renew')->assertActionVisible('updateVersion');
    }

    public function test_viewer_can_read_history_but_cannot_update_or_mutate_versions(): void
    {
        $document = $this->document();
        $user = User::factory()->create();
        $user->givePermissionTo(['ViewAny:Document', 'View:Document']);
        $this->actingAs($user);
        Livewire::test(ViewDocument::class, ['record' => $document->getRouteKey()])->assertActionHidden('updateVersion');
        Livewire::test(VersionsRelationManager::class, ['ownerRecord' => $document, 'pageClass' => ViewDocument::class])
            ->assertSuccessful()->assertCanSeeTableRecords($document->versions)->assertSee('Versi aktif')
            ->assertTableActionDoesNotExist('edit')->assertTableActionDoesNotExist('delete');
    }

    public function test_archived_download_is_scoped_to_document(): void
    {
        $document = $this->document();
        $old = $document->currentVersion;
        $this->update($document);
        $other = $this->document();
        $user = User::factory()->create();
        $user->givePermissionTo('View:Document');
        $this->actingAs($user);
        $captured = null;
        Storage::disk('s3')->buildTemporaryUrlsUsing(function (string $path) use (&$captured): string {
            $captured = $path;

            return 'https://storage.test/signed';
        });
        $this->get(route('documents.download', ['document' => $document, 'version' => $old->id]))->assertRedirect('https://storage.test/signed');
        $this->assertSame($old->file_path, $captured);
        $this->get(route('documents.download', ['document' => $document, 'version' => $other->current_version_id]))->assertNotFound();
        $this->get(route('documents.download', ['document' => $document, 'version' => ['invalid']]))->assertNotFound();
    }

    public function test_archived_version_cannot_be_edited(): void
    {
        $document = $this->document();
        $old = $document->currentVersion;
        $this->update($document);
        $this->expectException(LogicException::class);
        $old->fresh()->update(['change_note' => 'Diubah']);
    }
}
