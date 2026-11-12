<?php

namespace Tests\Feature;

use App\Models\DocumentRequest;
use App\Models\DocumentRequestNotification;
use App\Models\ReminderTemplate;
use App\Models\User;
use App\Services\DocumentRequestNotifier;
use App\Services\HandoverReminderSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class HandoverReminderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 9)->setTime(8, 0));
        config(['services.waghub.url' => 'https://waghub.test', 'services.waghub.token' => 'test-token', 'services.waghub.purpose' => 'test-purpose']);
        Permission::findOrCreate('Review:DocumentRequest', 'web');
        Permission::findOrCreate('ReceiveEscalation:DocumentRequest', 'web');
        Http::preventStrayRequests();
        Http::fake(['waghub.test/*' => Http::response(['status' => 'accepted'], 202)]);
    }

    /** @param array<string, mixed> $attributes */
    private function handover(int $signedDaysAgo, array $attributes = []): DocumentRequest
    {
        return DocumentRequest::factory()->handover($signedDaysAgo)->create([
            'requester_phone' => '081111111111', 'title' => 'Kontrak Vendor',
            'history' => [
                ['status' => 'submitted', 'at' => now()->subDays($signedDaysAgo + 5)->toIso8601String(), 'note' => null],
                ['status' => 'handover', 'at' => now()->subDays($signedDaysAgo)->toIso8601String(), 'note' => null],
            ],
            ...$attributes,
        ]);
    }

    private function staff(string $permission, string $phone): User
    {
        $user = User::factory()->create(['phone' => $phone]);
        $user->givePermissionTo($permission);

        return $user;
    }

    private function send(): int
    {
        return app(HandoverReminderSender::class)->run();
    }

    /** @return list<string> */
    private function keys(DocumentRequest $request): array
    {
        return $request->notifications()->orderBy('id')->pluck('event_key')->all();
    }

    public function test_day_seven_is_quiet_day_eight_reminds_applicant(): void
    {
        $quiet = $this->handover(7);
        $this->assertSame(0, $this->send());
        $this->assertSame(0, $quiet->notifications()->count());

        $late = $this->handover(8);
        $this->assertSame(1, $this->send());
        $notification = $late->notifications()->sole();
        $this->assertSame("lms-request-{$late->id}-2-late7-applicant", $notification->event_key);
        $this->assertSame('applicant', $notification->recipient_kind);
        $this->assertSame('6281111111111', $notification->recipient_phone);
        $this->assertStringContainsString('Kontrak Vendor', $notification->payload['message']['text']);
        $this->assertStringContainsString('lewat 1 hari', $notification->payload['message']['text']);
    }

    public function test_day_fifteen_reminds_applicant_and_legal_staff(): void
    {
        $legal = $this->staff('Review:DocumentRequest', '082222222222');
        $this->staff('ReceiveEscalation:DocumentRequest', '083333333333');
        $request = $this->handover(15);

        $this->assertSame(2, $this->send());

        $this->assertSame(["lms-request-{$request->id}-2-late14-applicant", "lms-request-{$request->id}-2-late14-u{$legal->id}"], $this->keys($request));
        $this->assertSame(['applicant', 'legal'], $request->notifications()->orderBy('id')->pluck('recipient_kind')->all());
    }

    public function test_applicant_is_asked_for_a_reason_and_staff_see_who_is_late_with_the_latest_reason(): void
    {
        $this->staff('Review:DocumentRequest', '082222222222');
        $request = $this->handover(15, ['requester_name' => 'Wulan Sari']);
        $request->lateReasons()->create(['reason' => 'Direktur dinas luar kota']);

        $this->send();

        $texts = $request->notifications()->orderBy('id')->get()->mapWithKeys(fn ($notification): array => [$notification->recipient_kind => $notification->payload['message']['text']]);
        $this->assertStringContainsString('tulis alasan keterlambatan lewat tautan ini', $texts['applicant']);
        $this->assertStringNotContainsString('tulis alasan', $texts['legal']);
        $this->assertStringContainsString('Pengaju: Wulan Sari', $texts['legal']);
        $this->assertStringContainsString('lewat 8 hari', $texts['legal']);
        $this->assertStringContainsString('Alasan terakhir: Direktur dinas luar kota', $texts['legal']);
    }

    public function test_day_twenty_two_reminds_applicant_staff_and_coordinator(): void
    {
        $legal = $this->staff('Review:DocumentRequest', '082222222222');
        $coordinator = $this->staff('ReceiveEscalation:DocumentRequest', '083333333333');
        // Hari ke-21 masih tahap "Terlambat 1 minggu"; tahap "Terlambat 2 minggu" (dan koordinator) mulai hari ke-22.
        $dayTwentyOne = $this->handover(21);
        $request = $this->handover(22);

        $this->assertSame(5, $this->send());
        $this->assertSame(["lms-request-{$dayTwentyOne->id}-2-late14-applicant", "lms-request-{$dayTwentyOne->id}-2-late14-u{$legal->id}"], $this->keys($dayTwentyOne));

        $this->assertSame([
            "lms-request-{$request->id}-2-late21-applicant",
            "lms-request-{$request->id}-2-late21-u{$legal->id}",
            "lms-request-{$request->id}-2-late21-u{$coordinator->id}",
        ], $this->keys($request));
        $this->assertSame(['applicant', 'legal', 'coordinator'], $request->notifications()->orderBy('id')->pluck('recipient_kind')->all());
    }

    public function test_moving_the_last_stage_to_day_21_keeps_custom_text_and_does_not_resend(): void
    {
        $request = $this->handover(30);
        $request->notifications()->create([
            'event_key' => "lms-request-{$request->id}-2-late28-applicant", 'recipient_kind' => 'applicant', 'recipient_phone' => '6281111111111',
            'payload' => ['message' => ['type' => 'text', 'text' => 'lama']], 'status' => 'accepted',
        ]);
        // Data lama disimpan sebelum aturan berubah, jadi ditulis langsung (validasi model sekarang menolak kunci late_28).
        $setting = ReminderTemplate::factory()->create();
        ReminderTemplate::whereKey($setting->id)->toBase()->update(['request_templates' => json_encode(['late_28' => 'Pesan khusus terlambat'])]);

        (require database_path('migrations/2026_11_10_041909_move_late_handover_stage_from_day_28_to_day_21.php'))->up();

        $this->assertSame(['late_21' => 'Pesan khusus terlambat'], $setting->refresh()->request_templates);
        $this->assertSame(["lms-request-{$request->id}-2-late21-applicant"], $this->keys($request));
        $this->assertSame(0, $this->send());
    }

    public function test_runs_twice_without_duplicates(): void
    {
        $this->staff('Review:DocumentRequest', '082222222222');
        $request = $this->handover(15);

        $this->assertSame(2, $this->send());
        $this->assertSame(0, $this->send());
        $this->assertSame(2, $request->notifications()->count());
    }

    public function test_after_scheduler_gap_only_highest_stage_is_sent(): void
    {
        $request = $this->handover(30);

        $this->assertSame(1, $this->send());
        $this->assertSame(["lms-request-{$request->id}-2-late21-applicant"], $this->keys($request));
    }

    public function test_stages_advance_one_by_one_when_scheduler_runs_daily(): void
    {
        $request = $this->handover(8);
        $this->assertSame(1, $this->send());

        $this->travel(7)->days();
        $this->assertSame(1, $this->send());

        $this->assertSame(["lms-request-{$request->id}-2-late7-applicant", "lms-request-{$request->id}-2-late14-applicant"], $this->keys($request));
    }

    public function test_no_reminders_for_cancelled_archived_or_after_day_21_stage(): void
    {
        $this->handover(30, ['status' => 'cancelled']);
        $this->handover(30, ['status' => 'archived']);
        $active = $this->handover(30);
        $this->assertSame(1, $this->send());

        $this->travel(20)->days();
        $this->assertSame(0, $this->send());
        $this->assertSame(1, $active->notifications()->count());
        $this->assertSame(1, DocumentRequestNotification::count());
    }

    public function test_soft_file_reminder_once_after_fourteen_days_without_upload(): void
    {
        $fresh = DocumentRequest::factory()->signing(14)->create(['requester_phone' => '081111111111', 'history' => [['status' => 'signing', 'at' => now()->toIso8601String()]]]);
        $this->assertSame(0, $this->send());

        $overdue = DocumentRequest::factory()->signing(15)->create(['requester_phone' => '081111111111', 'title' => 'Perjanjian Sewa', 'history' => [
            ['status' => 'submitted', 'at' => now()->subDays(19)->toIso8601String()],
            ['status' => 'signing', 'at' => now()->subDays(15)->toIso8601String()],
            ['event' => 'draft_replaced', 'status' => 'signing', 'at' => now()->subDays(3)->toIso8601String()],
        ]]);
        $this->assertSame(1, $this->send());
        $this->assertSame(0, $this->send());

        $notification = $overdue->notifications()->sole();
        $this->assertSame("lms-request-{$overdue->id}-2-scan14-applicant", $notification->event_key);
        $this->assertSame('applicant', $notification->recipient_kind);
        $this->assertStringContainsString('Perjanjian Sewa', $notification->payload['message']['text']);
        $this->assertSame(0, $fresh->notifications()->count());
    }

    public function test_late_reminders_ignore_request_reminder_switch(): void
    {
        ReminderTemplate::factory()->create(['request_reminder_enabled' => false]);
        $request = $this->handover(8);

        $this->assertSame(1, $this->send());
        $this->assertSame(1, $request->notifications()->count());
    }

    public function test_missing_applicant_phone_creates_a_visibly_failed_notification(): void
    {
        $request = $this->handover(8, ['requester_phone' => null, 'requester_id' => User::factory()->create(['phone' => null])->id]);

        $this->assertSame(1, $this->send());
        app(DocumentRequestNotifier::class)->run($request->id);

        Http::assertNothingSent();
        $this->assertSame('failed', $request->notifications()->sole()->status);
    }

    public function test_reminder_is_sent_by_notifier_and_not_cancelled_as_superseded(): void
    {
        $request = $this->handover(8);
        $this->send();

        app(DocumentRequestNotifier::class)->run($request->id);

        Http::assertSentCount(1);
        $this->assertSame('accepted', $request->notifications()->sole()->status);
    }

    public function test_adding_a_late_reason_between_runs_does_not_duplicate_reminders(): void
    {
        $request = $this->handover(8);
        $this->assertSame(1, $this->send());

        $request->forceFill(['history' => [...$request->history, ['event' => 'late_reason', 'status' => 'handover', 'at' => now()->toIso8601String(), 'note' => 'Kurir tertunda']]])->save();

        $this->assertSame(0, $this->send());
        $this->assertSame(1, $request->notifications()->count());
        app(DocumentRequestNotifier::class)->run($request->id);
        $this->assertSame('accepted', $request->notifications()->sole()->status);
    }

    public function test_command_runs_both_senders(): void
    {
        $this->handover(8);

        $this->artisan('lms:send-request-reminders')->expectsOutputToContain('1 pengingat keterlambatan dan unggah soft file')->assertSuccessful();
    }
}
