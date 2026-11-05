<?php

namespace Tests\Feature;

use App\Filament\Auth\Pages\EmailLogin;
use App\Filament\Auth\Pages\PhoneLogin;
use App\Filament\Resources\DocumentRequests\Pages\EditDocumentRequest;
use App\Filament\Resources\DocumentRequests\Pages\ListDocumentRequests;
use App\Filament\Resources\Documents\Pages\CreateDocument;
use App\Mail\EmailLoginMail;
use App\Models\Document;
use App\Models\DocumentActivity;
use App\Models\DocumentImport;
use App\Models\DocumentRequest;
use App\Models\DocumentRequestNotification;
use App\Models\DocumentType;
use App\Models\DocumentVersion;
use App\Models\ReminderLog;
use App\Models\ReminderTemplate;
use App\Models\TrashLog;
use App\Models\User;
use App\Models\WhatsappOtp;
use App\Services\DocumentReminderSender;
use App\Services\DocumentRequestNotifier;
use App\Services\DocumentRequestReminderSender;
use App\Services\HandoverReminderSender;
use App\Services\Trash\TrashManager;
use App\Services\UserDeactivator;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** User di tempat sampah (spec §4.4): hapus = nonaktifkan + tempat sampah, hapus permanen tidak berantai. */
class UserTrashTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN_PERMISSIONS = ['ViewAny:User', 'View:User', 'Update:User', 'Delete:User', 'DeleteAny:User', 'ViewAny:Document', 'View:Document', 'Create:Document',
        'ViewAny:DocumentRequest', 'View:DocumentRequest', 'Review:DocumentRequest'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 9)->setTime(8, 0));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Storage::fake('local');
        Storage::fake('s3');
        config([
            'services.waghub.url' => 'https://waghub.test', 'services.waghub.token' => 'test-token', 'services.waghub.purpose' => 'test-purpose', 'services.waghub.otp_purpose' => 'test-otp',
            'mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.test', 'mail.from.address' => 'noreply@example.test',
        ]);
        foreach ([...self::ADMIN_PERMISSIONS, 'ReceiveEscalation:DocumentRequest', 'receive_reminder'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        Mail::fake();
        Http::preventStrayRequests();
        Http::fake(['waghub.test/*' => Http::response(['status' => 'accepted'], 202)]);
    }

    private function manager(): TrashManager
    {
        return app(TrashManager::class);
    }

    private function superAdmin(string $name = 'Super Admin'): User
    {
        $role = Role::findOrCreate(config('filament-shield.super_admin.name'), 'web');
        $role->givePermissionTo(self::ADMIN_PERMISSIONS);
        $admin = User::factory()->create(['name' => $name]);
        $admin->assignRole($role);
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

    private function documentWithPic(User $pic, string $title = 'Kontrak Lama', ?User $uploader = null): Document
    {
        $document = Document::factory()->create(['pic_user_id' => $pic->id, 'title' => $title, 'document_type_id' => DocumentType::factory()->create(['has_expiry' => true])->id]);
        $version = DocumentVersion::factory()->create(['document_id' => $document->id, 'expiry_date' => today('Asia/Jakarta')->addDays(30), 'uploaded_by' => ($uploader ?? $pic)->id]);
        $document->forceFill(['current_version_id' => $version->id])->save();

        return $document->refresh();
    }

    /** @param array<string, mixed> $attributes */
    private function request(string $status, array $attributes = [], int $daysAgo = 2): DocumentRequest
    {
        return DocumentRequest::factory()->create([
            'status' => $status, 'title' => 'Kontrak Vendor',
            'history' => [['status' => $status, 'user' => 'Pengaju', 'user_id' => null, 'at' => now()->subDays($daysAgo)->toIso8601String(), 'note' => null]],
            ...$attributes,
        ]);
    }

    private function insertSession(string $id, User $user): void
    {
        DB::table('sessions')->insert(['id' => $id, 'user_id' => $user->id, 'payload' => '', 'last_activity' => now()->timestamp]);
    }

    public function test_deleting_active_user_reassigns_work_including_trashed_requests_and_types_then_trashes(): void
    {
        $admin = $this->superAdmin();
        $leaver = $this->staff('081200000001', ['ViewAny:Document', 'Review:DocumentRequest'], 'Staf Keluar');
        $newPic = $this->staff('081200000002', name: 'PIC Baru');
        $reviewer = $this->staff('081200000003', ['Review:DocumentRequest'], 'Pemeriksa Baru');
        $activeDocument = $this->documentWithPic($leaver, 'Kontrak Aktif', $newPic);
        $trashedDocument = $this->documentWithPic($leaver, 'Kontrak Dibuang', $newPic);
        $trashedDocument->delete();
        $openRequest = $this->request('submitted', ['pic_user_id' => $leaver->id, 'reviewer_id' => $leaver->id, 'approver_id' => $leaver->id]);
        $trashedRequest = $this->request('signing', ['pic_user_id' => $leaver->id, 'reviewer_id' => $leaver->id, 'approver_id' => $leaver->id]);
        $pending = DocumentRequestNotification::factory()->create(['document_request_id' => $trashedRequest->id, 'recipient_kind' => 'pic', 'event_key' => "lms-request-{$trashedRequest->id}-1-pic", 'status' => 'pending']);
        $trashedRequest->delete();
        $activeType = DocumentType::factory()->create(['request_reviewer_id' => $leaver->id]);
        $trashedType = DocumentType::factory()->create(['request_pic_id' => $leaver->id, 'request_reviewer_id' => $leaver->id, 'request_approver_id' => $leaver->id]);
        $trashedType->delete();
        $this->insertSession('sesi-target', $leaver);
        $this->insertSession('sesi-admin', $admin);

        $this->manager()->trash($leaver, $admin, [UserDeactivator::GROUP_DOCUMENT_PIC => $newPic->id, UserDeactivator::GROUP_REVIEWER => $reviewer->id]);

        $leaver = User::withTrashed()->find($leaver->id);
        $this->assertTrue($leaver->trashed());
        $this->assertFalse($leaver->isActive());
        $this->assertSame([$admin->id, $admin->id], [$leaver->deactivated_by, $leaver->deleted_by]);
        foreach ([$activeDocument, $trashedDocument] as $document) {
            $this->assertSame($newPic->id, Document::withTrashed()->find($document->id)->pic_user_id);
        }
        foreach ([$openRequest, $trashedRequest] as $request) {
            $request = DocumentRequest::withTrashed()->find($request->id);
            $this->assertSame([$reviewer->id, $reviewer->id, null], [$request->pic_user_id, $request->reviewer_id, $request->approver_id]);
        }
        $this->assertSame($reviewer->id, $activeType->fresh()->request_reviewer_id);
        $trashedType = DocumentType::withTrashed()->find($trashedType->id);
        $this->assertSame([$reviewer->id, $reviewer->id, null], [$trashedType->request_pic_id, $trashedType->request_reviewer_id, $trashedType->request_approver_id]);
        $this->assertSame('cancelled', $pending->fresh()->status);
        $this->assertSame(['sesi-admin'], DB::table('sessions')->pluck('id')->all());
        $this->assertSame(1, TrashLog::where(['subject_type' => 'user', 'subject_id' => $leaver->id, 'action' => 'deleted'])->count());

        // Tugas yang hanya ada di jenis dokumen atau pengajuan di tempat sampah tetap wajib dialihkan.
        $onlyTrashedWork = $this->staff('081200000004', ['Review:DocumentRequest']);
        DocumentType::factory()->create(['request_reviewer_id' => $onlyTrashedWork->id])->delete();
        $this->assertSame([UserDeactivator::GROUP_REVIEWER], app(UserDeactivator::class)->requiredGroups($onlyTrashedWork));
        $this->assertThrows(fn () => $this->manager()->trash($onlyTrashedWork, $admin, [UserDeactivator::GROUP_REVIEWER => null]), ValidationException::class, 'Pilih pengganti yang aktif dan memenuhi syarat.');
        $this->assertFalse($onlyTrashedWork->fresh()->trashed());
    }

    public function test_trashed_user_cannot_log_in_by_password_whatsapp_or_email_otp_and_sessions_end(): void
    {
        $admin = $this->superAdmin();
        Auth::logout();
        $user = $this->staff('6281234567890');
        $user->update(['email' => 'staf@example.test']);
        $whatsApp = Livewire::test(PhoneLogin::class)->fillForm(['whatsapp_number' => '081234567890'])->call('send')->assertHasNoFormErrors();
        preg_match('/\b(\d{6})\b/', Http::recorded()->last()[0]['message']['text'], $whatsAppCode);
        $email = Livewire::test(EmailLogin::class)->fillForm(['email' => 'staf@example.test'])->call('send')->assertHasNoFormErrors();
        $mail = Mail::sent(EmailLoginMail::class)->last();
        $this->insertSession('sesi-target', $user);
        $sessionKey = Auth::guard('web')->getName();

        $this->manager()->trash($user, $admin);

        $whatsApp->set('data.otp', $whatsAppCode[1])->call('verify')->assertHasFormErrors(['whatsapp_number']);
        Livewire::test(PhoneLogin::class)->fillForm(['whatsapp_number' => '081234567890'])->call('send')->assertHasFormErrors(['whatsapp_number']);
        $email->set('data.otp', $mail->otp)->call('verify')->assertHasFormErrors(['email']);
        Livewire::test(EmailLogin::class, ['token' => Str::afterLast($mail->loginUrl, '/')])->call('consumeMagicLink')->assertHasErrors(['data.email'])->assertNoRedirect();
        Livewire::test(EmailLogin::class)->fillForm(['email' => 'staf@example.test'])->call('send')->assertHasFormErrors(['email']);
        Livewire::test(Login::class)->fillForm(['email' => 'staf@example.test', 'password' => 'password'])->call('authenticate')->assertHasFormErrors(['email']);
        $this->assertGuest();
        Http::assertSentCount(1);
        Mail::assertSentCount(1);

        // Penjaga di tingkat user, tidak hanya lewat penonaktifan: user di tempat sampah yang belum nonaktif tetap ditolak.
        $ghost = $this->staff('6281234567899');
        $ghost->update(['email' => 'hantu@example.test']);
        $ghost->delete();
        $this->assertTrue($ghost->isActive());
        $this->assertFalse($ghost->canAccessPanel(Filament::getPanel('admin')));
        Livewire::test(PhoneLogin::class)->fillForm(['whatsapp_number' => '081234567899'])->call('send')->assertHasFormErrors(['whatsapp_number']);
        Livewire::test(EmailLogin::class)->fillForm(['email' => 'hantu@example.test'])->call('send')->assertHasFormErrors(['email']);
        Livewire::test(Login::class)->fillForm(['email' => 'hantu@example.test', 'password' => 'password'])->call('authenticate')->assertHasFormErrors(['email']);
        $this->assertGuest();
        $this->actingAs($ghost)->get(Filament::getPanel('admin')->getUrl())->assertForbidden();

        // Sesi yang masih terbuka berakhir: baris sesi dihapus, dan sesi yang tersisa tidak lagi mengenali user.
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
        $colleague = $this->staff('6281234567898');
        Auth::forgetGuards();
        $this->withSession([$sessionKey => $colleague->id])->get(Filament::getPanel('admin')->getUrl())->assertOk();
        Auth::forgetGuards();
        $this->withSession([$sessionKey => $user->id])->get(Filament::getPanel('admin')->getUrl())->assertRedirect(Filament::getPanel('admin')->getLoginUrl());
        $this->assertGuest();
    }

    public function test_trashed_user_receives_no_whatsapp_and_is_not_offered_as_pic_or_reviewer(): void
    {
        $admin = $this->superAdmin();
        $ghost = $this->staff('081299999999', ['ViewAny:Document', 'Review:DocumentRequest', 'ReceiveEscalation:DocumentRequest', 'receive_reminder'], 'Staf Dibuang');
        $legal = $this->staff('081200000002', ['Review:DocumentRequest', 'ReceiveEscalation:DocumentRequest', 'receive_reminder'], 'Staf Legal');
        $type = DocumentType::factory()->create(['request_pic_id' => $ghost->id, 'request_reviewer_id' => $ghost->id]);
        $handover = DocumentRequest::factory()->handover(30)->create([
            'requester_id' => $ghost->id, 'reviewer_id' => $ghost->id, 'pic_user_id' => $ghost->id, 'title' => 'Kontrak Vendor',
            'history' => [['status' => 'submitted', 'at' => now()->subDays(35)->toIso8601String(), 'note' => null], ['status' => 'handover', 'at' => now()->subDays(30)->toIso8601String(), 'note' => null]],
        ]);
        $waiting = $this->request('submitted', ['requester_id' => $ghost->id, 'reviewer_id' => $ghost->id, 'pic_user_id' => $ghost->id], daysAgo: 10);
        $document = $this->documentWithPic($ghost);
        ReminderTemplate::factory()->create(['schedule_mode' => 'specific_days', 'scheduled_days' => [30], 'body' => ReminderTemplate::DEFAULT_BODY]);
        // Dibuang langsung tanpa penonaktifan dan pengalihan, supaya yang diuji penjagaan di tingkat penerima.
        $ghost->delete();

        app(DocumentRequestNotifier::class)->enqueue($handover->fresh());
        app(DocumentRequestNotifier::class)->enqueue($waiting->fresh());
        app(HandoverReminderSender::class)->run();
        app(DocumentRequestReminderSender::class)->run();
        app(DocumentRequestNotifier::class)->run();
        app(DocumentReminderSender::class)->run();

        $this->assertSame(0, DocumentRequestNotification::where('recipient_phone', '6281299999999')->count());
        $this->assertSame(0, DocumentRequestNotification::where('event_key', 'like', '%-u'.$ghost->id)->count());
        $this->assertSame(0, DocumentRequestNotification::whereIn('recipient_kind', ['applicant', 'pic'])->count());
        $this->assertGreaterThan(0, DocumentRequestNotification::where('recipient_phone', '6281200000002')->count());
        Http::assertNotSent(fn (Request $request): bool => ($request['recipient']['value'] ?? null) === '6281299999999');
        Http::assertSent(fn (Request $request): bool => ($request['recipient']['value'] ?? null) === '6281200000002' && str_contains($request['message']['text'], 'Kontrak Lama'));
        $this->assertSame('6281200000002', ReminderLog::sole()->recipient_phone);
        $this->assertNotSame($ghost->id, $type->fresh()->resolveRequestPic()?->id);

        $this->assertFalse(app(UserDeactivator::class)->candidates($legal, UserDeactivator::GROUP_REVIEWER)->contains('id', $ghost->id));
        $this->assertFalse(app(UserDeactivator::class)->candidates($legal, UserDeactivator::GROUP_DOCUMENT_PIC)->contains('id', $ghost->id));
        $this->assertSame([], User::activeOrKeep(null)->whereKey($ghost->id)->pluck('id')->all());
        $this->actingAs($admin);
        Livewire::test(CreateDocument::class)->fillForm(['pic_user_id' => $ghost->id])->call('create')->assertHasFormErrors(['pic_user_id']);
    }

    public function test_trashed_requester_is_marked_in_request_list_and_detail_but_not_in_messages(): void
    {
        $admin = $this->superAdmin();
        $requester = $this->staff('081200000005', name: 'Pengaju Internal');
        $request = DocumentRequest::factory()->handover(2)->create(['requester_id' => $requester->id, 'reviewer_id' => $admin->id, 'title' => 'Kontrak Vendor',
            'history' => [['status' => 'submitted', 'user' => 'Pengaju', 'user_id' => null, 'at' => now()->toIso8601String(), 'note' => null]]]);
        $this->manager()->trash($requester, $admin);

        Livewire::test(ListDocumentRequests::class)->assertSee('Pengaju Internal (dihapus)');
        Livewire::test(EditDocumentRequest::class, ['record' => $request->getRouteKey()])->assertSee('Pengaju Internal (dihapus)');
        $this->assertSame('Pengaju Internal', $request->fresh()->applicantName());
        $this->assertSame('Pengaju Internal (dihapus)', $request->fresh()->applicantDisplayName());
    }

    public function test_restored_user_stays_deactivated(): void
    {
        $admin = $this->superAdmin();
        $user = $this->staff('081200000006');
        $this->manager()->trash($user, $admin);
        $user = User::withTrashed()->find($user->id);

        $this->manager()->restore($user, $admin);

        $user = $user->fresh();
        $this->assertFalse($user->trashed());
        $this->assertFalse($user->isActive());
        $this->assertSame($admin->id, $user->deactivated_by);
        $this->assertFalse($user->canAccessPanel(Filament::getPanel('admin')));
        $this->assertSame(1, TrashLog::where(['subject_type' => 'user', 'subject_id' => $user->id, 'action' => 'restored'])->count());
    }

    public function test_force_delete_blocked_by_trashed_document_where_user_is_pic(): void
    {
        $admin = $this->superAdmin();
        $user = User::factory()->deactivated()->create(['name' => 'Mantan Staf']);
        $document = $this->documentWithPic($user, 'Kontrak Lama', $admin);
        $this->manager()->trash($document, $admin);
        $this->manager()->trash($user, $admin);
        $user = User::withTrashed()->find($user->id);

        $this->assertThrows(fn () => $this->manager()->forceDelete($user, $admin), ValidationException::class,
            'Tidak bisa dihapus permanen: masih dipakai 1 dokumen sebagai PIC (Kontrak Lama). Hapus atau pindahkan data itu dulu.');

        $this->assertNotNull(User::withTrashed()->find($user->id));
        $this->assertTrue(Document::withTrashed()->find($document->id)->trashed());
        $this->assertSame(1, DocumentVersion::where('document_id', $document->id)->count());
        $this->assertSame(0, TrashLog::where('action', 'force_deleted')->count());

        // Semua referensi wajib, termasuk di data yang di tempat sampah, ikut menghalangi.
        $uploaderDocument = $this->documentWithPic($admin, 'Kontrak Unggahan', $user);
        $requested = $this->request('archived', ['requester_id' => $user->id]);
        $handled = $this->request('archived', ['pic_user_id' => $user->id]);
        $handled->delete();
        $type = DocumentType::factory()->create(['name' => 'Perjanjian Kerja']);
        DocumentType::withTrashed()->whereKey($type->id)->toBase()->update(['request_pic_id' => $user->id, 'request_reviewer_id' => $user->id]);
        $type->delete();
        DocumentImport::factory()->create(['user_id' => $user->id, 'excel_name' => 'impor-lama.xlsx']);

        $usage = $this->manager()->usage($user);
        $this->assertSame([
            ['label' => 'dokumen sebagai PIC', 'count' => 1, 'examples' => ['Kontrak Lama']],
            ['label' => 'versi dokumen sebagai pengunggah', 'count' => 1, 'examples' => ['Kontrak Unggahan v'.$uploaderDocument->currentVersion->version_number]],
            ['label' => 'pengajuan sebagai pengaju', 'count' => 1, 'examples' => ['#'.$requested->id]],
            ['label' => 'pengajuan sebagai PIC', 'count' => 1, 'examples' => ['#'.$handled->id]],
            ['label' => 'jenis dokumen sebagai PIC pengajuan', 'count' => 1, 'examples' => ['Perjanjian Kerja']],
            ['label' => 'jenis dokumen sebagai pemeriksa', 'count' => 1, 'examples' => ['Perjanjian Kerja']],
            ['label' => 'riwayat impor', 'count' => 1, 'examples' => ['impor-lama.xlsx']],
        ], $usage->active);
        $this->assertSame([], $usage->trashed);
    }

    public function test_force_delete_unused_user_clears_optional_refs_roles_and_sessions(): void
    {
        $admin = $this->superAdmin();
        $role = Role::findOrCreate('Staf Legal', 'web');
        $user = $this->staff('081200000007', ['ViewAny:Document', 'Review:DocumentRequest']);
        $user->assignRole($role);
        $closed = $this->request('archived', ['reviewer_id' => $user->id, 'approver_id' => $user->id]);
        $closed->delete();
        $type = DocumentType::factory()->create();
        $document = $this->documentWithPic($admin, 'Kontrak Admin');
        $activity = DocumentActivity::create(['document_id' => $document->id, 'document_version_id' => $document->current_version_id, 'user_id' => $user->id, 'event' => 'previewed']);
        $otp = WhatsappOtp::factory()->create(['user_id' => $user->id]);
        $this->manager()->trash($user, $admin);
        DocumentType::query()->whereKey($type->id)->toBase()->update(['request_approver_id' => $user->id]);
        $type->delete();
        $this->insertSession('sesi-lama', $user);
        $user = User::withTrashed()->find($user->id);

        $this->manager()->forceDelete($user, $admin);

        $this->assertNull(User::withTrashed()->find($user->id));
        $closed = DocumentRequest::withTrashed()->find($closed->id);
        $this->assertSame([null, null], [$closed->reviewer_id, $closed->approver_id]);
        $this->assertNull(DocumentType::withTrashed()->find($type->id)->request_approver_id);
        $this->assertSame(0, DB::table('model_has_roles')->where('model_id', $user->id)->count());
        $this->assertSame(0, DB::table('model_has_permissions')->where('model_id', $user->id)->count());
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->assertNull($activity->fresh()->user_id);
        $this->assertNull($otp->fresh()->user_id);
        $this->assertNotNull(Role::findByName('Staf Legal', 'web'));
        $this->assertSame(1, TrashLog::where(['subject_type' => 'user', 'subject_id' => $user->id, 'action' => 'force_deleted'])->count());
    }

    public function test_bulk_trash_skips_users_with_work(): void
    {
        $admin = $this->superAdmin();
        $free = $this->staff('081200000008', name: 'Staf Bebas');
        $busy = $this->staff('081200000009', name: 'Staf Sibuk');
        $this->documentWithPic($busy);
        $formerWithLeftover = User::factory()->deactivated()->create(['name' => 'Mantan Staf']);

        $result = $this->manager()->bulk('trash', [$free, $busy, $formerWithLeftover], $admin);

        $this->assertSame(['done' => 2, 'skipped' => [['label' => 'Staf Sibuk', 'reason' => 'punya tugas yang harus dialihkan, hapus satu per satu']]], $result);
        $this->assertTrue(User::withTrashed()->find($free->id)->trashed());
        $this->assertFalse(User::withTrashed()->find($free->id)->isActive());
        $this->assertTrue(User::withTrashed()->find($formerWithLeftover->id)->trashed());
        $this->assertFalse($busy->fresh()->trashed());
        $this->assertTrue($busy->fresh()->isActive());
    }

    public function test_cannot_trash_self_or_last_super_admin(): void
    {
        $admin = $this->superAdmin();
        $staffAdmin = $this->staff('081200000010', ['ViewAny:User', 'View:User', 'Update:User', 'Delete:User'], 'Admin Biasa');
        $formerSuperAdmin = User::factory()->deactivated()->create();
        $formerSuperAdmin->assignRole(config('filament-shield.super_admin.name'));
        $trashedSuperAdmin = User::factory()->create();
        $trashedSuperAdmin->assignRole(config('filament-shield.super_admin.name'));
        $trashedSuperAdmin->delete();

        $this->assertThrows(fn () => $this->manager()->trash($admin, $admin), ValidationException::class, 'Anda tidak dapat menonaktifkan akun sendiri.');
        $this->assertThrows(fn () => $this->manager()->trash($admin, $staffAdmin), ValidationException::class, 'Hanya super admin yang dapat menonaktifkan super admin.');
        $this->assertThrows(fn () => $this->manager()->trash($admin, $formerSuperAdmin), ValidationException::class, 'Super admin aktif terakhir tidak dapat dinonaktifkan.');
        $this->assertThrows(fn () => $this->manager()->trash($formerSuperAdmin, $staffAdmin), ValidationException::class, 'Hanya super admin yang dapat menghapus super admin.');
        $this->assertFalse($staffAdmin->can('delete', $admin));
        $this->assertFalse($admin->fresh()->trashed());
        $this->assertTrue($admin->fresh()->isActive());
        $this->assertFalse($formerSuperAdmin->fresh()->trashed());

        $this->manager()->trash($formerSuperAdmin, $admin);
        $this->assertTrue(User::withTrashed()->find($formerSuperAdmin->id)->trashed());
    }
}
