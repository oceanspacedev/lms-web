<?php

namespace Tests\Feature;

use App\Filament\Resources\Documents\DocumentResource;
use App\Filament\Resources\ReminderLogs\Pages\ListReminderLogs;
use App\Filament\Resources\ReminderLogs\Pages\ViewReminderLog;
use App\Filament\Resources\ReminderLogs\ReminderLogResource;
use App\Filament\Resources\ReminderTemplates\Pages\WhatsAppDeliveryHistory;
use App\Filament\Resources\ReminderTemplates\ReminderTemplateResource;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\DocumentVersion;
use App\Models\ReminderLog;
use App\Models\ReminderTemplate;
use App\Models\User;
use App\Services\DocumentReminderSender;
use Database\Seeders\ReminderLogSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ReminderLogResendTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 9)->setTime(8, 0));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        config(['services.waghub.url' => 'https://waghub.test', 'services.waghub.token' => 'test-token', 'services.waghub.purpose' => 'test-purpose']);
        $this->seed(ReminderLogSeeder::class);
        ReminderTemplate::factory()->create(['schedule_mode' => 'specific_days', 'scheduled_days' => [30, 7], 'body' => ReminderTemplate::DEFAULT_BODY]);
        Http::preventStrayRequests();
        Http::fake(['waghub.test/*' => fn () => Http::response(['status' => 'x'], config('testing.waghub_status', 202))]);
    }

    /** @param list<string> $permissions */
    private function staff(array $permissions = ['ViewAny:ReminderTemplate', 'Update:ReminderTemplate', 'ViewAny:ReminderLog', 'View:ReminderLog']): User
    {
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $user = User::factory()->create(['name' => 'Admin Pengirim']);
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function failedReminder(): ReminderLog
    {
        $document = Document::factory()->create([
            'title' => 'Kontrak Kirim Ulang',
            'document_type_id' => DocumentType::factory()->create(['has_expiry' => true])->id,
            'pic_user_id' => User::factory()->create(['phone' => '081234567890'])->id,
        ]);
        $version = DocumentVersion::factory()->create(['document_id' => $document->id, 'expiry_date' => today('Asia/Jakarta')->addDays(30)]);
        $document->current_version_id = $version->id;
        $document->save();
        config(['testing.waghub_status' => 503]);
        app(DocumentReminderSender::class)->run();
        config(['testing.waghub_status' => 202]);

        return ReminderLog::where('document_version_id', $version->id)->sole();
    }

    /** @return list<array{title: string, body: ?string, status: ?string}> */
    private function sentNotifications(): array
    {
        return collect([...session()->pull('filament.claimed_notifications', []), ...session()->pull('filament.notifications', [])])
            ->map(fn (array $notification): array => ['title' => $notification['title'], 'body' => $notification['body'] ?? null, 'status' => $notification['status'] ?? null])->all();
    }

    public function test_list_shows_resend_only_for_failed_logs_to_users_who_can_edit_settings(): void
    {
        $failed = $this->failedReminder();
        $accepted = ReminderLog::factory()->create(['status' => 'accepted']);
        $this->actingAs($this->staff());

        Livewire::test(ListReminderLogs::class)
            ->assertActionVisible(TestAction::make('resend')->table($failed))
            ->assertActionHidden(TestAction::make('resend')->table($accepted));

        $this->actingAs($this->staff(['ViewAny:ReminderTemplate', 'ViewAny:ReminderLog', 'View:ReminderLog']));
        Livewire::test(ListReminderLogs::class)->assertActionHidden(TestAction::make('resend')->table($failed));
    }

    /** Jumlah query ke pengaturan WhatsApp global selama $callback berjalan. */
    private function globalSettingQueries(callable $callback): int
    {
        DB::enableQueryLog();
        $callback();
        $count = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'from "reminder_templates"') && str_contains($query['query'], 'global_key'))->count();
        DB::disableQueryLog();

        return $count;
    }

    public function test_resend_permission_is_checked_once_per_page_not_once_per_failed_row(): void
    {
        $logs = [ReminderLog::factory()->create(['status' => 'failed']), ReminderLog::factory()->create(['status' => 'failed']), ReminderLog::factory()->create(['status' => 'failed']), ReminderLog::factory()->create(['status' => 'failed'])];
        $this->actingAs($this->staff());

        $listQueries = $this->globalSettingQueries(fn () => Livewire::test(ListReminderLogs::class)->assertCanSeeTableRecords($logs)->assertActionVisible(TestAction::make('resend')->table($logs[0])));
        $historyQueries = $this->globalSettingQueries(fn () => Livewire::test(WhatsAppDeliveryHistory::class)->assertSee('Kirim ulang'));

        $this->assertLessThanOrEqual(2, $listQueries);
        $this->assertLessThanOrEqual(2, $historyQueries);
    }

    public function test_resend_is_hidden_until_the_global_setting_exists(): void
    {
        $failed = $this->failedReminder();
        ReminderTemplate::query()->delete();
        $this->actingAs($this->staff(['ViewAny:ReminderTemplate', 'Create:ReminderTemplate', 'Update:ReminderTemplate', 'ViewAny:ReminderLog', 'View:ReminderLog']));

        $this->assertFalse(ReminderLogResource::canResend());
        Livewire::test(ListReminderLogs::class)->assertActionHidden(TestAction::make('resend')->table($failed));
    }

    public function test_row_resend_asks_for_confirmation_then_sends_and_audits_who_resent(): void
    {
        $log = $this->failedReminder();
        $user = $this->staff();
        $this->actingAs($user);

        Livewire::test(ListReminderLogs::class)
            ->mountAction(TestAction::make('resend')->table($log))
            ->assertMountedActionModalSee('Kirim ulang pengingat?')
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $log->refresh();
        $this->assertSame('accepted', $log->status);
        $this->assertSame($user->id, $log->resent_by);
        Http::assertSent(fn ($request): bool => $request->hasHeader('Idempotency-Key', "lms-reminder-{$log->document_version_id}-{$log->offset_days}"));
        $this->assertSame([['title' => 'Pesan diterima WagHub', 'body' => 'Status ini belum memastikan pesan diterima di WhatsApp tujuan.', 'status' => 'success']], $this->sentNotifications());
    }

    public function test_row_resend_reports_failure_again_when_the_provider_still_rejects(): void
    {
        $log = $this->failedReminder();
        $this->actingAs($this->staff());
        config(['testing.waghub_status' => 503]);

        Livewire::test(ListReminderLogs::class)->callAction(TestAction::make('resend')->table($log));

        $this->assertSame('failed', $log->fresh()->status);
        $this->assertSame('Pengiriman gagal lagi', $this->sentNotifications()[0]['title']);
    }

    public function test_row_resend_reports_cancelled_when_the_version_was_replaced(): void
    {
        $log = $this->failedReminder();
        $document = $log->documentVersion->document;
        $document->currentVersion->update(['is_current' => false]);
        $new = DocumentVersion::factory()->create(['document_id' => $document->id, 'version_number' => 2, 'expiry_date' => today()->addYear()]);
        $document->current_version_id = $new->id;
        $document->save();
        $this->actingAs($this->staff());
        Http::fake();

        Livewire::test(ListReminderLogs::class)->callAction(TestAction::make('resend')->table($log));

        Http::assertNothingSent();
        $this->assertSame('cancelled', $log->fresh()->status);
        $this->assertSame([['title' => 'Pesan tidak dikirim', 'body' => 'Pengajuan atau dokumen sudah berpindah tahap, sehingga pesan ini tidak berlaku lagi.', 'status' => 'warning']], $this->sentNotifications());
    }

    public function test_resend_without_waghub_configuration_shows_a_notification_instead_of_an_error_page(): void
    {
        $log = $this->failedReminder();
        $this->actingAs($this->staff([...['ViewAny:ReminderTemplate', 'Update:ReminderTemplate', 'ViewAny:ReminderLog', 'View:ReminderLog'], 'ViewAny:DocumentRequest', 'View:DocumentRequest']));
        config(['services.waghub.token' => '']);
        Http::fake();
        $expected = [['title' => 'Konfigurasi WagHub belum lengkap', 'body' => 'Pesan belum dikirim ulang. Hubungi administrator.', 'status' => 'danger']];

        Livewire::test(ListReminderLogs::class)->callAction(TestAction::make('resend')->table($log))->assertHasNoActionErrors();
        $this->assertSame($expected, $this->sentNotifications());

        Livewire::test(ViewReminderLog::class, ['record' => $log->id])->callAction('resend')->assertHasNoActionErrors();
        $this->assertSame($expected, $this->sentNotifications());

        Livewire::test(WhatsAppDeliveryHistory::class)->call('resend', 'reminder', $log->id)->assertSuccessful();
        $this->assertSame($expected, $this->sentNotifications());

        Http::assertNothingSent();
        $this->assertSame(['failed', null], [$log->fresh()->status, $log->fresh()->resent_by]);
    }

    public function test_log_of_a_deleted_document_still_names_the_document_and_explains_why_it_was_not_resent(): void
    {
        $log = $this->failedReminder();
        $document = $log->documentVersion->document;
        $document->delete();
        $this->actingAs($this->staff([...['ViewAny:ReminderTemplate', 'Update:ReminderTemplate', 'ViewAny:ReminderLog', 'View:ReminderLog'], 'View:Document']));

        Livewire::test(ListReminderLogs::class)
            ->assertTableColumnStateSet('documentVersion.document.title', 'Kontrak Kirim Ulang', $log)
            ->assertTableColumnStateSet('documentVersion.document.company.name', $document->company->name, $log)
            ->assertSee('Dokumen dihapus')
            ->searchTable('Kirim Ulang')->assertCanSeeTableRecords([$log])
            ->searchTable(null)
            ->callAction(TestAction::make('resend')->table($log));

        $this->assertSame(['cancelled', ReminderLog::DOCUMENT_DELETED_MESSAGE], [$log->fresh()->status, $log->fresh()->error_message]);
        $this->assertSame([['title' => 'Pesan tidak dikirim', 'body' => 'Dokumennya sudah dihapus. Pulihkan dokumen agar pengingatnya berjalan lagi.', 'status' => 'warning']], $this->sentNotifications());

        Livewire::test(ViewReminderLog::class, ['record' => $log->id])
            ->assertSee('Kontrak Kirim Ulang')->assertSee('Dokumen ini sudah dihapus. Pulihkan dokumen agar pengingatnya berjalan lagi.')
            ->assertSee(DocumentResource::getUrl('view', ['record' => $document]));
    }

    public function test_outcome_mapping_is_shared_and_covers_the_unavailable_case(): void
    {
        foreach ([null, 'unknown'] as $outcome) {
            ReminderLogResource::notifyResendOutcome($outcome);
            $this->assertSame([['title' => 'Pesan ini tidak dapat dikirim ulang', 'body' => 'Hanya pesan berstatus Gagal yang dapat dikirim ulang.', 'status' => 'warning']], $this->sentNotifications());
        }
        $this->assertSame(['accepted' => 'Pesan diterima WagHub', 'failed' => 'Pengiriman gagal lagi', 'cancelled' => 'Pesan tidak dikirim'], collect(['accepted', 'failed', 'cancelled'])
            ->mapWithKeys(function (string $outcome): array {
                ReminderLogResource::notifyResendOutcome($outcome);

                return [$outcome => $this->sentNotifications()[0]['title']];
            })->all());
    }

    public function test_history_page_uses_the_same_outcome_notification(): void
    {
        $log = $this->failedReminder();
        $this->actingAs($this->staff());

        Livewire::test(WhatsAppDeliveryHistory::class)->call('resend', 'reminder', $log->id);

        $this->assertSame('accepted', $log->fresh()->status);
        $this->assertSame('Pesan diterima WagHub', $this->sentNotifications()[0]['title']);
    }

    public function test_user_without_settings_permission_cannot_resend_even_by_forcing_the_action(): void
    {
        $log = $this->failedReminder();
        $this->actingAs($this->staff(['ViewAny:ReminderTemplate', 'ViewAny:ReminderLog', 'View:ReminderLog']));
        Http::fake();

        Livewire::test(ListReminderLogs::class)->mountAction(TestAction::make('resend')->table($log))->callMountedAction();

        Http::assertNothingSent();
        $log->refresh();
        $this->assertSame('failed', $log->status);
        $this->assertNull($log->resent_by);
    }

    public function test_view_page_has_resend_for_failed_logs_only(): void
    {
        $failed = $this->failedReminder();
        $accepted = ReminderLog::factory()->create(['status' => 'accepted']);
        $this->actingAs($this->staff());

        Livewire::test(ViewReminderLog::class, ['record' => $accepted->id])->assertActionHidden('resend');

        Livewire::test(ViewReminderLog::class, ['record' => $failed->id])->assertActionVisible('resend')
            ->mountAction('resend')->assertMountedActionModalSee('Kirim ulang pengingat?')
            ->callMountedAction()->assertSee('Diterima WagHub');
        $this->assertSame('accepted', $failed->fresh()->status);

        $this->actingAs($this->staff(['ViewAny:ReminderTemplate', 'ViewAny:ReminderLog', 'View:ReminderLog']));
        Livewire::test(ViewReminderLog::class, ['record' => $this->failedReminderAgain()->id])->assertActionHidden('resend');
    }

    private function failedReminderAgain(): ReminderLog
    {
        return ReminderLog::factory()->create(['status' => 'failed', 'error_message' => 'WagHub menolak permintaan (HTTP 503).']);
    }

    public function test_list_links_to_the_history_page_prefiltered_to_reminders(): void
    {
        $this->actingAs($this->staff());

        Livewire::test(ListReminderLogs::class)
            ->assertSee('Riwayat pengiriman WhatsApp')->assertActionVisible('delivery_history')->assertActionHasLabel('delivery_history', 'Riwayat pengiriman WhatsApp')
            ->assertActionHasUrl('delivery_history', ReminderTemplateResource::getUrl('history', ['source' => 'reminder']))
            ->filterTable('status', 'failed')
            ->assertActionHasUrl('delivery_history', ReminderTemplateResource::getUrl('history', ['source' => 'reminder', 'deliveryStatus' => 'failed']));
    }

    public function test_history_link_carries_the_status_filter_that_arrived_through_the_dashboard_link(): void
    {
        $this->actingAs($this->staff());

        $list = Livewire::withQueryParams(['filters' => ['status' => ['value' => 'failed']]])->test(ListReminderLogs::class);
        Livewire::withQueryParams([]);

        $list->assertActionHasUrl('delivery_history', ReminderTemplateResource::getUrl('history', ['source' => 'reminder', 'deliveryStatus' => 'failed']));
    }

    public function test_history_link_is_hidden_from_users_who_cannot_open_the_history_page(): void
    {
        $this->actingAs($this->staff(['ViewAny:ReminderLog', 'View:ReminderLog']));

        Livewire::test(ListReminderLogs::class)->assertSuccessful()->assertActionHidden('delivery_history')->assertDontSee('Riwayat pengiriman WhatsApp');
    }

    public function test_history_filters_are_bound_to_the_url(): void
    {
        $failed = $this->failedReminder();
        ReminderLog::factory()->create(['status' => 'accepted', 'request_payload' => ['message' => ['text' => 'PESAN DITERIMA']]]);
        $this->actingAs($this->staff());

        Livewire::withQueryParams(['source' => 'reminder', 'deliveryStatus' => 'failed'])->test(WhatsAppDeliveryHistory::class)
            ->assertSet('source', 'reminder')->assertSet('deliveryStatus', 'failed')
            ->assertSee('Kirim ulang')->assertDontSee('PESAN DITERIMA');
        $this->assertSame('failed', $failed->fresh()->status);

        Livewire::withQueryParams([])->test(WhatsAppDeliveryHistory::class)->assertSet('source', 'all')->assertSet('deliveryStatus', 'all');
    }

    public function test_history_ignores_unknown_or_unauthorized_filters_from_the_url(): void
    {
        $this->actingAs($this->staff(['ViewAny:ReminderTemplate', 'Update:ReminderTemplate']));

        Livewire::withQueryParams(['source' => 'reminder', 'deliveryStatus' => 'bukan-status'])->test(WhatsAppDeliveryHistory::class)
            ->assertSet('source', 'all')->assertSet('deliveryStatus', 'all');
    }
}
