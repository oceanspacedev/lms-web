<?php

namespace Tests\Feature;

use App\Filament\Resources\Documents\DocumentResource;
use App\Filament\Resources\Documents\Pages\CreateDocument;
use App\Filament\Resources\Documents\Pages\EditDocument;
use App\Filament\Resources\Documents\Pages\ListDocuments;
use App\Filament\Resources\Documents\Pages\ViewDocument;
use App\Filament\Resources\ReminderLogs\Pages\ListReminderLogs;
use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\DocumentVersion;
use App\Models\ReminderLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DocumentSeeder;
use Database\Seeders\ReminderLogSeeder;
use Filament\Facades\Filament;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DummyAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-06 01:00:00', 'UTC'));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Storage::fake('s3');
        Http::preventStrayRequests();
        $this->seed([DocumentSeeder::class, ReminderLogSeeder::class]);
        config(['services.waghub.url' => 'https://waghub.example.test', 'services.waghub.token' => 'dummy-token', 'services.waghub.purpose' => 'dummy-purpose']);
    }

    public function test_dummy_editor_viewer_and_denied_user_complete_the_document_lifecycle(): void
    {
        $editorRole = Role::findOrCreate('uat_editor', 'web');
        $editorRole->givePermissionTo(['ViewAny:Document', 'View:Document', 'Create:Document', 'Update:Document', 'Delete:Document']);
        $viewerRole = Role::findOrCreate('uat_viewer', 'web');
        $viewerRole->givePermissionTo(['ViewAny:Document', 'View:Document', 'ViewAny:ReminderLog', 'View:ReminderLog']);
        $editor = User::factory()->create(['name' => 'Editor Dummy', 'email' => 'editor@example.test', 'phone' => '081111111111']);
        $editor->assignRole($editorRole);
        $viewer = User::factory()->create(['name' => 'Viewer Dummy', 'email' => 'viewer@example.test']);
        $viewer->assignRole($viewerRole);
        $company = Company::factory()->create(['name' => 'PT Dummy UAT']);
        $type = DocumentType::factory()->create(['name' => 'Kontrak Dummy', 'reminder_days' => [30, 7]]);
        $this->actingAs($editor);
        Livewire::test(CreateDocument::class)->fillForm([
            'title' => 'Kontrak Dummy UAT', 'document_number' => 'DUMMY-001', 'company_id' => $company->id,
            'document_type_id' => $type->id, 'pic_user_id' => $editor->id, 'issued_date' => '2026-10-06', 'expiry_date' => '2026-11-05',
            'file' => UploadedFile::fake()->create('dummy-v1.pdf', 1, 'application/pdf'),
        ])->call('create')->assertHasNoFormErrors();
        $document = Document::sole();
        $oldVersion = $document->currentVersion;
        $this->assertSame('expiring', $document->expiry_status);
        Http::fake(['waghub.example.test/*' => Http::sequence()->push([], 503)->push(['data' => ['status' => 'provider_accepted']], 201)]);
        $this->artisan('lms:send-reminders')->assertFailed();
        Livewire::test(ViewDocument::class, ['record' => $document->id])->callAction('updateVersion', data: [
            'file' => UploadedFile::fake()->create('dummy-v2.pdf', 1, 'application/pdf'), 'issued_date' => '2026-10-06',
            'expiry_date' => '2026-10-13', 'change_note' => 'Revisi dummy UAT',
        ])->assertHasNoActionErrors();
        $document->refresh();
        $this->assertSame(2, $document->currentVersion->version_number);
        $this->assertFalse($oldVersion->fresh()->is_current);
        $this->artisan('lms:send-reminders')->assertSuccessful();
        $this->artisan('lms:send-reminders')->assertSuccessful();
        Http::assertSentCount(2);
        $this->assertDatabaseHas('reminder_logs', ['document_version_id' => $oldVersion->id, 'status' => 'cancelled']);
        $this->assertDatabaseHas('reminder_logs', ['document_version_id' => $document->current_version_id, 'status' => 'accepted']);
        $this->actingAs($viewer);
        foreach (['table', 'grid'] as $view) {
            Livewire::withQueryParams(['view' => $view])->test(ListDocuments::class)->searchTable('PT Dummy UAT')->assertCanSeeTableRecords([$document]);
        }
        $this->assertCount(1, DocumentResource::getGlobalSearchResults('DUMMY-001'));
        Livewire::test(ViewDocument::class, ['record' => $document->id])->assertSuccessful()->assertActionHidden('updateVersion');
        Livewire::test(EditDocument::class, ['record' => $document->id])->assertForbidden();
        Livewire::test(CreateDocument::class)->assertForbidden();
        Livewire::test(ListReminderLogs::class)->assertCanSeeTableRecords(ReminderLog::all());
        Storage::disk('s3')->buildTemporaryUrlsUsing(fn (): string => 'https://storage.example.test/dummy-signed');
        $this->get(route('documents.download', ['document' => $document, 'version' => $oldVersion->id]))->assertRedirect();
        $this->get(route('documents.download', $document))->assertRedirect();
        $this->assertDatabaseHas('document_activities', ['event' => 'downloaded', 'document_version_id' => $oldVersion->id, 'user_id' => $viewer->id]);
        $this->assertDatabaseHas('document_activities', ['event' => 'downloaded', 'document_version_id' => $document->current_version_id, 'user_id' => $viewer->id]);
        $this->actingAs(User::factory()->create(['email' => 'denied@example.test']));
        Livewire::test(ListDocuments::class)->assertForbidden();
        Livewire::test(ListReminderLogs::class)->assertForbidden();
        $this->get(route('documents.download', $document))->assertForbidden();
        $this->actingAs($editor);
        Livewire::test(EditDocument::class, ['record' => $document->id])->callAction('delete');
        $this->assertSoftDeleted($document);
        Storage::disk('s3')->assertExists($oldVersion->file_path);
        Storage::disk('s3')->assertExists($document->currentVersion->file_path);
        $this->get(route('documents.download', $document))->assertNotFound();
    }

    public function test_scheduler_is_due_at_eight_wib_and_not_a_minute_before(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event): bool => str_contains($event->command ?? '', 'lms:send-reminders'));
        $this->assertNotNull($event);
        $this->assertSame('Asia/Jakarta', $event->timezone);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->isDue($this->app));
        $this->travelTo(CarbonImmutable::parse('2026-10-06 00:59:00', 'UTC'));
        $this->assertFalse($event->isDue($this->app));
        Http::assertNothingSent();
    }

    public function test_status_and_filters_follow_wib_day_before_utc_midnight(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-05 18:00:00', 'UTC'));
        $document = Document::factory()->create(['document_type_id' => DocumentType::factory()->create(['reminder_days' => [30]])->id]);
        $version = DocumentVersion::factory()->create(['document_id' => $document->id, 'expiry_date' => '2026-10-05']);
        $document->current_version_id = $version->id;
        $document->save();
        $this->assertSame('expired', $document->fresh()->expiry_status);
        $this->assertTrue(Document::query()->withExpiryStatus('expired')->whereKey($document->id)->exists());
        $this->assertFalse(Document::query()->expiringWithin(30)->whereKey($document->id)->exists());
    }
}
