<?php

namespace Tests\Feature;

use App\Filament\Resources\ReminderLogs\ReminderLogResource;
use App\Filament\Resources\ReminderTemplates\Pages\ManageReminderTemplates;
use App\Filament\Resources\ReminderTemplates\ReminderTemplateResource;
use App\Models\ReminderTemplate;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class WhatsAppScheduleSetupTest extends TestCase
{
    use RefreshDatabase;

    private const SCHEDULE = 'expiry_settings.reminder_schedule';

    private const INACTIVE_WARNING = "Jadwal disimpan, tetapi pengingat otomatis masih nonaktif. Aktifkan lewat 'Atur jadwal'.";

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /** @param list<string> $permissions */
    private function authorizeSettings(array $permissions = ['ViewAny:ReminderTemplate', 'Create:ReminderTemplate', 'Update:ReminderTemplate']): User
    {
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);
        $this->actingAs($user);

        return $user;
    }

    /** @return list<array{title: string, body: ?string, status: ?string}> */
    private function sentNotifications(): array
    {
        return collect([...session()->pull('filament.claimed_notifications', []), ...session()->pull('filament.notifications', [])])
            ->map(fn (array $notification): array => ['title' => $notification['title'], 'body' => $notification['body'] ?? null, 'status' => $notification['status'] ?? null])->all();
    }

    public static function quickIntervals(): array
    {
        return [[1], [3], [7]];
    }

    #[DataProvider('quickIntervals')]
    public function test_quick_interval_on_a_fresh_install_creates_an_active_schedule(int $days): void
    {
        $this->authorizeSettings();
        $this->assertNull(ReminderTemplate::globalSetting());

        Livewire::test(ManageReminderTemplates::class)
            ->callAction(TestAction::make('interval_'.$days)->schemaComponent(self::SCHEDULE, 'content'))
            ->assertHasNoActionErrors()->assertSee('Aktif');

        $setting = ReminderTemplate::globalSetting();
        $this->assertTrue($setting->is_active);
        $this->assertSame('interval', $setting->schedule_mode);
        $this->assertSame($days, $setting->interval_days);
        $this->assertSame(30, $setting->start_before_days);
        $this->assertSame(ReminderTemplate::DEFAULT_REQUEST_TEMPLATES, $setting->request_templates);
        $this->assertSame(range(30, 0, -$days), ReminderTemplate::globalReminderDays());
        $this->assertSame([['title' => 'Jadwal pengingat disimpan', 'body' => null, 'status' => 'success']], $this->sentNotifications());
    }

    #[DataProvider('quickIntervals')]
    public function test_quick_interval_on_an_inactive_setting_saves_but_warns_that_reminders_are_still_off(int $days): void
    {
        $this->authorizeSettings();
        $setting = ReminderTemplate::factory()->create(['is_active' => false, 'schedule_mode' => 'interval', 'start_before_days' => 30, 'interval_days' => 14]);

        Livewire::test(ManageReminderTemplates::class)
            ->callAction(TestAction::make('interval_'.$days)->schemaComponent(self::SCHEDULE, 'content'))->assertHasNoActionErrors();

        $saved = $setting->fresh();
        $this->assertFalse($saved->is_active);
        $this->assertSame($days, $saved->interval_days);
        $this->assertSame([], ReminderTemplate::globalReminderDays());
        $this->assertSame([['title' => self::INACTIVE_WARNING, 'body' => null, 'status' => 'warning']], $this->sentNotifications());
    }

    public function test_quick_interval_on_an_active_setting_confirms_without_warning(): void
    {
        $this->authorizeSettings();
        $setting = ReminderTemplate::factory()->create(['is_active' => true, 'schedule_mode' => 'specific_days', 'scheduled_days' => [7, 1]]);

        Livewire::test(ManageReminderTemplates::class)
            ->callAction(TestAction::make('interval_3')->schemaComponent(self::SCHEDULE, 'content'))->assertHasNoActionErrors();

        $this->assertTrue($setting->fresh()->is_active);
        $this->assertSame('interval', $setting->fresh()->schedule_mode);
        $this->assertSame([['title' => 'Jadwal pengingat disimpan', 'body' => null, 'status' => 'success']], $this->sentNotifications());
    }

    public function test_schedule_form_with_the_switch_off_also_warns_instead_of_claiming_reminders_run(): void
    {
        $this->authorizeSettings();

        Livewire::test(ManageReminderTemplates::class)
            ->callAction(TestAction::make('edit_schedule')->schemaComponent(self::SCHEDULE, 'content'), [
                'is_active' => false, 'schedule_mode' => 'interval', 'start_before_days' => 30, 'interval_days' => 7, 'scheduled_days' => [], 'overdue_days' => [],
            ])->assertHasNoActionErrors();

        $this->assertFalse(ReminderTemplate::globalSetting()->is_active);
        $this->assertSame([['title' => self::INACTIVE_WARNING, 'body' => null, 'status' => 'warning']], $this->sentNotifications());
    }

    public function test_schedule_form_on_a_fresh_install_defaults_to_active(): void
    {
        $this->authorizeSettings();

        Livewire::test(ManageReminderTemplates::class)
            ->mountAction(TestAction::make('edit_schedule')->schemaComponent(self::SCHEDULE, 'content'))
            ->assertActionDataSet(['is_active' => true, 'schedule_mode' => 'interval', 'start_before_days' => 30, 'interval_days' => 7])
            ->callMountedAction()->assertHasNoActionErrors();

        $this->assertTrue(ReminderTemplate::globalSetting()->is_active);
        $this->assertSame([['title' => 'Jadwal pengingat disimpan', 'body' => null, 'status' => 'success']], $this->sentNotifications());
    }

    public static function expiryMessageEdits(): array
    {
        return [
            'reminder' => ['edit_reminder', 'expiry_settings.template_reminder', ['body' => 'Pengingat {dokumen}.']],
            'overdue' => ['edit_overdue', 'expiry_settings.template_overdue', ['overdue_body' => 'Lewat {hari_terlambat} hari: {dokumen}']],
        ];
    }

    /** @param array<string, string> $data */
    #[DataProvider('expiryMessageEdits')]
    public function test_editing_an_expiry_message_on_a_fresh_install_stays_inactive_and_says_so(string $action, string $component, array $data): void
    {
        $this->authorizeSettings();

        Livewire::test(ManageReminderTemplates::class)
            ->callAction(TestAction::make($action)->schemaComponent($component, 'content'), $data)->assertHasNoActionErrors();

        $this->assertFalse(ReminderTemplate::globalSetting()->is_active);
        $this->assertSame([[
            'title' => 'Template WhatsApp disimpan',
            'body' => "Pengingat masa berlaku otomatis masih nonaktif. Aktifkan lewat 'Atur jadwal'.",
            'status' => 'success',
        ]], $this->sentNotifications());
    }

    public function test_editing_a_message_of_an_active_schedule_does_not_mention_inactivity(): void
    {
        $this->authorizeSettings();
        ReminderTemplate::factory()->create(['is_active' => true, 'schedule_mode' => 'interval']);

        Livewire::test(ManageReminderTemplates::class)
            ->callAction(TestAction::make('edit_reminder')->schemaComponent('expiry_settings.template_reminder', 'content'), ['body' => 'Pengingat {dokumen}.']);

        $this->assertSame([['title' => 'Template WhatsApp disimpan', 'body' => null, 'status' => 'success']], $this->sentNotifications());
    }

    public function test_status_message_template_and_request_reminder_edits_never_claim_the_schedule_is_saved(): void
    {
        $this->authorizeSettings();
        $page = Livewire::test(ManageReminderTemplates::class)
            ->callAction(TestAction::make('edit_revision')->schemaComponent('notification_settings.template_navigation.revision.template_revision', 'content'), [
                'request_templates' => ['revision' => 'Mohon revisi {judul}.'],
            ])->assertHasNoActionErrors();
        $this->assertSame([['title' => 'Template WhatsApp disimpan', 'body' => null, 'status' => 'success']], $this->sentNotifications());
        $this->assertFalse(ReminderTemplate::globalSetting()->is_active);

        ReminderTemplate::query()->delete();
        $page->callAction(TestAction::make('edit_request_reminder_settings')->schemaComponent('notification_settings.request_reminder', 'content'), [
            'request_reminder_enabled' => true, 'request_reminder_after_days' => 2, 'request_reminder_interval_days' => 2, 'request_reminder_max' => 3,
        ])->assertHasNoActionErrors();
        $titles = array_column($this->sentNotifications(), 'title');
        $this->assertSame(['Jadwal pengingat pengajuan disimpan'], $titles);
        $this->assertFalse(ReminderTemplate::globalSetting()->is_active);
    }

    public function test_settings_page_lists_the_real_reminder_dates_for_repeating_schedules(): void
    {
        $this->authorizeSettings();
        ReminderTemplate::factory()->create(['schedule_mode' => 'interval', 'start_before_days' => 30, 'interval_days' => 7]);

        Livewire::test(ManageReminderTemplates::class)->assertSee('Pengingat dikirim pada')
            ->assertSeeInOrder(['30 hari sebelum', '23 hari sebelum', '16 hari sebelum', '9 hari sebelum', '2 hari sebelum'])
            ->assertDontSee('Tanggal berakhir')->assertDontSee('Sebelum berakhir');
    }

    public function test_settings_page_shortens_a_very_long_date_list_instead_of_rendering_every_reminder(): void
    {
        $this->authorizeSettings();
        ReminderTemplate::factory()->create(['schedule_mode' => 'interval', 'start_before_days' => 3650, 'interval_days' => 1]);

        $html = Livewire::test(ManageReminderTemplates::class)->assertSee('Pengingat dikirim pada')
            ->assertSeeInOrder(['3650 hari sebelum', '3643 hari sebelum', '…', '2 hari sebelum', '1 hari sebelum', 'Tanggal berakhir', 'Total 3651 pengingat'])
            ->assertDontSee('3000 hari sebelum')->html();

        $this->assertLessThan(200000, strlen($html));
    }

    public function test_settings_page_lists_every_date_up_to_the_shortening_limit(): void
    {
        $this->authorizeSettings();
        ReminderTemplate::factory()->create(['schedule_mode' => 'interval', 'start_before_days' => 13, 'interval_days' => 1]);

        Livewire::test(ManageReminderTemplates::class)->assertSee('8 hari sebelum')->assertSee('Tanggal berakhir')->assertDontSee('…')->assertDontSee('Total 14 pengingat');
    }

    public function test_settings_page_lists_reminder_dates_for_specific_days_including_the_expiry_date(): void
    {
        $this->authorizeSettings();
        ReminderTemplate::factory()->create(['schedule_mode' => 'specific_days', 'scheduled_days' => [1, 30, 0, 7]]);

        Livewire::test(ManageReminderTemplates::class)->assertSee('Pengingat dikirim pada')
            ->assertSeeInOrder(['30 hari sebelum', '7 hari sebelum', '1 hari sebelum', 'Tanggal berakhir']);
    }

    public function test_settings_page_hides_the_date_list_until_a_schedule_exists(): void
    {
        $this->authorizeSettings();
        Livewire::test(ManageReminderTemplates::class)->assertSee('Jadwal belum diatur')->assertDontSee('Pengingat dikirim pada');

        ReminderTemplate::factory()->create(['schedule_mode' => 'inherit']);
        Livewire::test(ManageReminderTemplates::class)->assertSee('Jadwal belum diatur')->assertDontSee('Pengingat dikirim pada');
    }

    public static function schedulePreviews(): array
    {
        return [
            'repeating never reaches the expiry date' => ['interval', 30, 7, [], 'Pengingat dikirim pada: 30 hari sebelum, 23 hari sebelum, 16 hari sebelum, 9 hari sebelum, 2 hari sebelum'],
            'repeating lands on the expiry date' => ['interval', '30', '10', [], 'Pengingat dikirim pada: 30 hari sebelum, 20 hari sebelum, 10 hari sebelum, Tanggal berakhir'],
            'specific days sorted, unique, zero is the expiry date' => ['specific_days', 30, 7, ['7', 30, '1', '0', 7], 'Pengingat dikirim pada: 30 hari sebelum, 7 hari sebelum, 1 hari sebelum, Tanggal berakhir'],
            'specific days ignore half typed or invalid tags' => ['specific_days', 30, 7, ['abc', '', '-3', '12', '99999'], 'Pengingat dikirim pada: 12 hari sebelum'],
            'specific days without any valid tag' => ['specific_days', 30, 7, ['abc'], 'Pengingat dikirim pada: lengkapi isian jadwal untuk melihat pratinjau.'],
            'cleared start field' => ['interval', null, 7, [], 'Pengingat dikirim pada: lengkapi isian jadwal untuk melihat pratinjau.'],
            'zero interval' => ['interval', 30, 0, [], 'Pengingat dikirim pada: lengkapi isian jadwal untuk melihat pratinjau.'],
            'start below the saved minimum' => ['interval', 0, 7, [], 'Pengingat dikirim pada: lengkapi isian jadwal untuk melihat pratinjau.'],
            'unknown mode' => [null, 30, 7, [], 'Pengingat dikirim pada: lengkapi isian jadwal untuk melihat pratinjau.'],
            'long lists are shortened' => ['interval', 30, 1, [], 'Pengingat dikirim pada: 30 hari sebelum, 29 hari sebelum, 28 hari sebelum, 27 hari sebelum, 26 hari sebelum, 25 hari sebelum, 24 hari sebelum, 23 hari sebelum, …, 2 hari sebelum, 1 hari sebelum, Tanggal berakhir (31 pengingat)'],
        ];
    }

    /** @param list<mixed> $scheduledDays */
    #[DataProvider('schedulePreviews')]
    public function test_schedule_preview_matches_what_would_be_saved(?string $mode, mixed $start, mixed $interval, array $scheduledDays, string $expected): void
    {
        $this->assertSame($expected, ReminderTemplateResource::schedulePreview($mode, $start, $interval, $scheduledDays));
    }

    public function test_schedule_form_shows_a_live_preview_that_follows_the_form(): void
    {
        $this->authorizeSettings();
        ReminderTemplate::factory()->create(['schedule_mode' => 'interval', 'start_before_days' => 30, 'interval_days' => 7]);

        Livewire::test(ManageReminderTemplates::class)
            ->mountAction(TestAction::make('edit_schedule')->schemaComponent(self::SCHEDULE, 'content'))
            ->assertMountedActionModalSee('Pengingat dikirim pada: 30 hari sebelum, 23 hari sebelum, 16 hari sebelum, 9 hari sebelum, 2 hari sebelum')
            ->setActionData(['interval_days' => 10])
            ->assertMountedActionModalSee('Pengingat dikirim pada: 30 hari sebelum, 20 hari sebelum, 10 hari sebelum, Tanggal berakhir')
            ->setActionData(['schedule_mode' => 'specific_days', 'scheduled_days' => [14, 3, 0]])
            ->assertMountedActionModalSee('Pengingat dikirim pada: 14 hari sebelum, 3 hari sebelum, Tanggal berakhir');
    }

    public function test_whatsapp_settings_live_next_to_the_reminder_log_in_the_administration_group(): void
    {
        $this->assertSame('Administrasi', ReminderTemplateResource::getNavigationGroup());
        $this->assertSame(ReminderLogResource::getNavigationGroup(), ReminderTemplateResource::getNavigationGroup());
        $this->assertSame(0, ReminderTemplateResource::getNavigationSort());
        $this->assertLessThan(ReminderLogResource::getNavigationSort(), ReminderTemplateResource::getNavigationSort());

        $this->authorizeSettings(['ViewAny:ReminderTemplate', 'ViewAny:ReminderLog', 'View:ReminderLog']);
        $groups = collect(Filament::getNavigation())->keyBy(fn ($group): string => (string) $group->getLabel());
        $this->assertNull($groups->get('Data Master')?->getItems()->first(fn ($item): bool => $item->getLabel() === 'Pengaturan WhatsApp'));
        $this->assertSame(['Pengaturan WhatsApp', 'Log Pengingat'], $groups->get('Administrasi')->getItems()->map(fn ($item): string => $item->getLabel())->values()->all());
    }
}
