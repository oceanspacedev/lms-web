<?php

namespace Tests\Feature;

use App\Filament\Widgets\ReminderHealthAlerts;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\DocumentVersion;
use App\Models\ReminderLog;
use App\Models\ReminderTemplate;
use App\Models\User;
use App\Services\ReminderHealthChecker;
use Database\Seeders\ReminderLogSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ReminderHealthAlertsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 6)->setTime(1, 0));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->seed(ReminderLogSeeder::class);
        Permission::findOrCreate('ViewAny:ReminderTemplate', 'web');
    }

    private function activeTemplate(): ReminderTemplate
    {
        return ReminderTemplate::factory()->create(['schedule_mode' => 'specific_days', 'scheduled_days' => [30, 7], 'body' => ReminderTemplate::DEFAULT_BODY]);
    }

    private function document(?string $picPhone = '081234567890', int $days = 30): Document
    {
        $document = Document::factory()->create([
            'document_type_id' => DocumentType::factory()->create(['has_expiry' => true])->id,
            'pic_user_id' => User::factory()->create(['phone' => $picPhone])->id,
        ]);
        $version = DocumentVersion::factory()->create(['document_id' => $document->id, 'expiry_date' => today('Asia/Jakarta')->addDays($days)]);
        $document->current_version_id = $version->id;
        $document->save();

        return $document;
    }

    /** @return list<string> */
    private function keys(): array
    {
        return array_column(app(ReminderHealthChecker::class)->alerts(), 'key');
    }

    public function test_healthy_setup_has_no_alerts(): void
    {
        $this->activeTemplate();
        $this->document();

        $this->assertSame([], app(ReminderHealthChecker::class)->alerts());
    }

    public function test_missing_inactive_or_unscheduled_template_raises_alert(): void
    {
        $this->assertSame(['template'], $this->keys());

        $template = $this->activeTemplate();
        $template->update(['is_active' => false]);
        $this->assertSame(['template'], $this->keys());

        $template->update(['is_active' => true, 'schedule_mode' => 'inherit']);
        $this->assertSame(['template'], $this->keys());
    }

    public function test_pic_without_valid_phone_is_warning_with_fallback_and_danger_without(): void
    {
        $this->activeTemplate();
        $this->document(picPhone: null);
        $this->document(picPhone: 'bukan-nomor');
        $this->document(picPhone: '081234567890');

        $alert = app(ReminderHealthChecker::class)->alerts()[0];
        $this->assertSame('danger', $alert['level']);
        $this->assertStringContainsString('2 dokumen', $alert['message']);

        User::factory()->create(['phone' => '081111111111'])->givePermissionTo('receive_reminder');
        $alert = app(ReminderHealthChecker::class)->alerts()[0];
        $this->assertSame('warning', $alert['level']);
        $this->assertStringContainsString('penerima cadangan', $alert['message']);
    }

    public function test_expired_or_non_expiring_documents_do_not_count_for_recipient_alert(): void
    {
        $this->activeTemplate();
        $this->document(picPhone: null, days: -1);
        $nonExpiring = $this->document(picPhone: null);
        $nonExpiring->documentType->update(['has_expiry' => false]);

        $this->assertSame([], $this->keys());
    }

    public function test_failed_reminders_raise_alert_with_count(): void
    {
        $this->activeTemplate();
        $version = $this->document()->currentVersion;
        ReminderLog::factory()->create(['document_version_id' => $version->id, 'offset_days' => 30, 'status' => 'failed']);
        ReminderLog::factory()->create(['document_version_id' => $version->id, 'offset_days' => 7, 'status' => 'accepted']);

        $alerts = app(ReminderHealthChecker::class)->alerts();
        $this->assertSame(['failed'], array_column($alerts, 'key'));
        $this->assertStringContainsString('1 pengingat gagal', $alerts[0]['message']);
    }

    public function test_widget_renders_alerts_and_links_only_for_permitted_users(): void
    {
        $this->actingAs(tap(User::factory()->create())->givePermissionTo(['ViewAny:ReminderLog', 'View:ReminderLog']));
        Livewire::test(ReminderHealthAlerts::class)
            ->assertSee('Pengingat WhatsApp belum aktif')
            ->assertDontSee('Periksa');

        $this->actingAs(tap(User::factory()->create())->givePermissionTo('ViewAny:ReminderTemplate'));
        Livewire::test(ReminderHealthAlerts::class)
            ->assertSee('Pengingat WhatsApp belum aktif')
            ->assertSee('Periksa');
    }

    public function test_widget_is_hidden_from_users_without_reminder_permissions(): void
    {
        $this->actingAs(User::factory()->create());

        $this->assertFalse(ReminderHealthAlerts::canView());
    }

    public function test_widget_shows_nothing_when_healthy(): void
    {
        $this->activeTemplate();
        $this->actingAs(tap(User::factory()->create())->givePermissionTo('ViewAny:ReminderTemplate'));

        Livewire::test(ReminderHealthAlerts::class)->assertDontSee('Perlu Perhatian');
    }
}
