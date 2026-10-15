<?php

namespace Tests\Feature;

use App\Filament\Resources\ReminderTemplates\Pages\ManageReminderTemplates;
use App\Models\DocumentRequest;
use App\Models\ReminderTemplate;
use App\Models\User;
use App\Services\DocumentRequestNotifier;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class WhatsAppTemplateSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Http::preventStrayRequests();
        Http::fake(['waghub.test/*' => Http::response([], 202)]);
        config(['services.waghub.url' => 'https://waghub.test', 'services.waghub.token' => 'test-token', 'services.waghub.purpose' => 'test-purpose']);
    }

    public static function statuses(): array
    {
        return array_map(fn (string $status): array => [$status], ['submitted', 'review', 'approved', 'revision', 'rejected', 'archived']);
    }

    #[DataProvider('statuses')]
    public function test_custom_template_is_used_for_each_status(string $status): void
    {
        ReminderTemplate::factory()->create(['is_active' => false, 'request_templates' => [
            $status => '{pengaju}: {judul} #{nomor_pengajuan} | {status} | {catatan} | {perusahaan} | {jenis_dokumen} | {pic} | {tautan}',
        ]]);
        $request = DocumentRequest::factory()->create([
            'status' => $status, 'requester_name' => 'Pengaju Publik', 'requester_phone' => '081234567890',
            'public_token' => str_repeat('a', 64), 'history' => [['status' => $status, 'note' => 'Lengkapi lampiran {judul}.']],
        ]);
        app(DocumentRequestNotifier::class)->enqueue($request);
        $notification = $request->notifications()->where('recipient_kind', 'applicant')->sole();
        $expected = 'Pengaju Publik: '.$request->title.' #'.$request->id.' | '.DocumentRequest::STATUSES[$status]
            .' | Lengkapi lampiran {judul}. | '.$request->company->name.' | '.$request->documentType->name.' | '.$request->pic->name
            .' | '.route('requests.public.status', ['token' => $request->public_token]);
        $this->assertSame($expected, $notification->payload['message']['text']);
        app(DocumentRequestNotifier::class)->run($request->id);
        Http::assertSent(fn ($outgoing): bool => $outgoing['recipient']['value'] === '6281234567890' && $outgoing['message']['text'] === $expected);
    }

    #[DataProvider('statuses')]
    public function test_default_status_template_is_used_without_settings(string $status): void
    {
        $request = DocumentRequest::factory()->create(['status' => $status, 'history' => [['status' => $status, 'note' => 'Catatan uji']]]);
        app(DocumentRequestNotifier::class)->enqueue($request);
        $text = $request->notifications()->where('recipient_kind', 'applicant')->sole()->payload['message']['text'];
        $this->assertStringContainsString('Pengajuan #'.$request->id."\n".$request->title, $text);
        $this->assertStringContainsString("\nCatatan: Catatan uji\n", $text);
        $this->assertStringNotContainsString('{', $text);
    }

    public function test_pic_template_and_unconfigured_status_keep_distinct_recipients(): void
    {
        ReminderTemplate::factory()->create(['request_templates' => ['pic' => 'PIC {pic}: {judul}, dari {pengaju}. {tautan}']]);
        $request = DocumentRequest::factory()->create(['status' => 'submitted']);
        app(DocumentRequestNotifier::class)->enqueue($request);
        $this->assertSame(2, $request->notifications()->count());
        $this->assertSame('PIC '.$request->pic->name.': '.$request->title.', dari '.$request->applicantName().'. '.route('filament.admin.resources.document-requests.edit', ['record' => $request]),
            $request->notifications()->where('recipient_kind', 'pic')->sole()->payload['message']['text']);
        $applicantText = $request->notifications()->where('recipient_kind', 'applicant')->sole()->payload['message']['text'];
        $this->assertStringContainsString('Pengajuan diterima dan sedang diperiksa.', $applicantText);
        $this->assertStringNotContainsString('Catatan:', $applicantText);
    }

    public static function invalidTemplates(): array
    {
        return [[''], ['   '], ['{tidak_ada}'], [str_repeat('a', 4001)], [123]];
    }

    #[DataProvider('invalidTemplates')]
    public function test_invalid_template_cannot_be_saved(mixed $body): void
    {
        $this->expectException(ValidationException::class);
        ReminderTemplate::factory()->create(['request_templates' => ['revision' => $body]]);
    }

    private function authorizeSettings(array $permissions = ['ViewAny:ReminderTemplate', 'Create:ReminderTemplate', 'Update:ReminderTemplate']): void
    {
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);
        $this->actingAs($user);
    }

    public function test_authorized_user_can_edit_legacy_settings_with_default_templates(): void
    {
        $this->authorizeSettings(['ViewAny:ReminderTemplate', 'Update:ReminderTemplate']);
        $setting = ReminderTemplate::factory()->create(['schedule_mode' => 'interval']);
        Livewire::test(ManageReminderTemplates::class)->assertSee('Perlu revisi')->assertSee('Ditolak')
            ->mountAction(TestAction::make('edit_revision')->schemaComponent('notification_settings.template_navigation.revision.template_revision', 'content'))
            ->assertActionDataSet(['request_templates' => ['revision' => ReminderTemplate::DEFAULT_REQUEST_TEMPLATES['revision']]])
            ->setActionData(['request_templates' => ['revision' => 'Revisi: {judul}. {catatan} {tautan}']])
            ->callMountedAction()->assertHasNoActionErrors()
            ->assertSee('Revisi: Pengajuan Kontrak Dummy. Mohon lengkapi lampiran.');
        $this->assertSame('Revisi: {judul}. {catatan} {tautan}', $setting->fresh()->request_templates['revision']);
        $this->assertSame($setting->body, $setting->fresh()->body);
        $this->assertArrayNotHasKey('rejected', $setting->fresh()->request_templates);
    }

    public function test_settings_form_rejects_unknown_variables(): void
    {
        $this->authorizeSettings();
        $setting = ReminderTemplate::factory()->create(['schedule_mode' => 'interval']);
        Livewire::test(ManageReminderTemplates::class)
            ->callAction(TestAction::make('edit_rejected')->schemaComponent('notification_settings.template_navigation.rejected.template_rejected', 'content'), ['request_templates' => ['rejected' => '{tidak_ada}']])
            ->assertHasActionErrors(['request_templates.rejected']);
        $this->assertNull($setting->fresh()->request_templates);
    }

    public function test_schedule_form_saves_overdue_days(): void
    {
        $this->authorizeSettings(['ViewAny:ReminderTemplate', 'Update:ReminderTemplate']);
        $setting = ReminderTemplate::factory()->create(['schedule_mode' => 'interval']);
        Livewire::test(ManageReminderTemplates::class)
            ->callAction(TestAction::make('edit_schedule')->schemaComponent('expiry_settings.reminder_schedule', 'content'), [
                'is_active' => true, 'schedule_mode' => 'interval', 'start_before_days' => 30, 'interval_days' => 7, 'scheduled_days' => [], 'overdue_days' => [1, '7'],
            ])->assertHasNoActionErrors();
        $this->assertSame([1, 7], $setting->fresh()->overdueOffsets());
    }

    public function test_overdue_template_can_be_customized_previewed_and_restored(): void
    {
        $this->authorizeSettings(['ViewAny:ReminderTemplate', 'Update:ReminderTemplate']);
        $setting = ReminderTemplate::factory()->create(['schedule_mode' => 'interval']);
        $component = 'expiry_settings.template_overdue';
        Livewire::test(ManageReminderTemplates::class)
            ->assertSee('Pesan setelah kedaluwarsa')->assertSee('Terlambat: 3 hari')
            ->mountAction(TestAction::make('edit_overdue')->schemaComponent($component, 'content'))
            ->assertActionDataSet(['overdue_body' => ReminderTemplate::DEFAULT_OVERDUE_BODY])
            ->setActionData(['overdue_body' => 'LEGAL: {dokumen} lewat {hari_terlambat} hari. PIC {pic}'])
            ->callMountedAction()->assertHasNoActionErrors()
            ->assertSee('LEGAL: Kontrak Dummy lewat 3 hari. PIC PIC Dummy');
        $this->assertSame('LEGAL: {dokumen} lewat {hari_terlambat} hari. PIC {pic}', $setting->fresh()->overdue_body);
        $this->assertSame($setting->body, $setting->fresh()->body);

        Livewire::test(ManageReminderTemplates::class)
            ->callAction(TestAction::make('reset_overdue')->schemaComponent($component, 'content'))->assertHasNoActionErrors();
        $this->assertNull($setting->fresh()->overdue_body);
        $this->assertSame(ReminderTemplate::DEFAULT_OVERDUE_BODY, $setting->fresh()->overdueBody());
    }

    public function test_overdue_template_rejects_unknown_variables_including_remaining_days(): void
    {
        $this->authorizeSettings();
        $setting = ReminderTemplate::factory()->create(['schedule_mode' => 'interval']);
        foreach (['{tidak_ada}', 'Sisa {sisa_hari}'] as $body) {
            Livewire::test(ManageReminderTemplates::class)
                ->callAction(TestAction::make('edit_overdue')->schemaComponent('expiry_settings.template_overdue', 'content'), ['overdue_body' => $body])
                ->assertHasActionErrors(['overdue_body']);
        }
        $this->assertNull($setting->fresh()->overdue_body);
    }

    public function test_request_reminder_schedule_can_be_saved_and_rejects_invalid_values(): void
    {
        $this->authorizeSettings(['ViewAny:ReminderTemplate', 'Update:ReminderTemplate']);
        $setting = ReminderTemplate::factory()->create(['schedule_mode' => 'interval']);
        $component = 'notification_settings.request_reminder';
        Livewire::test(ManageReminderTemplates::class)
            ->assertSee('Pengingat pengajuan menggantung')->assertSee('2 hari')->assertSee('3 kali')
            ->mountAction(TestAction::make('edit_request_reminder_settings')->schemaComponent($component, 'content'))
            ->assertActionDataSet(['request_reminder_enabled' => true, 'request_reminder_after_days' => 2, 'request_reminder_interval_days' => 2, 'request_reminder_max' => 3])
            ->setActionData(['request_reminder_enabled' => false, 'request_reminder_after_days' => 1, 'request_reminder_interval_days' => 3, 'request_reminder_max' => 5])
            ->callMountedAction()->assertHasNoActionErrors();
        $fresh = $setting->fresh();
        $this->assertFalse($fresh->request_reminder_enabled);
        $this->assertSame([1, 3, 5], [$fresh->request_reminder_after_days, $fresh->request_reminder_interval_days, $fresh->request_reminder_max]);

        Livewire::test(ManageReminderTemplates::class)
            ->callAction(TestAction::make('edit_request_reminder_settings')->schemaComponent($component, 'content'), [
                'request_reminder_enabled' => true, 'request_reminder_after_days' => 0, 'request_reminder_interval_days' => 366, 'request_reminder_max' => 11,
            ])->assertHasActionErrors(['request_reminder_after_days', 'request_reminder_interval_days', 'request_reminder_max']);
        $this->assertSame(1, $setting->fresh()->request_reminder_after_days);
    }

    public function test_request_reminder_template_can_be_customized_and_restored(): void
    {
        $this->authorizeSettings(['ViewAny:ReminderTemplate', 'Update:ReminderTemplate']);
        $setting = ReminderTemplate::factory()->create(['schedule_mode' => 'interval']);
        $component = 'notification_settings.request_reminder';
        Livewire::test(ManageReminderTemplates::class)
            ->mountAction(TestAction::make('edit_request_reminder')->schemaComponent($component, 'content'))
            ->assertActionDataSet(['request_reminder_body' => ReminderTemplate::DEFAULT_REQUEST_REMINDER_BODY])
            ->setActionData(['request_reminder_body' => 'SEGERA: {judul} menunggu {hari_menunggu} hari'])
            ->callMountedAction()->assertHasNoActionErrors()
            ->assertSee('SEGERA: Pengajuan Kontrak Dummy menunggu 3 hari');
        $this->assertSame('SEGERA: {judul} menunggu {hari_menunggu} hari', $setting->fresh()->request_reminder_body);

        Livewire::test(ManageReminderTemplates::class)
            ->callAction(TestAction::make('reset_request_reminder')->schemaComponent($component, 'content'))->assertHasNoActionErrors();
        $this->assertNull($setting->fresh()->request_reminder_body);

        Livewire::test(ManageReminderTemplates::class)
            ->callAction(TestAction::make('edit_request_reminder')->schemaComponent($component, 'content'), ['request_reminder_body' => 'Halo {pic}'])
            ->assertHasActionErrors(['request_reminder_body']);
    }

    public function test_user_without_permission_cannot_access_settings(): void
    {
        $this->actingAs(User::factory()->create());
        Livewire::test(ManageReminderTemplates::class)->assertForbidden();
    }

    public function test_authorized_user_can_create_settings_with_all_default_templates(): void
    {
        $this->authorizeSettings(['ViewAny:ReminderTemplate', 'Create:ReminderTemplate']);
        Livewire::test(ManageReminderTemplates::class)->callAction(TestAction::make('edit_reminder')->schemaComponent('expiry_settings.template_reminder', 'content'))->assertHasNoActionErrors();
        $this->assertSame(ReminderTemplate::DEFAULT_REQUEST_TEMPLATES, ReminderTemplate::globalSetting()->request_templates);
        $this->assertFalse(ReminderTemplate::globalSetting()->is_active);
    }

    public function test_retry_preserves_queued_message_after_template_changes(): void
    {
        $setting = ReminderTemplate::factory()->create(['request_templates' => ['revision' => 'Pesan awal: {judul}']]);
        $request = DocumentRequest::factory()->create(['status' => 'revision', 'requester_phone' => '081234567890']);
        app(DocumentRequestNotifier::class)->enqueue($request);
        $setting->update(['request_templates' => ['revision' => 'Pesan diubah: {judul}']]);
        app(DocumentRequestNotifier::class)->enqueue($request);
        $this->assertSame(1, $request->notifications()->count());
        app(DocumentRequestNotifier::class)->run($request->id);
        Http::assertSent(fn ($outgoing): bool => $outgoing['message']['text'] === 'Pesan awal: '.$request->title);
    }

    public function test_template_can_be_configured_before_reminders_without_enabling_them(): void
    {
        $this->authorizeSettings(['ViewAny:ReminderTemplate', 'Create:ReminderTemplate']);
        Livewire::test(ManageReminderTemplates::class)
            ->callAction(TestAction::make('edit_revision')->schemaComponent('notification_settings.template_navigation.revision.template_revision', 'content'), [
                'request_templates' => ['revision' => 'Mohon revisi {judul}.'],
            ])->assertHasNoActionErrors();
        $setting = ReminderTemplate::globalSetting();
        $this->assertFalse($setting->is_active);
        $this->assertSame('Mohon revisi {judul}.', $setting->request_templates['revision']);
        $this->assertSame(ReminderTemplate::DEFAULT_REQUEST_TEMPLATES['rejected'], $setting->request_templates['rejected']);
    }

    public function test_read_only_user_can_view_separate_templates_but_cannot_edit(): void
    {
        $this->authorizeSettings(['ViewAny:ReminderTemplate']);
        ReminderTemplate::factory()->create();
        Livewire::test(ManageReminderTemplates::class)->assertSuccessful()
            ->assertSee('Pengingat masa berlaku')->assertSee('Perlu revisi')->assertSee('Ditolak')->assertDontSee('Ubah')
            ->assertDontSee('3 hari sekali');
    }

    public function test_settings_page_renders_message_examples_with_separate_template_navigation(): void
    {
        $this->authorizeSettings();
        ReminderTemplate::factory()->create(['schedule_mode' => 'interval', 'start_before_days' => 30, 'interval_days' => 7]);
        $response = $this->get(route('filament.admin.resources.reminder-templates.index'));
        $response->assertSuccessful()->assertSee('whatsapp-settings-tabs')->assertSee('Kontrak Dummy')->assertSee('Contoh pesan')
            ->assertSee('content.expiry_settings.reminder_schedule', false)
            ->assertSee('Notifikasi status pengajuan')
            ->assertDontSee('notification_settings.template_navigation.reminder', false);
    }

    public function test_editor_displays_draggable_data_and_saves_inserted_variables(): void
    {
        $this->authorizeSettings();
        $setting = ReminderTemplate::factory()->create(['request_templates' => ['revision' => 'Halo']]);
        $editor = Livewire::test(ManageReminderTemplates::class)
            ->mountAction(TestAction::make('edit_revision')->schemaComponent('notification_settings.template_navigation.revision.template_revision', 'content'))
            ->assertActionDataSet(['request_templates' => ['revision' => 'Halo']]);
        $editorHtml = $editor->getMountedActionModalHtml();
        $this->assertStringContainsString('draggable="true"', $editorHtml);
        $this->assertStringContainsString('data-variable="{pengaju}"', $editorHtml);
        $this->assertStringContainsString('Tambahkan Nama pengaju ke pesan', $editorHtml);
        $editor->setActionData(['request_templates' => ['revision' => 'Halo {pengaju}']])
            ->callMountedAction()->assertHasNoActionErrors();
        $this->assertSame(['revision' => 'Halo {pengaju}'], $setting->fresh()->request_templates);
    }

    public static function reminderSchedules(): array
    {
        return [
            'repeating' => [['schedule_mode' => 'interval', 'start_before_days' => 30, 'interval_days' => 7], '30 hari sebelum · 23 hari sebelum · 16 hari sebelum · 9 hari sebelum · 2 hari sebelum'],
            'specific days' => [['schedule_mode' => 'specific_days', 'scheduled_days' => [30, 7, 1, 0]], '30 hari sebelum · 7 hari sebelum · 1 hari sebelum · Tanggal berakhir'],
        ];
    }

    #[DataProvider('reminderSchedules')]
    public function test_reminder_schedule_is_visible_and_saved_separately_from_messages(array $schedule, string $days): void
    {
        $this->authorizeSettings();
        $setting = ReminderTemplate::factory()->create(['schedule_mode' => 'interval', 'request_templates' => ['revision' => 'Revisi {judul}']]);
        config(['lms.reminder_time' => '08:00', 'lms.reminder_timezone' => 'Asia/Jakarta']);
        $page = Livewire::test(ManageReminderTemplates::class)
            ->assertSee('Jadwal pengiriman')->assertSee('08:00 WIB')
            ->callAction(TestAction::make('edit_schedule')->schemaComponent('expiry_settings.reminder_schedule', 'content'), [...$schedule, 'is_active' => true])
            ->assertHasNoActionErrors();
        $page->mountAction(TestAction::make('edit_schedule')->schemaComponent('expiry_settings.reminder_schedule', 'content'));
        $page->assertActionDataSet($schedule);
        $this->assertStringNotContainsString('3 hari sekali', $page->getMountedActionModalHtml());
        $this->assertStringNotContainsString('wa-timeline', $page->getMountedActionModalHtml());
        $this->assertSame($setting->body, $setting->fresh()->body);
        $this->assertSame($setting->request_templates, $setting->fresh()->request_templates);
        $this->assertSame($schedule['schedule_mode'], $setting->fresh()->schedule_mode);
    }

    public function test_editing_reminder_text_preserves_schedule_and_active_state(): void
    {
        $this->authorizeSettings();
        $setting = ReminderTemplate::factory()->create(['is_active' => true, 'schedule_mode' => 'specific_days', 'scheduled_days' => [7, 1, 0]]);
        $editor = Livewire::test(ManageReminderTemplates::class)
            ->mountAction(TestAction::make('edit_reminder')->schemaComponent('expiry_settings.template_reminder', 'content'));
        $this->assertStringNotContainsString('schedule_mode', $editor->getMountedActionModalHtml());
        $editor->setActionData(['body' => 'Pengingat {dokumen}.', 'is_active' => false, 'scheduled_days' => [30]])
            ->callMountedAction()->assertHasNoActionErrors();
        $this->assertSame('Pengingat {dokumen}.', $setting->fresh()->body);
        $this->assertTrue($setting->fresh()->is_active);
        $this->assertSame([7, 1, 0], $setting->fresh()->scheduled_days);
    }

    public static function quickIntervals(): array
    {
        return [[1], [3], [7]];
    }

    #[DataProvider('quickIntervals')]
    public function test_quick_interval_saves_directly_and_preserves_other_settings(int $days): void
    {
        $this->authorizeSettings();
        $setting = ReminderTemplate::factory()->create([
            'is_active' => false, 'schedule_mode' => 'specific_days', 'start_before_days' => 21,
            'interval_days' => 14, 'scheduled_days' => [7, 1], 'request_templates' => ['revision' => 'Revisi {judul}'],
        ]);
        $editor = Livewire::test(ManageReminderTemplates::class)
            ->callAction(TestAction::make('interval_'.$days)->schemaComponent('expiry_settings.reminder_schedule', 'content'))
            ->assertHasNoActionErrors();
        $saved = $setting->fresh();
        $this->assertSame('interval', $saved->schedule_mode);
        $this->assertSame($days, $saved->interval_days);
        $this->assertSame(21, $saved->start_before_days);
        $this->assertSame(range(21, 0, -$days), $saved->reminderOffsets());
        $this->assertFalse($saved->is_active);
        $this->assertSame($setting->body, $saved->body);
        $this->assertSame($setting->request_templates, $saved->request_templates);
    }
}
