<?php

namespace Tests\Feature;

use App\Filament\Resources\ReminderTemplates\Pages\WhatsAppDeliveryHistory;
use App\Models\Document;
use App\Models\DocumentRequest;
use App\Models\DocumentRequestNotification;
use App\Models\DocumentType;
use App\Models\DocumentVersion;
use App\Models\ReminderLog;
use App\Models\ReminderTemplate;
use App\Models\User;
use App\Services\DocumentReminderSender;
use App\Services\DocumentRequestNotifier;
use Database\Seeders\ReminderLogSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class DeliveryResendTest extends TestCase
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
    private function staff(array $permissions = ['ViewAny:ReminderTemplate', 'Update:ReminderTemplate', 'ViewAny:ReminderLog', 'View:ReminderLog', 'ViewAny:DocumentRequest', 'View:DocumentRequest', 'ViewAll:DocumentRequest']): User
    {
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $user = User::factory()->create(['name' => 'Admin Pengirim', 'phone' => '081200000099']);
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function document(?string $picPhone = '081234567890', int $days = 30): Document
    {
        $document = Document::factory()->create([
            'title' => 'Kontrak Kirim Ulang',
            'document_type_id' => DocumentType::factory()->create(['has_expiry' => true])->id,
            'pic_user_id' => User::factory()->create(['phone' => $picPhone])->id,
        ]);
        $version = DocumentVersion::factory()->create(['document_id' => $document->id, 'expiry_date' => today('Asia/Jakarta')->addDays($days)]);
        $document->current_version_id = $version->id;
        $document->save();

        return $document->refresh();
    }

    private function failedReminder(?string $picPhone = '081234567890'): ReminderLog
    {
        $document = $this->document($picPhone);
        config(['testing.waghub_status' => 503]);
        app(DocumentReminderSender::class)->run();
        config(['testing.waghub_status' => 202]);

        return ReminderLog::where('document_version_id', $document->current_version_id)->sole();
    }

    private function failedNotification(string $phone = '6281234567890'): DocumentRequestNotification
    {
        $request = DocumentRequest::factory()->create([
            'status' => 'submitted', 'requester_name' => 'Pengaju', 'requester_phone' => '081234567890',
            'history' => [['status' => 'submitted', 'user' => 'Pengaju', 'user_id' => null, 'at' => now()->toIso8601String(), 'note' => null]],
        ]);

        return DocumentRequestNotification::factory()->create([
            'document_request_id' => $request->id, 'event_key' => "lms-request-{$request->id}-1-applicant", 'recipient_kind' => 'applicant',
            'recipient_phone' => $phone, 'status' => 'failed', 'attempts' => 5, 'error_message' => 'Pengiriman WhatsApp gagal. Akan dicoba kembali.',
            'payload' => ['recipient' => ['type' => 'phone', 'value' => $phone], 'message' => ['type' => 'text', 'text' => 'Pesan pengajuan'], 'purpose' => 'test-purpose', 'client_reference' => "lms-request-{$request->id}-1-applicant"],
        ]);
    }

    public function test_failed_reminder_is_resent_with_same_key_and_payload_and_audited(): void
    {
        $log = $this->failedReminder();
        $this->assertSame('failed', $log->status);
        $payload = $log->request_payload;
        $user = $this->staff();

        $this->assertSame('accepted', app(DocumentReminderSender::class)->resend($log, $user));

        $log->refresh();
        $this->assertSame('accepted', $log->status);
        $this->assertSame(1, $log->attempts);
        $this->assertSame($payload, $log->request_payload);
        $this->assertSame($user->id, $log->resent_by);
        $this->assertNotNull($log->resent_at);
        Http::assertSent(fn ($request): bool => $request->hasHeader('Idempotency-Key', "lms-reminder-{$log->document_version_id}-{$log->offset_days}") && $request['message']['text'] === $payload['message']['text']);
    }

    public function test_resend_resets_exhausted_attempts_and_ignores_same_day_retry_limit(): void
    {
        $log = $this->failedReminder();
        $log->update(['attempts' => 3, 'last_attempt_at' => now()]);

        $this->assertSame('accepted', app(DocumentReminderSender::class)->resend($log, $this->staff()));
    }

    public function test_resend_uses_the_corrected_pic_phone(): void
    {
        $log = $this->failedReminder(picPhone: null);
        $this->assertNull($log->request_payload);
        $log->documentVersion->document->pic->update(['phone' => '081355550000']);

        $this->assertSame('accepted', app(DocumentReminderSender::class)->resend($log, $this->staff()));

        $this->assertSame('6281355550000', $log->fresh()->recipient_phone);
        Http::assertSent(fn ($request): bool => $request['recipient']['value'] === '6281355550000');
    }

    public function test_resend_only_applies_to_failed_logs(): void
    {
        $log = $this->failedReminder();
        $log->update(['status' => 'accepted']);
        Http::fake();

        $this->assertNull(app(DocumentReminderSender::class)->resend($log, $this->staff()));
        Http::assertNothingSent();
        $this->assertNull($log->fresh()->resent_by);
    }

    public function test_resend_cancels_reminder_when_the_version_was_replaced(): void
    {
        $log = $this->failedReminder();
        $document = $log->documentVersion->document;
        $document->currentVersion->update(['is_current' => false]);
        $new = DocumentVersion::factory()->create(['document_id' => $document->id, 'version_number' => 2, 'expiry_date' => today()->addYear()]);
        $document->current_version_id = $new->id;
        $document->save();
        Http::fake();

        $this->assertSame('cancelled', app(DocumentReminderSender::class)->resend($log, $this->staff()));
        Http::assertNothingSent();
        $this->assertSame('cancelled', $log->fresh()->status);
    }

    public function test_resend_reports_failure_again_when_provider_still_rejects(): void
    {
        $log = $this->failedReminder();
        config(['testing.waghub_status' => 503]);

        $this->assertSame('failed', app(DocumentReminderSender::class)->resend($log, $this->staff()));
        $this->assertSame('failed', $log->fresh()->status);
    }

    public function test_failed_notification_is_resent_after_attempts_are_exhausted(): void
    {
        $notification = $this->failedNotification();
        $user = $this->staff();

        $this->assertSame('accepted', app(DocumentRequestNotifier::class)->resend($notification, $user));

        $notification->refresh();
        $this->assertSame('accepted', $notification->status);
        $this->assertSame(1, $notification->attempts);
        $this->assertSame($user->id, $notification->resent_by);
        Http::assertSent(fn ($request): bool => $request->hasHeader('Idempotency-Key', $notification->event_key) && $request['recipient']['value'] === '6281234567890');
    }

    public function test_notification_resend_uses_the_corrected_applicant_phone(): void
    {
        $notification = $this->failedNotification(phone: '');
        $notification->update(['recipient_phone' => null]);
        $notification->documentRequest()->update(['requester_phone' => '081377770000']);

        $this->assertSame('accepted', app(DocumentRequestNotifier::class)->resend($notification, $this->staff()));

        $this->assertSame('6281377770000', $notification->fresh()->recipient_phone);
        Http::assertSent(fn ($request): bool => $request['recipient']['value'] === '6281377770000');
    }

    public function test_notification_from_an_earlier_stage_is_cancelled_instead_of_sent(): void
    {
        $notification = $this->failedNotification();
        $request = DocumentRequest::find($notification->document_request_id);
        $request->forceFill(['status' => 'review', 'history' => [...$request->history, ['status' => 'review', 'at' => now()->toIso8601String()]]])->save();
        Http::fake();

        $this->assertSame('cancelled', app(DocumentRequestNotifier::class)->resend($notification, $this->staff()));
        Http::assertNothingSent();
    }

    public function test_notification_resend_only_applies_to_failed_notifications(): void
    {
        $notification = $this->failedNotification();
        $notification->update(['status' => 'accepted']);
        Http::fake();

        $this->assertNull(app(DocumentRequestNotifier::class)->resend($notification, $this->staff()));
        Http::assertNothingSent();
    }

    public function test_history_shows_resend_button_only_for_failed_rows_for_permitted_users(): void
    {
        $log = $this->failedReminder();
        $this->failedNotification();
        $accepted = $this->failedReminder();
        $accepted->update(['status' => 'accepted']);
        $this->actingAs($this->staff());

        Livewire::test(WhatsAppDeliveryHistory::class)->assertSee('Kirim ulang');

        Livewire::test(WhatsAppDeliveryHistory::class)->set('deliveryStatus', 'accepted')->assertDontSee('Kirim ulang');

        $this->actingAs($this->staff(['ViewAny:ReminderTemplate', 'ViewAny:ReminderLog', 'View:ReminderLog']));
        Livewire::test(WhatsAppDeliveryHistory::class)->assertSee('Gagal')->assertDontSee('Kirim ulang');
        $this->assertSame('failed', $log->fresh()->status);
    }

    public function test_resend_from_page_updates_status_and_shows_who_resent(): void
    {
        $log = $this->failedReminder();
        $notification = $this->failedNotification();
        $this->actingAs($this->staff());

        Livewire::test(WhatsAppDeliveryHistory::class)->call('resend', 'reminder', $log->id)->call('resend', 'notification', $notification->id)
            ->assertSee('Dikirim ulang oleh Admin Pengirim');

        $this->assertSame('accepted', $log->fresh()->status);
        $this->assertSame('accepted', $notification->fresh()->status);
    }

    public function test_resend_requires_settings_edit_permission(): void
    {
        $log = $this->failedReminder();
        $this->actingAs($this->staff(['ViewAny:ReminderTemplate', 'ViewAny:ReminderLog', 'View:ReminderLog']));
        Http::fake();

        Livewire::test(WhatsAppDeliveryHistory::class)->call('resend', 'reminder', $log->id)->assertForbidden();

        Http::assertNothingSent();
        $this->assertSame('failed', $log->fresh()->status);
    }

    public function test_resend_of_notification_outside_the_users_request_scope_is_not_found(): void
    {
        $notification = $this->failedNotification();
        $this->actingAs($this->staff(['ViewAny:ReminderTemplate', 'Update:ReminderTemplate', 'ViewAny:DocumentRequest', 'View:DocumentRequest']));
        Http::fake();

        Livewire::test(WhatsAppDeliveryHistory::class)->call('resend', 'notification', $notification->id)->assertNotFound();

        Http::assertNothingSent();
        $this->assertSame('failed', $notification->fresh()->status);
    }

    public function test_resend_rejects_unknown_sources(): void
    {
        $this->actingAs($this->staff());

        Livewire::test(WhatsAppDeliveryHistory::class)->call('resend', 'test', 1)->assertForbidden();
    }
}
