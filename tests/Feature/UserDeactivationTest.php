<?php

namespace Tests\Feature;

use App\Filament\Resources\Documents\Pages\CreateDocument;
use App\Filament\Resources\Documents\Pages\EditDocument;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Document;
use App\Models\DocumentActivity;
use App\Models\DocumentRequest;
use App\Models\DocumentRequestNotification;
use App\Models\DocumentType;
use App\Models\DocumentVersion;
use App\Models\ReminderLog;
use App\Models\ReminderTemplate;
use App\Models\User;
use App\Services\DocumentReminderSender;
use App\Services\DocumentRequestReminderSender;
use App\Services\ReminderHealthChecker;
use App\Services\UserDeactivator;
use App\Services\WhatsAppLoginService;
use Filament\Actions\Testing\TestAction;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserDeactivationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 9)->setTime(8, 0));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        config(['services.waghub.url' => 'https://waghub.test', 'services.waghub.token' => 'test-token', 'services.waghub.purpose' => 'test-purpose', 'services.waghub.otp_purpose' => 'test-otp']);
        foreach (['ViewAny:User', 'View:User', 'Update:User', 'ViewAny:Document', 'View:Document', 'Create:Document', 'Update:Document', 'Review:DocumentRequest', 'Approve:DocumentRequest', 'receive_reminder'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        Http::preventStrayRequests();
        Http::fake(['waghub.test/*' => Http::response(['status' => 'accepted'], 202)]);
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['name' => 'Admin Legal']);
        $admin->givePermissionTo(['ViewAny:User', 'View:User', 'Update:User', 'ViewAny:Document', 'View:Document', 'Create:Document', 'Update:Document']);
        $this->actingAs($admin);

        return $admin;
    }

    /** @param list<string> $permissions */
    private function staff(?string $phone, array $permissions = ['ViewAny:Document'], string $name = 'Staf'): User
    {
        $user = User::factory()->create(['phone' => $phone, 'name' => $name]);
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function documentWithPic(User $pic, int $expiresInDays = 30): Document
    {
        $document = Document::factory()->create([
            'pic_user_id' => $pic->id,
            'document_type_id' => DocumentType::factory()->create(['has_expiry' => true])->id,
        ]);
        $version = DocumentVersion::factory()->create(['document_id' => $document->id, 'expiry_date' => today('Asia/Jakarta')->addDays($expiresInDays)]);
        $document->forceFill(['current_version_id' => $version->id])->save();

        return $document->refresh();
    }

    /** @param array<string, mixed> $attributes */
    private function request(string $status, array $attributes = []): DocumentRequest
    {
        return DocumentRequest::factory()->create([
            'status' => $status, 'title' => 'Kontrak Vendor',
            'history' => [['status' => $status, 'user' => 'Pengaju', 'user_id' => null, 'at' => now()->subDays(2)->toIso8601String(), 'note' => null]],
            ...$attributes,
        ]);
    }

    public function test_deactivated_user_cannot_log_in_with_password_or_whatsapp_code(): void
    {
        $user = $this->staff('6281234567890');
        app(WhatsAppLoginService::class)->requestCode('081234567890', '127.0.0.1');
        preg_match('/\b(\d{6})\b/', Http::recorded()->last()[0]['message']['text'], $match);
        $user->forceFill(['deactivated_at' => now()])->save();

        $this->assertThrows(fn () => app(WhatsAppLoginService::class)->verifyCode('081234567890', $match[1]), ValidationException::class);
        $this->travel(2)->minutes();
        app(WhatsAppLoginService::class)->requestCode('081234567890', '127.0.0.1');
        Http::assertSentCount(1);

        Livewire::test(Login::class)->fillForm(['email' => $user->email, 'password' => 'password'])->call('authenticate')->assertHasFormErrors(['email']);
        $this->assertGuest();
    }

    public function test_deactivation_ends_existing_sessions_and_rotates_remember_token(): void
    {
        $admin = $this->admin();
        $user = $this->staff('081234567890');
        $oldToken = $user->getRememberToken();
        DB::table('sessions')->insert([
            ['id' => 'sesi-target', 'user_id' => $user->id, 'payload' => '', 'last_activity' => now()->timestamp],
            ['id' => 'sesi-admin', 'user_id' => $admin->id, 'payload' => '', 'last_activity' => now()->timestamp],
        ]);

        app(UserDeactivator::class)->deactivate($user, $admin, []);

        $user->refresh();
        $this->assertFalse($user->isActive());
        $this->assertSame($admin->id, $user->deactivated_by);
        $this->assertNotSame($oldToken, $user->getRememberToken());
        $this->assertSame(['sesi-admin'], DB::table('sessions')->pluck('id')->all());
        $this->actingAs($user)->get(Filament::getPanel('admin')->getUrl())->assertForbidden();
    }

    public function test_every_assignment_moves_to_the_chosen_replacements_including_trashed_documents(): void
    {
        $admin = $this->admin();
        $leaver = $this->staff('081200000001', ['ViewAny:Document', 'Review:DocumentRequest', 'Approve:DocumentRequest'], 'Staf Keluar');
        $newPic = $this->staff('081200000002', name: 'PIC Baru');
        $reviewer = $this->staff('081200000003', ['Review:DocumentRequest'], 'Pemeriksa Baru');
        $approver = $this->staff('081200000004', ['Approve:DocumentRequest'], 'Penyetuju Baru');
        $active = $this->documentWithPic($leaver);
        $trashed = $this->documentWithPic($leaver);
        $trashed->delete();
        $submitted = $this->request('submitted', ['pic_user_id' => $leaver->id, 'reviewer_id' => $leaver->id]);
        $inReview = $this->request('review', ['approver_id' => $leaver->id]);
        $draft = $this->request('draft', ['requester_id' => $leaver->id, 'pic_user_id' => $leaver->id]);
        $archived = $this->request('archived', ['pic_user_id' => $leaver->id, 'reviewer_id' => $leaver->id, 'approver_id' => $leaver->id]);
        $type = DocumentType::factory()->create(['request_pic_id' => $leaver->id, 'request_reviewer_id' => $leaver->id, 'request_approver_id' => $leaver->id]);

        Livewire::test(EditUser::class, ['record' => $leaver->getRouteKey()])
            ->mountAction('deactivate')
            ->assertMountedActionModalSee(['2 dokumen sebagai PIC', '3 pengajuan berjalan', '1 jenis dokumen', '1 draf pengajuan milik user ini dibiarkan'])
            ->setActionData(['document_pic' => $newPic->id, 'reviewer' => $reviewer->id, 'approver' => $approver->id])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('User dinonaktifkan');

        $this->assertSame([$newPic->id, $newPic->id], Document::withTrashed()->whereKey([$active->id, $trashed->id])->pluck('pic_user_id')->all());
        $this->assertSame(2, DocumentActivity::where('event', 'pic_reassigned')->where('user_id', $admin->id)->count());
        $this->assertSame('PIC dialihkan', DocumentActivity::EVENTS['pic_reassigned']);
        $this->assertSame([$reviewer->id, $reviewer->id], [$submitted->fresh()->pic_user_id, $submitted->fresh()->reviewer_id]);
        $this->assertSame($approver->id, $inReview->fresh()->approver_id);
        $this->assertSame($reviewer->id, $draft->fresh()->pic_user_id);
        $this->assertSame($leaver->id, $draft->fresh()->requester_id);
        $this->assertSame([$leaver->id, $leaver->id, $leaver->id], [$archived->fresh()->pic_user_id, $archived->fresh()->reviewer_id, $archived->fresh()->approver_id]);
        $this->assertCount(1, $submitted->fresh()->history);
        $type->refresh();
        $this->assertSame([$reviewer->id, $reviewer->id, $approver->id], [$type->request_pic_id, $type->request_reviewer_id, $type->request_approver_id]);
        $this->assertFalse($leaver->fresh()->isActive());
        $this->assertTrue($leaver->fresh()->hasPermissionTo('Review:DocumentRequest'));
        $this->assertNotContains('inactive_pic', array_column(app(ReminderHealthChecker::class)->alerts(), 'key'));
    }

    public function test_pending_reminder_is_rebuilt_for_the_new_pic_number(): void
    {
        $admin = $this->admin();
        ReminderTemplate::factory()->create(['schedule_mode' => 'specific_days', 'scheduled_days' => [30, 7], 'body' => ReminderTemplate::DEFAULT_BODY]);
        $leaver = $this->staff('081200000001');
        $newPic = $this->staff('081200000002');
        $document = $this->documentWithPic($leaver);
        $log = ReminderLog::factory()->create([
            'document_version_id' => $document->current_version_id, 'offset_days' => 30, 'status' => 'failed', 'attempts' => 1,
            'last_attempt_at' => now()->subDay(), 'sent_at' => null, 'recipient_phone' => '6281200000001',
            'request_payload' => ['recipient' => ['type' => 'phone', 'value' => '6281200000001'], 'message' => ['type' => 'text', 'text' => 'lama']],
        ]);

        app(UserDeactivator::class)->deactivate($leaver, $admin, ['document_pic' => $newPic->id]);
        $this->assertNull($log->fresh()->request_payload);
        app(DocumentReminderSender::class)->run();

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request['recipient']['value'] === '6281200000002');
        $this->assertSame('6281200000002', $log->fresh()->recipient_phone);
    }

    public function test_pending_notifications_for_the_user_are_cancelled_and_stale_reminders_go_to_the_replacement(): void
    {
        $admin = $this->admin();
        $leaver = $this->staff('081200000001', ['Review:DocumentRequest']);
        $reviewer = $this->staff('081200000003', ['Review:DocumentRequest']);
        $request = $this->request('submitted', ['pic_user_id' => $leaver->id, 'reviewer_id' => null]);
        $notification = fn (string $suffix, string $kind): DocumentRequestNotification => DocumentRequestNotification::factory()->create([
            'document_request_id' => $request->id, 'event_key' => "lms-request-{$request->id}-1-{$suffix}", 'recipient_kind' => $kind, 'status' => 'pending',
        ]);
        $pic = $notification('pic', 'pic');
        $stale = $notification("stale1-u{$leaver->id}", 'reviewer');
        $applicant = $notification('applicant', 'applicant');
        $other = $notification("stale1-u{$leaver->id}0", 'reviewer');

        app(UserDeactivator::class)->deactivate($leaver, $admin, ['reviewer' => $reviewer->id]);

        $this->assertSame(['cancelled', 'cancelled', 'pending', 'pending'], [$pic->fresh()->status, $stale->fresh()->status, $applicant->fresh()->status, $other->fresh()->status]);
        $this->assertSame(1, app(DocumentRequestReminderSender::class)->run());
        $this->assertSame('6281200000003', DocumentRequestNotification::where('event_key', "lms-request-{$request->id}-1-stale1-u{$reviewer->id}")->sole()->recipient_phone);
    }

    public function test_approval_team_option_clears_the_approver_so_any_approver_can_act(): void
    {
        $admin = $this->admin();
        $leaver = $this->staff('081200000001', ['Approve:DocumentRequest']);
        $approverA = $this->staff('081200000002', ['Approve:DocumentRequest']);
        $approverB = $this->staff('081200000003', ['Approve:DocumentRequest']);
        $request = $this->request('review', ['approver_id' => $leaver->id]);
        $type = DocumentType::factory()->create(['request_approver_id' => $leaver->id]);

        $this->assertSame(UserDeactivator::APPROVAL_TEAM, array_key_first(app(UserDeactivator::class)->candidateOptions($leaver, UserDeactivator::GROUP_APPROVER)));
        Livewire::test(EditUser::class, ['record' => $leaver->getRouteKey()])
            ->callAction('deactivate', data: ['approver' => UserDeactivator::APPROVAL_TEAM])
            ->assertHasNoActionErrors();

        $this->assertNull($request->fresh()->approver_id);
        $this->assertNull($type->fresh()->request_approver_id);
        $this->assertTrue(Gate::forUser($approverA)->allows('approve', $request->fresh()));
        $this->assertTrue(Gate::forUser($approverB)->allows('approve', $request->fresh()));
    }

    public function test_cannot_deactivate_yourself_or_the_last_active_super_admin(): void
    {
        $admin = $this->admin();
        $superAdminRole = Role::findOrCreate(config('filament-shield.super_admin.name'), 'web');
        $superAdminRole->givePermissionTo(['ViewAny:User', 'View:User', 'Update:User']);
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole($superAdminRole);
        $deactivator = app(UserDeactivator::class);

        Livewire::test(EditUser::class, ['record' => $admin->getRouteKey()])->assertActionHidden('deactivate');
        $this->assertSame('Anda tidak dapat menonaktifkan akun sendiri.', $deactivator->deactivationDenialReason($admin, $admin));
        Livewire::test(EditUser::class, ['record' => $superAdmin->getRouteKey()])->assertActionHidden('deactivate');
        $this->assertSame('Hanya super admin yang dapat menonaktifkan super admin.', $deactivator->deactivationDenialReason($admin, $superAdmin));
        $this->assertThrows(fn () => $deactivator->deactivate($superAdmin, $admin, []), ValidationException::class);

        $formerSuperAdmin = User::factory()->deactivated()->create();
        $formerSuperAdmin->assignRole($superAdminRole);
        $this->assertSame('Super admin aktif terakhir tidak dapat dinonaktifkan.', $deactivator->deactivationDenialReason($formerSuperAdmin, $superAdmin));
        $this->assertNull($deactivator->deactivationDenialReason($superAdmin, $admin));
        $this->assertTrue($superAdmin->fresh()->isActive());
    }

    public function test_action_is_refused_when_no_valid_replacement_exists(): void
    {
        $admin = $this->admin();
        $leaver = $this->staff('081200000001', ['Review:DocumentRequest']);
        $this->request('submitted', ['reviewer_id' => $leaver->id]);
        DocumentType::factory()->create(['request_pic_id' => $leaver->id]);
        $this->staff(null, ['Review:DocumentRequest']);

        Livewire::test(EditUser::class, ['record' => $leaver->getRouteKey()])
            ->mountAction('deactivate')
            ->assertActionNotMounted('deactivate')
            ->assertNotified('User belum bisa dinonaktifkan');

        $this->assertStringContainsString('"Periksa pengajuan" dan nomor WhatsApp valid', app(UserDeactivator::class)->missingCandidatesMessage($leaver));
        $this->assertThrows(fn () => app(UserDeactivator::class)->deactivate($leaver, $admin, ['reviewer' => $admin->id]), ValidationException::class);
        $this->assertTrue($leaver->fresh()->isActive());
    }

    public function test_reactivation_restores_access_but_is_refused_while_the_phone_belongs_to_an_active_user(): void
    {
        $this->admin();
        $leaver = $this->staff('081200000001');
        $leaver->forceFill(['deactivated_at' => now()])->save();
        $newcomer = $this->staff('081200000001');

        Livewire::test(EditUser::class, ['record' => $leaver->getRouteKey()])
            ->assertActionHidden('deactivate')
            ->callAction('reactivate')
            ->assertNotified('User belum bisa diaktifkan kembali');
        $this->assertFalse($leaver->fresh()->isActive());

        $newcomer->update(['phone' => '6281200000009']);
        Livewire::test(EditUser::class, ['record' => $leaver->getRouteKey()])
            ->callAction('reactivate')
            ->assertNotified('User diaktifkan kembali');
        $this->assertTrue($leaver->fresh()->isActive());
        $this->assertNull($leaver->fresh()->deactivated_by);
        $this->assertTrue($leaver->fresh()->canAccessPanel(Filament::getPanel('admin')));
    }

    public function test_users_list_shows_active_users_by_default_and_who_deactivated_the_others(): void
    {
        $admin = $this->admin();
        $active = $this->staff('081200000001');
        $inactive = User::factory()->create(['deactivated_at' => now(), 'deactivated_by' => $admin->id]);

        Livewire::test(ListUsers::class)
            ->assertCanSeeTableRecords([$active])->assertCanNotSeeTableRecords([$inactive])
            ->assertActionVisible(TestAction::make('deactivate')->table($active))
            ->filterTable('status', false)
            ->assertCanSeeTableRecords([$inactive])->assertCanNotSeeTableRecords([$active])
            ->assertSee('Nonaktif')->assertSee('Dinonaktifkan oleh Admin Legal')
            ->assertActionVisible(TestAction::make('reactivate')->table($inactive))
            ->assertActionHidden(TestAction::make('deactivate')->table($inactive));
    }

    public function test_user_pickers_hide_inactive_users_but_existing_documents_keep_saving(): void
    {
        $this->admin();
        $inactive = User::factory()->deactivated()->create(['name' => 'Mantan Staf']);
        $document = $this->documentWithPic($inactive);

        Livewire::test(EditDocument::class, ['record' => $document->getRouteKey()])
            ->fillForm(['title' => 'Judul Baru'])->call('save')->assertHasNoFormErrors();
        $this->assertSame(['Judul Baru', $inactive->id], [$document->fresh()->title, $document->fresh()->pic_user_id]);

        Livewire::test(CreateDocument::class)->fillForm(['pic_user_id' => $inactive->id])->call('create')->assertHasFormErrors(['pic_user_id']);
        $this->assertSame([], User::activeOrKeep(null)->whereKey($inactive->id)->pluck('id')->all());
        $this->assertSame([$inactive->id], User::activeOrKeep($inactive->id)->whereKey($inactive->id)->pluck('id')->all());
        $this->assertContains('inactive_pic', array_column(app(ReminderHealthChecker::class)->alerts(), 'key'));
    }

    public function test_phone_is_taken_ignores_inactive_users(): void
    {
        $leaver = User::factory()->create(['phone' => '6281200000001']);
        $this->assertTrue(User::phoneIsTaken('6281200000001'));

        $leaver->forceFill(['deactivated_at' => now()])->save();

        $this->assertFalse(User::phoneIsTaken('6281200000001'));
    }
}
