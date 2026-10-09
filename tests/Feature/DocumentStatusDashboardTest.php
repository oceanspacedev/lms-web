<?php

namespace Tests\Feature;

use App\Filament\Resources\Documents\Pages\ListDocuments;
use App\Filament\Widgets\DocumentStatusOverview;
use App\Filament\Widgets\ExpiringDocuments;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\DocumentVersion;
use App\Models\ReminderTemplate;
use App\Models\User;
use Database\Seeders\DocumentSeeder;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DocumentStatusDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 5)->setTime(15, 0));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->seed(DocumentSeeder::class);
    }

    /** Jadwal pengingat berlaku global untuk semua dokumen; daftar kosong berarti tidak ada jadwal. */
    private function useReminderDays(array $days): void
    {
        $attributes = $days === [] ? ['schedule_mode' => 'inherit', 'scheduled_days' => []] : ['schedule_mode' => 'specific_days', 'scheduled_days' => $days];
        $template = ReminderTemplate::globalSetting();
        $template ? $template->update($attributes) : ReminderTemplate::factory()->create($attributes);
    }

    private function document(?string $expiryDate, array $reminderDays = [90, 60, 30], bool $hasExpiry = true): Document
    {
        if ($hasExpiry) {
            $this->useReminderDays($reminderDays);
        }
        $type = DocumentType::factory()->create(['has_expiry' => $hasExpiry]);
        $document = Document::factory()->create(['document_type_id' => $type->id]);
        $version = DocumentVersion::factory()->create(['document_id' => $document->id, 'expiry_date' => $expiryDate]);
        $document->current_version_id = $version->id;
        $document->save();

        return $document;
    }

    private function signIn(bool $canViewRecord = true): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo($canViewRecord ? ['ViewAny:Document', 'View:Document'] : ['ViewAny:Document']);
        $this->actingAs($user);
    }

    public static function statusCases(): array
    {
        return [
            'past' => ['2026-10-04', [30], true, 'expired'],
            'today' => ['2026-10-05', [30], true, 'expiring'],
            'threshold' => ['2026-11-04', [30, 10], true, 'expiring'],
            'outside threshold' => ['2026-11-05', [30, 10], true, 'active'],
            'first reminder is largest' => ['2026-12-04', [10, 60, 30], true, 'expiring'],
            'no reminders' => ['2026-10-06', [], true, 'active'],
            'today without reminders' => ['2026-10-05', [], true, 'active'],
            'no expiry date' => [null, [30], true, 'no_expiry'],
            'type without expiry' => ['2026-10-04', [], false, 'no_expiry'],
        ];
    }

    #[DataProvider('statusCases')]
    public function test_status_and_database_filter_agree_on_date_boundaries(?string $expiry, array $reminders, bool $hasExpiry, string $expected): void
    {
        $document = $this->document($expiry, $reminders, $hasExpiry);
        $this->assertSame($expected, $document->expiry_status);
        foreach (array_keys(Document::EXPIRY_STATUSES) as $status) {
            $this->assertSame($status === $expected, Document::query()->withExpiryStatus($status)->whereKey($document->id)->exists());
        }
    }

    public function test_status_tracks_current_version_and_reminder_schedule_changes(): void
    {
        $document = $this->document('2026-12-04', [30]);
        $this->assertSame('active', $document->expiry_status);
        $this->useReminderDays([90]);
        $this->assertSame('expiring', $document->fresh()->expiry_status);
        $old = $document->currentVersion;
        $old->update(['is_current' => false]);
        $new = DocumentVersion::factory()->create(['document_id' => $document->id, 'version_number' => 2, 'expiry_date' => '2027-10-05']);
        $document->current_version_id = $new->id;
        $document->save();
        $this->assertSame('active', $document->fresh()->expiry_status);
        $this->assertFalse(Document::query()->withExpiryStatus('expiring')->whereKey($document->id)->exists());
    }

    public function test_status_changes_after_midnight_without_database_update(): void
    {
        $document = $this->document('2026-10-05', [30]);
        $this->assertSame('expiring', $document->expiry_status);
        $this->travelTo(today()->addDay()->addMinutes(1));
        $this->assertSame('expired', $document->expiry_status);
        $this->assertTrue(Document::query()->withExpiryStatus('expired')->whereKey($document->id)->exists());
    }

    public function test_table_badges_and_status_filters_work_with_other_filters(): void
    {
        $this->signIn();
        $active = $this->document('2027-10-05');
        $expiring = $this->document('2026-10-20');
        $expired = $this->document('2026-10-04');
        $noExpiry = $this->document(null, [], false);
        $records = collect([$active, $expiring, $expired, $noExpiry]);
        $page = Livewire::test(ListDocuments::class)->assertCanSeeTableRecords($records);
        foreach ($records as $document) {
            $page->assertTableColumnStateSet('expiry_status', $document->expiry_status, $document);
        }
        $page->filterTable('expiry_status', 'expiring')->assertCanSeeTableRecords([$expiring])
            ->assertCanNotSeeTableRecords([$active, $expired, $noExpiry])
            ->filterTable('company', $active->company_id)->assertCanNotSeeTableRecords($records);
    }

    public function test_dashboard_counts_exclude_deleted_documents_and_link_to_filters(): void
    {
        $this->signIn();
        $this->document('2027-10-05');
        $this->document('2026-10-20');
        $this->document('2026-10-04');
        $this->document(null, [], false);
        $this->document('2027-10-05')->delete();
        $component = Livewire::test(DocumentStatusOverview::class)->assertSuccessful()->assertSee('Masa Berlaku Dokumen');
        $stats = (new ReflectionMethod(DocumentStatusOverview::class, 'getStats'))->invoke($component->instance());
        $this->assertCount(4, $stats);
        foreach ($stats as $stat) {
            $this->assertSame(1, $stat->getValue());
            $this->assertStringContainsString('tableFilters', $stat->getUrl());
        }
        $widgets = Livewire::test(Dashboard::class)->instance()->getVisibleWidgets();
        $this->assertContains(DocumentStatusOverview::class, $widgets);
        $this->assertContains(ExpiringDocuments::class, $widgets);
    }

    public function test_thirty_day_list_includes_endpoints_and_excludes_old_versions_and_deleted_records(): void
    {
        $this->signIn();
        $today = $this->document('2026-10-05');
        $last = $this->document('2026-11-04', [10]);
        $outside = $this->document('2026-11-05');
        $expired = $this->document('2026-10-04');
        $noExpiry = $this->document(null, [], false);
        $deleted = $this->document('2026-10-06');
        $deleted->delete();
        DocumentVersion::factory()->create(['document_id' => $outside->id, 'version_number' => 2, 'is_current' => false, 'expiry_date' => '2026-10-10']);
        Livewire::test(ExpiringDocuments::class)->assertSuccessful()
            ->assertCanSeeTableRecords([$today, $last], inOrder: true)
            ->assertCanNotSeeTableRecords([$outside, $expired, $noExpiry, $deleted]);
    }

    public function test_widgets_are_hidden_and_inaccessible_without_document_permissions(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('panel_user', 'web'));
        $this->actingAs($user);
        $this->assertFalse(DocumentStatusOverview::canView());
        $this->assertFalse(ExpiringDocuments::canView());
        $this->assertSame([], Livewire::test(Dashboard::class)->instance()->getVisibleWidgets());
        Livewire::test(DocumentStatusOverview::class)->assertForbidden();
        Livewire::test(ExpiringDocuments::class)->assertForbidden();
    }

    public function test_list_only_permission_does_not_show_view_action_and_empty_dashboard_is_valid(): void
    {
        $this->signIn(false);
        $overview = Livewire::test(DocumentStatusOverview::class)->assertSuccessful();
        $stats = (new ReflectionMethod(DocumentStatusOverview::class, 'getStats'))->invoke($overview->instance());
        foreach ($stats as $stat) {
            $this->assertSame(0, $stat->getValue());
        }
        Livewire::test(ExpiringDocuments::class)->assertSee('Tidak ada dokumen yang berakhir dalam 30 hari');
        $document = $this->document('2026-10-10');
        Livewire::test(ExpiringDocuments::class)->assertCanSeeTableRecords([$document])->assertTableActionHidden('view', $document);
    }
}
