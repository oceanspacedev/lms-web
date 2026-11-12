<?php

namespace Tests\Feature;

use App\Filament\Resources\DocumentRequests\DocumentRequestResource;
use App\Filament\Resources\DocumentRequests\Pages\ListDocumentRequests;
use App\Filament\Widgets\WaitingRequestsOverview;
use App\Models\Document;
use App\Models\DocumentRequest;
use App\Models\DocumentRequestNotification;
use App\Models\TrashLog;
use App\Models\User;
use App\Services\DocumentRequestNotifier;
use App\Services\DocumentRequestReminderSender;
use App\Services\HandoverReminderSender;
use App\Services\Trash\TrashManager;
use Database\Seeders\PermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use ReflectionMethod;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/** Pengajuan Dokumen di tempat sampah (spec §4.5). */
class DocumentRequestTrashTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6abcd';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 9)->setTime(8, 0));
        Storage::fake('local');
        Storage::fake('s3');
        config(['services.waghub.url' => 'https://waghub.test', 'services.waghub.token' => 'test-token', 'services.waghub.purpose' => 'test-purpose']);
        Permission::findOrCreate('Review:DocumentRequest', 'web');
        Permission::findOrCreate('ReceiveEscalation:DocumentRequest', 'web');
        Http::preventStrayRequests();
        Http::fake(['waghub.test/*' => Http::response(['status' => 'accepted'], 202)]);
    }

    private function manager(): TrashManager
    {
        return app(TrashManager::class);
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

    /** @return array<string, string> event_key => status */
    private function notificationStatuses(DocumentRequest $request): array
    {
        return $request->notifications()->orderBy('id')->pluck('status', 'event_key')->all();
    }

    public function test_trashing_running_request_cancels_pending_notifications_and_hides_from_lists_and_widget(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $permissions = ['ViewAny:DocumentRequest', 'View:DocumentRequest', 'ViewAll:DocumentRequest', 'Create:Document'];
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $viewer = User::factory()->create();
        $viewer->givePermissionTo([...$permissions, 'Review:DocumentRequest']);
        $this->actingAs($viewer);
        $actor = User::factory()->create();
        $request = $this->handover(3);
        $other = $this->handover(2, ['title' => 'Kontrak Lain']);
        $pending = DocumentRequestNotification::factory()->for($request)->create(['status' => 'pending', 'event_key' => "lms-request-{$request->id}-2-applicant"]);
        $failed = DocumentRequestNotification::factory()->for($request)->create(['status' => 'failed', 'event_key' => "lms-request-{$request->id}-2-u{$viewer->id}", 'error_message' => 'Pengiriman WhatsApp gagal. Akan dicoba kembali.']);
        $accepted = DocumentRequestNotification::factory()->for($request)->create(['status' => 'accepted', 'event_key' => "lms-request-{$request->id}-1-pic"]);
        $otherPending = DocumentRequestNotification::factory()->for($other)->create(['status' => 'pending', 'event_key' => "lms-request-{$other->id}-2-applicant"]);
        $stats = fn (): array => (new ReflectionMethod(WaitingRequestsOverview::class, 'getStats'))->invoke(Livewire::test(WaitingRequestsOverview::class)->instance());
        $this->assertSame(2, $stats()[2]->getValue());
        $this->assertSame(2, DocumentRequestResource::countActionable());

        $this->assertSame(
            'Pengajuan ini masih berjalan (Menunggu Dokumen Fisik). Pengingat terkait pengajuan ini akan berhenti.',
            $this->manager()->usage($request)->summary(),
        );
        $this->manager()->trash($request, $actor);

        $this->assertSoftDeleted($request);
        foreach ([$pending, $failed] as $notification) {
            $this->assertSame(['cancelled', 'Pengajuan dipindahkan ke tempat sampah.'], [$notification->fresh()->status, $notification->fresh()->error_message]);
        }
        $this->assertSame('accepted', $accepted->fresh()->status);
        $this->assertSame('pending', $otherPending->fresh()->status);
        Livewire::test(ListDocumentRequests::class)->assertCanSeeTableRecords([$other])->assertCanNotSeeTableRecords([$request]);
        Livewire::test(ListDocumentRequests::class)->set('activeTab', 'actionable')->assertCanSeeTableRecords([$other])->assertCanNotSeeTableRecords([$request]);
        $this->assertSame(1, DocumentRequestResource::countActionable());
        $this->assertSame(1, $stats()[2]->getValue());
        Http::assertNothingSent();
    }

    public function test_finished_request_has_no_running_warning(): void
    {
        $this->assertSame('', $this->manager()->usage($this->handover(3, ['status' => 'archived']))->summary());
        $this->assertSame(
            'Pengajuan ini masih berjalan (Diperiksa). Pengingat terkait pengajuan ini akan berhenti.',
            $this->manager()->usage(DocumentRequest::factory()->create(['status' => 'submitted']))->summary(),
        );
    }

    public function test_public_links_of_trashed_request_answer_not_found(): void
    {
        Storage::disk('local')->put('lms/requests/perjanjian/draft.pdf', 'draft');
        $request = $this->handover(10, [
            'requester_id' => null, 'public_token' => self::TOKEN, 'requester_name' => 'Pengaju Publik', 'requester_phone' => '6281111111111',
            'draft_path' => 'lms/requests/perjanjian/draft.pdf', 'draft_name' => 'draft.pdf',
        ]);
        $lookup = ['submission_id' => (string) $request->id, 'requester_phone' => '081111111111'];
        $this->get(route('requests.public.status', ['token' => self::TOKEN]))->assertOk()->assertSee('Kontrak Vendor');
        $this->get(route('requests.public.file', ['token' => self::TOKEN, 'kind' => 'draft']))->assertOk();
        $this->post(route('requests.public.lookup.submit'), $lookup)->assertOk()->assertSee('Pengajuan #'.$request->id);

        $this->manager()->trash($request, User::factory()->create());

        $this->get(route('requests.public.status', ['token' => self::TOKEN]))->assertNotFound();
        $this->get(route('requests.public.file', ['token' => self::TOKEN, 'kind' => 'draft']))->assertNotFound();
        $this->post(route('requests.public.scan', ['token' => self::TOKEN]), ['scan' => UploadedFile::fake()->create('scan.pdf', 50, 'application/pdf')])->assertNotFound();
        $this->post(route('requests.public.reason', ['token' => self::TOKEN]), ['reason' => 'Direktur dinas luar kota'])->assertNotFound();
        $this->post(route('requests.public.revise', ['token' => self::TOKEN]), ['requester_name' => 'Pengaju Publik'])->assertNotFound();
        $this->post(route('requests.public.lookup.submit'), $lookup)->assertRedirect(route('requests.public.lookup'))->assertSessionHas('lookup_failed', true);
        $this->get(route('requests.public.lookup'))->assertOk()->assertSee('Data tidak ditemukan. Periksa nomor pengajuan dan nomor WhatsApp.');
        $request = DocumentRequest::withTrashed()->findOrFail($request->id);
        $this->assertSame('handover', $request->status);
        $this->assertSame('lms/requests/signed.pdf', $request->signed_path);
        $this->assertSame(0, $request->lateReasons()->count());
    }

    public function test_trashed_request_gets_no_handover_or_scan_reminders(): void
    {
        $this->staff('Review:DocumentRequest', '082222222222');
        $actor = User::factory()->create();
        $late = $this->handover(15);
        $scanOverdue = DocumentRequest::factory()->signing(8)->create(['requester_phone' => '081111111111', 'history' => [['status' => 'signing', 'at' => now()->subDays(8)->toIso8601String()]]]);
        $waiting = DocumentRequest::factory()->create([
            'status' => 'submitted', 'reviewer_id' => $this->staff('Review:DocumentRequest', '083333333333')->id,
            'history' => [['status' => 'submitted', 'at' => now()->subDays(5)->toIso8601String(), 'note' => null]],
        ]);
        foreach ([$late, $scanOverdue, $waiting] as $request) {
            $this->manager()->trash($request, $actor);
        }

        $this->assertSame(0, app(HandoverReminderSender::class)->run());
        $this->assertSame(0, app(DocumentRequestReminderSender::class)->run());
        $this->travel(15)->days();
        $this->assertSame(0, app(HandoverReminderSender::class)->run());
        $this->assertSame(0, app(DocumentRequestReminderSender::class)->run());

        $this->assertSame(0, DocumentRequestNotification::count());
        Http::assertNothingSent();
    }

    public function test_restore_after_long_trash_does_not_burst_passed_reminders_but_sends_next_stage(): void
    {
        $legal = $this->staff('Review:DocumentRequest', '082222222222');
        $coordinator = $this->staff('ReceiveEscalation:DocumentRequest', '083333333333');
        $actor = User::factory()->create();
        $request = $this->handover(10);
        $this->manager()->trash($request, $actor);
        $this->travel(6)->days();

        $this->manager()->restore($request, $actor);
        $this->assertSame(0, app(HandoverReminderSender::class)->run());
        app(DocumentRequestNotifier::class)->run();

        Http::assertNothingSent();
        $this->assertSame([
            "lms-request-{$request->id}-2-late14-applicant" => 'cancelled',
            "lms-request-{$request->id}-2-late14-u{$legal->id}" => 'cancelled',
        ], $this->notificationStatuses($request));
        $this->assertSame(
            ['Jatuh tempo saat pengajuan di tempat sampah, tidak dikirim.'],
            $request->notifications()->distinct()->pluck('error_message')->all(),
        );

        $this->travel(13)->days();
        $this->assertSame(3, app(HandoverReminderSender::class)->run());
        app(DocumentRequestNotifier::class)->run();

        Http::assertSentCount(3);
        $this->assertSame([
            "lms-request-{$request->id}-2-late14-applicant" => 'cancelled',
            "lms-request-{$request->id}-2-late14-u{$legal->id}" => 'cancelled',
            "lms-request-{$request->id}-2-late21-applicant" => 'accepted',
            "lms-request-{$request->id}-2-late21-u{$legal->id}" => 'accepted',
            "lms-request-{$request->id}-2-late21-u{$coordinator->id}" => 'accepted',
        ], $this->notificationStatuses($request));
    }

    public function test_restore_skips_scan_reminder_that_fell_due_while_trashed(): void
    {
        $actor = User::factory()->create();
        $request = DocumentRequest::factory()->signing(3)->create(['requester_phone' => '081111111111', 'history' => [['status' => 'signing', 'at' => now()->subDays(3)->toIso8601String()]]]);
        $this->manager()->trash($request, $actor);
        $this->travel(17)->days();

        $this->manager()->restore($request, $actor);

        $this->assertSame(0, app(HandoverReminderSender::class)->run());
        $this->assertSame(["lms-request-{$request->id}-1-scan14-applicant" => 'cancelled'], $this->notificationStatuses($request));
    }

    public function test_restore_before_any_stage_is_due_creates_no_placeholders(): void
    {
        $actor = User::factory()->create();
        $request = $this->handover(2);
        $this->manager()->trash($request, $actor);
        $this->travel(1)->days();

        $this->manager()->restore($request, $actor);

        $this->assertSame(0, $request->notifications()->count());
        $this->travel(5)->days();
        $this->assertSame(1, app(HandoverReminderSender::class)->run());
        $this->assertSame(["lms-request-{$request->id}-2-late7-applicant" => 'pending'], $this->notificationStatuses($request));
    }

    public function test_restore_keeps_lateness_counted(): void
    {
        $actor = User::factory()->create();
        $request = $this->handover(10);
        $signedAt = $request->signed_uploaded_at->toIso8601String();
        $dueDate = $request->handoverDueDate()->toDateString();
        $this->assertSame(3, $request->daysLate());
        $this->manager()->trash($request, $actor);
        $this->travel(6)->days();

        $this->manager()->restore($request, $actor);

        $request->refresh();
        $this->assertSame($signedAt, $request->signed_uploaded_at->toIso8601String());
        $this->assertSame($dueDate, $request->handoverDueDate()->toDateString());
        $this->assertSame(9, $request->daysLate());
        $this->assertTrue($request->isHandoverLate());
        $this->assertSame('Terlambat 9 hari', $request->handoverRemainingLabel());
    }

    public function test_restore_does_not_send_stale_review_reminder_immediately(): void
    {
        $actor = User::factory()->create();
        $reviewer = User::factory()->create(['phone' => '081234567891']);
        $request = DocumentRequest::factory()->create([
            'status' => 'submitted', 'reviewer_id' => $reviewer->id,
            'history' => [['status' => 'submitted', 'at' => now()->subDay()->toIso8601String(), 'note' => null]],
        ]);
        $this->manager()->trash($request, $actor);
        $this->travel(4)->days();

        $this->manager()->restore($request, $actor);
        $this->assertSame(0, app(DocumentRequestReminderSender::class)->run());
        app(DocumentRequestNotifier::class)->run();

        Http::assertNothingSent();
        $this->assertSame(["lms-request-{$request->id}-1-stale2-u{$reviewer->id}" => 'cancelled'], $this->notificationStatuses($request));

        $this->travel(2)->days();
        $this->assertSame(1, app(DocumentRequestReminderSender::class)->run());
        app(DocumentRequestNotifier::class)->run();

        Http::assertSentCount(1);
        $this->assertSame('accepted', $request->notifications()->where('event_key', "lms-request-{$request->id}-1-stale3-u{$reviewer->id}")->value('status'));
    }

    public function test_restore_does_not_revive_notifications_cancelled_by_trashing(): void
    {
        $actor = User::factory()->create();
        $request = $this->handover(3);
        $pending = DocumentRequestNotification::factory()->for($request)->create(['status' => 'pending', 'event_key' => "lms-request-{$request->id}-2-applicant"]);
        $this->manager()->trash($request, $actor);

        $this->manager()->restore($request, $actor);
        app(DocumentRequestNotifier::class)->run();

        $this->assertSame('cancelled', $pending->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_force_delete_removes_local_files_and_keeps_archived_document(): void
    {
        $actor = User::factory()->create();
        $disk = Storage::disk('local');
        $paths = ['lms/requests/perjanjian/ktp.pdf', 'lms/requests/perjanjian/npwp.pdf', 'lms/requests/perjanjian/draft.pdf', 'lms/requests/perjanjian/ttd.pdf'];
        foreach ([...$paths, 'lms/requests/perjanjian/lain.pdf'] as $path) {
            $disk->put($path, 'isi');
        }
        $document = Document::factory()->create();
        $request = $this->handover(10, [
            'title' => 'Pengajuan Uji', 'attachments' => ['ktp' => $paths[0], 'npwp' => $paths[1]],
            'draft_path' => $paths[2], 'signed_path' => $paths[3],
        ]);
        $request->forceFill(['status' => 'archived', 'document_id' => $document->id])->save();
        $notification = DocumentRequestNotification::factory()->for($request)->create(['status' => 'accepted']);
        $lateReason = $request->lateReasons()->create(['reason' => 'Kurir tertunda']);
        $this->manager()->trash($request, $actor);

        $this->manager()->forceDelete($request, $actor);

        $this->assertDatabaseMissing('document_requests', ['id' => $request->id]);
        $this->assertDatabaseMissing('document_request_notifications', ['id' => $notification->id]);
        $this->assertDatabaseMissing('document_request_late_reasons', ['id' => $lateReason->id]);
        $disk->assertMissing($paths);
        $disk->assertExists('lms/requests/perjanjian/lain.pdf');
        $this->assertNotNull($document->fresh());
        $this->assertFalse($document->fresh()->trashed());
        $log = TrashLog::where('action', 'force_deleted')->sole();
        $this->assertSame(['document_request', $request->id, "#{$request->id} · Pengajuan Uji", $actor->id, ['actor_name' => $actor->name]], [$log->subject_type, $log->subject_id, $log->subject_label, $log->user_id, $log->details]);
    }

    public function test_files_of_trashed_request_download_for_super_admin_only(): void
    {
        $this->seed(PermissionSeeder::class);
        $superAdmin = User::factory()->create()->assignRole(config('filament-shield.super_admin.name'));
        $staff = User::factory()->create();
        $staff->givePermissionTo(['ViewAny:DocumentRequest', 'View:DocumentRequest', 'ViewAll:DocumentRequest', 'Review:DocumentRequest']);
        foreach (['lms/requests/perjanjian/ktp.pdf', 'lms/requests/perjanjian/draft.pdf', 'lms/requests/perjanjian/ttd.pdf'] as $path) {
            Storage::disk('local')->put($path, '%PDF-1.4 isi');
        }
        $request = $this->handover(3, [
            'requirements' => ['fields' => [], 'attachments' => [['key' => 'ktp', 'label' => 'KTP', 'required' => true]], 'has_expiry' => false],
            'attachments' => ['ktp' => 'lms/requests/perjanjian/ktp.pdf'],
            'draft_path' => 'lms/requests/perjanjian/draft.pdf', 'draft_name' => 'draft.pdf',
            'signed_path' => 'lms/requests/perjanjian/ttd.pdf', 'signed_name' => 'ttd.pdf',
        ]);
        $urls = [
            route('requests.attachment', ['documentRequest' => $request, 'key' => 'ktp']),
            route('requests.file', ['documentRequest' => $request, 'kind' => 'draft']),
            route('requests.file', ['documentRequest' => $request, 'kind' => 'signed']),
        ];
        foreach ($urls as $url) {
            $this->actingAs($staff)->get($url)->assertOk();
        }

        $this->manager()->trash($request, $superAdmin);

        foreach ($urls as $url) {
            $this->actingAs($superAdmin)->get($url)->assertOk();
            $this->actingAs($staff)->get($url)->assertForbidden();
        }
    }

    public function test_force_delete_never_removes_files_outside_request_folder(): void
    {
        $actor = User::factory()->create();
        $disk = Storage::disk('local');
        $disk->put('lms/requests/draft.pdf', 'isi');
        $disk->put('rahasia.txt', 'isi');
        $request = DocumentRequest::factory()->signing()->create(['attachments' => ['ktp' => 'rahasia.txt', 'npwp' => 'lms/requests/../rahasia.txt']]);
        $this->manager()->trash($request, $actor);

        $this->manager()->forceDelete($request, $actor);

        $disk->assertMissing('lms/requests/draft.pdf');
        $disk->assertExists('rahasia.txt');
    }
}
