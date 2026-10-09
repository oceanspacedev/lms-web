<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentType;
use App\Models\DocumentVersion;
use App\Models\ReminderLog;
use App\Models\ReminderTemplate;
use App\Models\User;
use App\Services\DocumentReminderSender;
use App\Services\WaghubService;
use Database\Seeders\ReminderLogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DocumentReminderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 6)->setTime(1, 0));
        config(['services.waghub.url' => 'https://waghub.test', 'services.waghub.token' => 'test-token', 'services.waghub.purpose' => 'test-purpose']);
        $this->seed(ReminderLogSeeder::class);
        ReminderTemplate::factory()->create(['schedule_mode' => 'specific_days', 'scheduled_days' => [30, 7], 'body' => ReminderTemplate::DEFAULT_BODY]);
        Http::preventStrayRequests();
        Http::fake(['waghub.test/*' => fn () => Http::response(['status' => 'accepted'], config('testing.waghub_status', 202))]);
    }

    private function document(int $days = 30, ?string $phone = '081234567890'): Document
    {
        $document = Document::factory()->create([
            'title' => 'Kontrak Pengujian',
            'document_type_id' => DocumentType::factory()->create(['has_expiry' => true])->id,
            'pic_user_id' => User::factory()->create(['phone' => $phone])->id,
        ]);
        $version = DocumentVersion::factory()->create(['document_id' => $document->id, 'expiry_date' => today('Asia/Jakarta')->addDays($days)]);
        $document->current_version_id = $version->id;
        $document->save();

        return $document->refresh();
    }

    public function test_exact_schedule_payload_and_repeat_run_are_idempotent(): void
    {
        $document = $this->document();
        $this->document(31);
        $this->artisan('lms:send-reminders')->assertSuccessful();
        $this->artisan('lms:send-reminders')->assertSuccessful();
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://waghub.test/api/v1/messages'
            && $request->hasHeader('Authorization', 'Bearer test-token')
            && $request->hasHeader('Idempotency-Key', "lms-reminder-{$document->current_version_id}-30")
            && $request['recipient'] === ['type' => 'phone', 'value' => '6281234567890']
            && $request['purpose'] === 'test-purpose'
            && str_contains($request['message']['text'], 'Sisa waktu: 30 hari'));
        $this->assertSame('accepted', ReminderLog::sole()->status);
        $this->assertNull(ReminderLog::sole()->sent_at);
    }

    public function test_missed_schedule_is_sent_late_once_with_accurate_remaining_days(): void
    {
        $document = $this->document(29);
        app(DocumentReminderSender::class)->run();
        $this->travel(1)->days();
        app(DocumentReminderSender::class)->run();
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->hasHeader('Idempotency-Key', "lms-reminder-{$document->current_version_id}-30")
            && str_contains($request['message']['text'], 'Sisa waktu: 29 hari'));
        $this->assertSame(30, ReminderLog::sole()->offset_days);
    }

    public function test_new_document_inside_window_gets_nearest_reached_schedule_only(): void
    {
        $this->document(5);
        app(DocumentReminderSender::class)->run();
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => str_contains($request['message']['text'], 'Sisa waktu: 5 hari'));
        $this->assertSame(7, ReminderLog::sole()->offset_days);
    }

    public function test_expired_document_and_document_outside_window_are_not_reminded(): void
    {
        $this->document(31);
        $this->document(-1);
        app(DocumentReminderSender::class)->run();
        Http::assertNothingSent();
        $this->assertDatabaseCount('reminder_logs', 0);
    }

    public function test_failed_request_retries_next_day_with_identical_payload_and_key(): void
    {
        $this->document();
        config(['testing.waghub_status' => 503]);
        app(DocumentReminderSender::class)->run();
        $payload = ReminderLog::sole()->request_payload;
        config(['testing.waghub_status' => 202]);
        app(DocumentReminderSender::class)->run();
        Http::assertSentCount(1);
        $this->travel(1)->days();
        app(DocumentReminderSender::class)->run();
        Http::assertSentCount(2);
        $log = ReminderLog::sole();
        $this->assertSame(2, $log->attempts);
        $this->assertSame('accepted', $log->status);
        $this->assertSame($payload, $log->request_payload);
        $requests = Http::recorded();
        $this->assertSame($requests[0][0]->data(), $requests[1][0]->data());
        $this->assertSame($requests[0][0]->header('Idempotency-Key'), $requests[1][0]->header('Idempotency-Key'));
    }

    public function test_retry_stops_after_configured_maximum(): void
    {
        $this->document();
        config(['testing.waghub_status' => 500]);
        for ($day = 0; $day < 5; $day++) {
            app(DocumentReminderSender::class)->run();
            $this->travel(1)->days();
        }
        Http::assertSentCount(3);
        $this->assertSame(3, ReminderLog::sole()->attempts);
    }

    public function test_old_version_retry_is_cancelled_after_version_update(): void
    {
        $document = $this->document();
        config(['testing.waghub_status' => 500]);
        app(DocumentReminderSender::class)->run();
        $document->currentVersion->update(['is_current' => false]);
        $new = DocumentVersion::factory()->create(['document_id' => $document->id, 'version_number' => 2, 'expiry_date' => today()->addYear()]);
        $document->current_version_id = $new->id;
        $document->save();
        $this->travel(1)->days();
        app(DocumentReminderSender::class)->run();
        Http::assertSentCount(1);
        $this->assertSame('cancelled', ReminderLog::sole()->status);
    }

    public function test_fallback_requires_receive_reminder_permission(): void
    {
        $this->document(phone: null);
        User::factory()->create(['phone' => '081111111111']);
        $recipient = User::factory()->create(['phone' => '+62 812-3456-7890']);
        $recipient->givePermissionTo('receive_reminder');
        app(DocumentReminderSender::class)->run();
        Http::assertSent(fn ($request): bool => $request['recipient']['value'] === '6281234567890');
    }

    public function test_missing_phone_creates_failure_without_http_request(): void
    {
        $this->document(phone: null);
        app(DocumentReminderSender::class)->run();
        Http::assertNothingSent();
        $this->assertSame('failed', ReminderLog::sole()->status);
    }

    public function test_dry_run_never_sends_or_writes_logs(): void
    {
        $this->document();
        $this->artisan('lms:send-reminders --dry-run')->assertSuccessful();
        Http::assertNothingSent();
        $this->assertDatabaseCount('reminder_logs', 0);
    }

    public function test_archived_and_non_expiring_documents_are_excluded(): void
    {
        $this->document()->delete();
        $document = $this->document();
        $document->documentType->update(['has_expiry' => false]);
        app(DocumentReminderSender::class)->run();
        Http::assertNothingSent();
        $this->assertDatabaseCount('reminder_logs', 0);
    }

    public function test_schedule_uses_jakarta_calendar_day_near_utc_midnight(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 5)->setTime(18, 0));
        $this->document();
        app(DocumentReminderSender::class)->run();
        Http::assertSentCount(1);
    }

    public function test_missing_configuration_fails_before_writing_logs(): void
    {
        $this->document();
        config(['services.waghub.purpose' => null]);
        $this->artisan('lms:send-reminders')->assertFailed();
        Http::assertNothingSent();
        $this->assertDatabaseCount('reminder_logs', 0);
    }

    public function test_normalization_rejects_invalid_phone(): void
    {
        $service = app(WaghubService::class);
        $this->assertSame('6281234567890', $service->normalizePhone('(0812) 3456-7890'));
        $this->assertSame('6281234567890', $service->normalizePhone('+6281234567890'));
        $this->assertNull($service->normalizePhone('abc081234567890'));
        $this->assertNull($service->normalizePhone('123'));
    }
}
