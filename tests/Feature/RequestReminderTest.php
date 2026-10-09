<?php

namespace Tests\Feature;

use App\Models\DocumentRequest;
use App\Models\DocumentRequestNotification;
use App\Models\ReminderTemplate;
use App\Models\User;
use App\Services\DocumentRequestNotifier;
use App\Services\DocumentRequestReminderSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class RequestReminderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 9)->setTime(8, 0));
        config(['services.waghub.url' => 'https://waghub.test', 'services.waghub.token' => 'test-token', 'services.waghub.purpose' => 'test-purpose']);
        Permission::findOrCreate('Approve:DocumentRequest', 'web');
        Http::preventStrayRequests();
        Http::fake(['waghub.test/*' => Http::response(['status' => 'accepted'], 202)]);
    }

    /** @param array<string, mixed> $attributes */
    private function request(string $status = 'submitted', int $waitingDays = 2, array $attributes = []): DocumentRequest
    {
        return DocumentRequest::factory()->create([
            'status' => $status, 'requester_name' => 'Pengaju Publik', 'title' => 'Kontrak Vendor',
            'history' => [['status' => $status, 'user' => 'Pengaju', 'user_id' => null, 'at' => now()->subDays($waitingDays)->toIso8601String(), 'note' => null]],
            ...$attributes,
        ]);
    }

    private function user(string $phone = '081234567890'): User
    {
        return User::factory()->create(['phone' => $phone]);
    }

    private function sendReminders(): int
    {
        return app(DocumentRequestReminderSender::class)->run();
    }

    public function test_nothing_is_scheduled_before_the_first_threshold(): void
    {
        $this->request(waitingDays: 1, attributes: ['reviewer_id' => $this->user()->id]);

        $this->assertSame(0, $this->sendReminders());
        $this->assertDatabaseCount('document_request_notifications', 0);
    }

    public function test_reviewer_is_reminded_with_waiting_days_and_link(): void
    {
        $reviewer = $this->user('081234567891');
        $request = $this->request(waitingDays: 2, attributes: ['reviewer_id' => $reviewer->id]);

        $this->assertSame(1, $this->sendReminders());
        $notification = DocumentRequestNotification::sole();
        $this->assertSame('reviewer', $notification->recipient_kind);
        $this->assertSame('6281234567891', $notification->recipient_phone);
        $this->assertSame("lms-request-{$request->id}-1-stale1-u{$reviewer->id}", $notification->event_key);
        $text = $notification->payload['message']['text'];
        $this->assertStringContainsString('Kontrak Vendor', $text);
        $this->assertStringContainsString('Menunggu 2 hari', $text);
        $this->assertStringContainsString('Tahap: Diperiksa', $text);
        $this->assertStringContainsString(route('filament.admin.resources.document-requests.edit', ['record' => $request->id]), $text);
    }

    public function test_reminders_repeat_on_interval_without_duplicates_and_stop_at_maximum(): void
    {
        $request = $this->request(waitingDays: 2, attributes: ['reviewer_id' => $this->user()->id]);
        $this->assertSame(1, $this->sendReminders());
        $this->assertSame(0, $this->sendReminders());

        $this->travel(1)->days();
        $this->assertSame(0, $this->sendReminders());

        $this->travel(1)->days();
        $this->assertSame(1, $this->sendReminders());
        $this->travel(2)->days();
        $this->assertSame(1, $this->sendReminders());
        $this->travel(10)->days();
        $this->assertSame(0, $this->sendReminders());
        $this->assertSame(3, $request->notifications()->count());
    }

    public function test_missed_runs_send_only_the_latest_due_reminder(): void
    {
        $request = $this->request(waitingDays: 5, attributes: ['reviewer_id' => $this->user()->id]);

        $this->assertSame(1, $this->sendReminders());
        $this->assertStringEndsWith('-stale2-u'.$request->reviewer_id, DocumentRequestNotification::sole()->event_key);
    }

    public function test_reviewer_falls_back_to_pic_when_not_assigned(): void
    {
        $pic = $this->user('081200000001');
        $this->request(attributes: ['reviewer_id' => null, 'pic_user_id' => $pic->id]);

        $this->sendReminders();

        $this->assertSame('6281200000001', DocumentRequestNotification::sole()->recipient_phone);
    }

    public function test_review_stage_reminds_the_approver_or_all_approvers_when_unassigned(): void
    {
        $approver = $this->user('081200000002');
        $this->request('review', attributes: ['approver_id' => $approver->id]);
        $this->sendReminders();
        $first = DocumentRequestNotification::sole();
        $this->assertSame('approver', $first->recipient_kind);
        $this->assertSame('6281200000002', $first->recipient_phone);
        $this->assertStringContainsString('Tahap: Menunggu Persetujuan', $first->payload['message']['text']);

        DocumentRequestNotification::query()->delete();
        DocumentRequest::query()->update(['approver_id' => null]);
        $this->user('081200000003')->givePermissionTo('Approve:DocumentRequest');
        $this->user('081200000004')->givePermissionTo('Approve:DocumentRequest');
        $this->user('081200000005');
        $this->sendReminders();

        $this->assertEqualsCanonicalizing(['6281200000003', '6281200000004'], DocumentRequestNotification::pluck('recipient_phone')->all());
    }

    public function test_only_requests_waiting_for_staff_are_reminded(): void
    {
        foreach (['draft', 'revision', 'approved', 'rejected', 'archived'] as $status) {
            $this->request($status, 10, ['reviewer_id' => $this->user()->id, 'approver_id' => $this->user()->id]);
        }

        $this->assertSame(0, $this->sendReminders());
    }

    public function test_disabled_setting_and_custom_schedule_are_respected(): void
    {
        $this->request(waitingDays: 1, attributes: ['reviewer_id' => $this->user()->id]);
        $setting = ReminderTemplate::factory()->create(['request_reminder_enabled' => false]);
        $this->assertSame(0, $this->sendReminders());

        $setting->update(['request_reminder_enabled' => true, 'request_reminder_after_days' => 1, 'request_reminder_interval_days' => 1, 'request_reminder_max' => 2]);
        $this->assertSame(1, $this->sendReminders());
        $this->travel(5)->days();
        $this->assertSame(1, $this->sendReminders());
        $this->assertSame(2, DocumentRequestNotification::count());
    }

    public function test_custom_template_is_used_and_validated(): void
    {
        $setting = ReminderTemplate::factory()->create(['request_reminder_body' => 'LEGAL #{nomor_pengajuan} {judul} {hari_menunggu} hari {status}']);
        $this->request(waitingDays: 3, attributes: ['reviewer_id' => $this->user()->id]);
        $this->sendReminders();
        $this->assertSame('LEGAL #'.DocumentRequest::first()->id.' Kontrak Vendor 3 hari Diperiksa', DocumentRequestNotification::sole()->payload['message']['text']);

        $this->expectException(ValidationException::class);
        $setting->update(['request_reminder_body' => 'Halo {pic}']);
    }

    public function test_settings_validation_rejects_out_of_range_values(): void
    {
        $setting = ReminderTemplate::factory()->create();

        foreach ([['request_reminder_after_days' => 0], ['request_reminder_interval_days' => 366], ['request_reminder_max' => 11]] as $invalid) {
            try {
                $setting->update($invalid);
                $this->fail('Nilai di luar rentang seharusnya ditolak.');
            } catch (ValidationException) {
                $this->assertSame(2, $setting->fresh()->request_reminder_after_days);
            }
        }
    }

    public function test_queued_reminder_is_sent_by_notifier_and_cancelled_when_status_changes(): void
    {
        $reviewer = $this->user();
        $request = $this->request(waitingDays: 2, attributes: ['reviewer_id' => $reviewer->id]);
        $this->sendReminders();
        app(DocumentRequestNotifier::class)->run($request->id);
        Http::assertSentCount(1);
        Http::assertSent(fn ($outgoing): bool => $outgoing['recipient']['value'] === '6281234567890' && str_contains($outgoing['message']['text'], 'Menunggu 2 hari'));
        $this->assertSame('accepted', DocumentRequestNotification::sole()->status);

        DocumentRequestNotification::query()->delete();
        $this->travel(2)->days();
        $this->sendReminders();
        $request->forceFill(['history' => [...$request->history, ['status' => 'review', 'at' => now()->toIso8601String()]], 'status' => 'review'])->save();
        app(DocumentRequestNotifier::class)->run($request->id);
        Http::assertSentCount(1);
        $this->assertSame('cancelled', DocumentRequestNotification::sole()->status);
    }

    public function test_missing_phone_is_recorded_as_failed_notification(): void
    {
        $this->request(attributes: ['reviewer_id' => User::factory()->create(['phone' => null])->id]);
        $this->sendReminders();
        app(DocumentRequestNotifier::class)->run();

        Http::assertNothingSent();
        $this->assertSame('failed', DocumentRequestNotification::sole()->status);
    }

    public function test_command_creates_reminders(): void
    {
        $this->request(attributes: ['reviewer_id' => $this->user()->id]);

        $this->artisan('lms:send-request-reminders')->expectsOutputToContain('1 pengingat pengajuan')->assertSuccessful();
    }
}
