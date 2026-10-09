<?php

namespace App\Filament\Resources\ReminderTemplates\Pages;

use App\Filament\Resources\ReminderTemplates\ReminderTemplateResource;
use App\Models\DocumentRequest;
use App\Models\ReminderTemplate;
use App\Services\WhatsAppTemplateTester;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class ManageReminderTemplates extends Page
{
    protected static string $resource = ReminderTemplateResource::class;

    public function getTitle(): string
    {
        return 'Pengaturan WhatsApp';
    }

    public function getSubheading(): string
    {
        return '';
    }

    public function getBreadcrumbs(): array
    {
        return [];
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('delivery_history')->label('Riwayat pengiriman')->color('gray')->outlined()
            ->icon(Heroicon::OutlinedClock)->url(ReminderTemplateResource::getUrl('history'))];
    }

    public function content(Schema $schema): Schema
    {
        $scheduleSection = Section::make('Jadwal pengiriman')->key('reminder_schedule')->compact()
            ->extraAttributes(['class' => 'whatsapp-settings-panel'])
            ->headerActions([$this->editTemplateAction('schedule', 'Jadwal pengingat masa berlaku')])
            ->footerActions($this->quickIntervalActions())
            ->schema([
                Grid::make(['default' => 2])->extraAttributes(['class' => 'whatsapp-settings-schedule'])->schema([
                    TextEntry::make('reminder_status')->label('Status')->badge()
                        ->state(fn (): string => ReminderTemplate::globalSetting()?->is_active ? 'Aktif' : 'Nonaktif')
                        ->color(fn (): string => ReminderTemplate::globalSetting()?->is_active ? 'success' : 'gray'),
                    TextEntry::make('reminder_time')->label('Jam kirim')->weight(FontWeight::SemiBold)->state(ReminderTemplateResource::reminderTimeLabel()),
                    TextEntry::make('reminder_start')->label('Mulai')->weight(FontWeight::SemiBold)
                        ->state(fn (): string => ReminderTemplate::globalSetting()?->start_before_days.' hari sebelum berakhir')
                        ->visible(fn (): bool => ReminderTemplate::globalSetting()?->schedule_mode === 'interval'),
                    TextEntry::make('reminder_interval')->label('Ulangi')->weight(FontWeight::SemiBold)
                        ->state(fn (): string => 'Setiap '.ReminderTemplate::globalSetting()?->interval_days.' hari')
                        ->visible(fn (): bool => ReminderTemplate::globalSetting()?->schedule_mode === 'interval'),
                    TextEntry::make('reminder_days')->label('Sebelum berakhir')->columnSpanFull()->badge()->color('gray')
                        ->state(fn (): array => array_map(fn (int $day): string => $day === 0 ? 'Tanggal berakhir' : $day.' hari', ReminderTemplate::globalSetting()?->reminderOffsets() ?? []))
                        ->visible(fn (): bool => ReminderTemplate::globalSetting()?->schedule_mode === 'specific_days'),
                    TextEntry::make('reminder_overdue')->label('Setelah kedaluwarsa')->columnSpanFull()->badge()->color('gray')
                        ->state(fn (): array => array_map(fn (int $day): string => $day.' hari', ReminderTemplate::globalSetting()?->overdueOffsets() ?? []))
                        ->placeholder('Tidak ada')
                        ->visible(fn (): bool => (bool) ReminderTemplate::globalSetting()),
                    TextEntry::make('reminder_unconfigured')->hiddenLabel()->state('Jadwal belum diatur')->columnSpanFull()
                        ->visible(fn (): bool => ! ReminderTemplate::globalSetting() || ReminderTemplate::globalSetting()?->schedule_mode === 'inherit'),
                ]),
            ]);
        $reminderSection = Section::make('Pesan pengingat')->key('template_reminder')->compact()
            ->extraAttributes(['class' => 'whatsapp-settings-panel'])
            ->headerActions([$this->editTemplateAction('reminder', 'Pengingat masa berlaku')])
            ->footerActions($this->templateTools('reminder'))
            ->schema([TextEntry::make('reminder_message')->hiddenLabel()
                ->state(fn (): string => ReminderTemplate::renderBody(ReminderTemplate::globalSetting()?->body ?? ReminderTemplate::DEFAULT_BODY, ReminderTemplate::EXAMPLE_VALUES))
                ->view('filament.resources.reminder-templates.message-preview')]);
        $overdueSection = Section::make('Pesan setelah kedaluwarsa')->key('template_overdue')->compact()->columnSpanFull()
            ->extraAttributes(['class' => 'whatsapp-settings-panel'])
            ->description('Dikirim sesuai jadwal "Setelah kedaluwarsa" pada pengaturan jadwal.')
            ->headerActions([$this->editTemplateAction('overdue', 'Pengingat setelah kedaluwarsa')])
            ->footerActions($this->templateTools('overdue'))
            ->schema([TextEntry::make('overdue_message')->hiddenLabel()
                ->state(fn (): string => ReminderTemplate::renderBody(ReminderTemplate::globalSetting()?->overdueBody() ?? ReminderTemplate::DEFAULT_OVERDUE_BODY, ReminderTemplate::OVERDUE_EXAMPLE_VALUES))
                ->view('filament.resources.reminder-templates.message-preview')]);
        $requestReminderSection = Section::make('Pengingat pengajuan menggantung')->key('request_reminder')->compact()
            ->extraAttributes(['class' => 'whatsapp-settings-panel'])
            ->description('Mengingatkan pemeriksa atau penyetuju lewat WhatsApp bila pengajuan menunggu terlalu lama.')
            ->headerActions([$this->editRequestReminderSettingsAction(), $this->editTemplateAction('request_reminder', 'Pesan pengingat pengajuan')])
            ->footerActions($this->templateTools('request_reminder'))
            ->schema([
                Grid::make(['default' => 2, 'lg' => 4])->schema([
                    TextEntry::make('request_reminder_status')->label('Status')->badge()
                        ->state(fn (): string => ReminderTemplate::requestReminderSettings()['enabled'] ? 'Aktif' : 'Nonaktif')
                        ->color(fn (): string => ReminderTemplate::requestReminderSettings()['enabled'] ? 'success' : 'gray'),
                    TextEntry::make('request_reminder_after')->label('Mulai setelah')->weight(FontWeight::SemiBold)
                        ->state(fn (): string => ReminderTemplate::requestReminderSettings()['after_days'].' hari'),
                    TextEntry::make('request_reminder_interval')->label('Ulangi setiap')->weight(FontWeight::SemiBold)
                        ->state(fn (): string => ReminderTemplate::requestReminderSettings()['interval_days'].' hari'),
                    TextEntry::make('request_reminder_max')->label('Maksimal')->weight(FontWeight::SemiBold)
                        ->state(fn (): string => ReminderTemplate::requestReminderSettings()['max'].' kali'),
                ]),
                TextEntry::make('request_reminder_message')->hiddenLabel()
                    ->state(fn (): string => ReminderTemplate::renderBody(ReminderTemplate::globalSetting()?->requestReminderBody() ?? ReminderTemplate::DEFAULT_REQUEST_REMINDER_BODY, ReminderTemplate::REQUEST_REMINDER_EXAMPLE_VALUES))
                    ->view('filament.resources.reminder-templates.message-preview'),
            ]);
        $tabs = [];
        $labels = ['submitted' => 'Pengajuan diterima', 'review' => 'Menunggu persetujuan', 'approved' => 'Disetujui', 'revision' => 'Perlu revisi', 'rejected' => 'Ditolak', 'archived' => 'Selesai', 'pic' => 'Notifikasi PIC'];

        foreach (ReminderTemplate::REQUEST_TEMPLATE_LABELS as $event => $label) {
            $section = Section::make($labels[$event])->key('template_'.$event)->compact()
                ->extraAttributes(['class' => 'whatsapp-settings-panel'])
                ->headerActions([$this->editTemplateAction($event, $labels[$event])])
                ->footerActions($this->templateTools($event))
                ->schema([TextEntry::make('message_'.$event)->hiddenLabel()
                    ->state(fn (): string => ReminderTemplate::renderBody(ReminderTemplate::globalSetting()?->requestTemplate($event) ?? ReminderTemplate::DEFAULT_REQUEST_TEMPLATES[$event], [
                        ...ReminderTemplate::REQUEST_EXAMPLE_VALUES, 'status' => DocumentRequest::STATUSES[$event] ?? 'Diperiksa',
                    ]))
                    ->view('filament.resources.reminder-templates.message-preview')]);
            $tabs[] = Tab::make($labels[$event])->key($event)->schema([$section]);
        }

        return $schema->columns(1)->components([
            Section::make('Pengingat masa berlaku')->key('expiry_settings')->contained(false)
                ->extraAttributes(['class' => 'whatsapp-settings-group'])
                ->schema([
                    Grid::make(['default' => 1, 'xl' => 2])->extraAttributes(['class' => 'whatsapp-settings-reminder'])->schema([$scheduleSection, $reminderSection, $overdueSection]),
                ]),
            Section::make('Notifikasi status pengajuan')->key('notification_settings')->contained(false)
                ->extraAttributes(['class' => 'whatsapp-settings-group whatsapp-settings-status'])
                ->schema([
                    $requestReminderSection,
                    Tabs::make('Jenis pesan')->key('template_navigation')->vertical()->contained(false)
                        ->persistTabInQueryString('template')->extraAttributes(['class' => 'whatsapp-settings-tabs'])->tabs($tabs),
                ]),
        ]);
    }

    /** @return array<Action> */
    private function quickIntervalActions(): array
    {
        return array_map(fn (int $days, string $label): Action => Action::make('interval_'.$days)
            ->label($label)->button()
            ->visible(fn (): bool => $this->canEditSettings())
            ->color(fn (): string => ReminderTemplate::globalSetting()?->schedule_mode === 'interval' && ReminderTemplate::globalSetting()?->interval_days === $days ? 'primary' : 'gray')
            ->extraAttributes(fn (): array => [
                'aria-pressed' => ReminderTemplate::globalSetting()?->schedule_mode === 'interval' && ReminderTemplate::globalSetting()?->interval_days === $days ? 'true' : 'false',
                'style' => 'min-height: 44px',
            ])
            ->action(function () use ($days): void {
                abort_unless($this->canEditSettings(), 403);
                DB::transaction(function () use ($days): void {
                    $setting = ReminderTemplate::where('global_key', 'global')->lockForUpdate()->first();
                    $setting ??= new ReminderTemplate([
                        'name' => 'Pengingat Semua Dokumen', 'is_active' => false, 'body' => ReminderTemplate::DEFAULT_BODY,
                        'request_templates' => ReminderTemplate::DEFAULT_REQUEST_TEMPLATES,
                    ]);
                    $setting->fill(['schedule_mode' => 'interval', 'interval_days' => $days]);
                    $setting->save();
                });
                Notification::make()->title('Jadwal pengingat disimpan')->success()->send();
            }), [1, 3, 7], ['Setiap hari', '3 hari sekali', '7 hari sekali']);
    }

    private function canEditSettings(): bool
    {
        $setting = ReminderTemplate::globalSetting();

        return $setting ? ReminderTemplateResource::canEdit($setting) : ReminderTemplateResource::canCreate();
    }

    private function templateBody(string $event): string
    {
        $setting = ReminderTemplate::globalSetting();

        return match ($event) {
            'reminder' => $setting?->body ?? ReminderTemplate::DEFAULT_BODY,
            'overdue' => $setting?->overdueBody() ?? ReminderTemplate::DEFAULT_OVERDUE_BODY,
            'request_reminder' => $setting?->requestReminderBody() ?? ReminderTemplate::DEFAULT_REQUEST_REMINDER_BODY,
            default => $setting?->requestTemplate($event) ?? ReminderTemplate::DEFAULT_REQUEST_TEMPLATES[$event],
        };
    }

    private function defaultBody(string $event): string
    {
        return match ($event) {
            'reminder' => ReminderTemplate::DEFAULT_BODY,
            'overdue' => ReminderTemplate::DEFAULT_OVERDUE_BODY,
            'request_reminder' => ReminderTemplate::DEFAULT_REQUEST_REMINDER_BODY,
            default => ReminderTemplate::DEFAULT_REQUEST_TEMPLATES[$event],
        };
    }

    /** @return array<Action> */
    private function templateTools(string $event): array
    {
        return [
            Action::make('test_'.$event)->label('Kirim pesan uji')->link()->color('gray')->icon(Heroicon::OutlinedPaperAirplane)
                ->visible(fn (): bool => $this->canEditSettings())->modalHeading('Kirim pesan uji')
                ->modalDescription('Mengirim template tersimpan dengan data contoh. Pesan ditandai [PESAN UJI].')
                ->modalSubmitActionLabel('Kirim pesan uji')->modalWidth('lg')
                ->schema([
                    TextInput::make('phone')->label('Nomor WhatsApp tujuan')->tel()->required()->maxLength(30)
                        ->default(fn (): ?string => auth()->user()->phone)->placeholder('08xxxxxxxxxx'),
                    TextEntry::make('test_preview')->label('Pesan yang dikirim')->state(fn (): string => "[PESAN UJI]\n".ReminderTemplate::renderBody($this->templateBody($event),
                        match ($event) {
                            'reminder' => ReminderTemplate::EXAMPLE_VALUES,
                            'overdue' => ReminderTemplate::OVERDUE_EXAMPLE_VALUES,
                            'request_reminder' => ReminderTemplate::REQUEST_REMINDER_EXAMPLE_VALUES,
                            default => [...ReminderTemplate::REQUEST_EXAMPLE_VALUES, 'status' => DocumentRequest::STATUSES[$event] ?? 'Diperiksa'],
                        }))
                        ->view('filament.resources.reminder-templates.message-preview'),
                ])->action(function (array $data) use ($event): void {
                    abort_unless($this->canEditSettings(), 403);
                    $result = app(WhatsAppTemplateTester::class)->send(auth()->user(), $event, $data['phone']);
                    if ($result['status'] === 'accepted') {
                        Notification::make()->title('Pesan uji diterima WagHub')->body('Status ini belum memastikan pesan diterima di WhatsApp tujuan.')->success()->send();
                    } else {
                        Notification::make()->title('Pesan uji gagal')->body($result['error'])->danger()->send();
                    }
                }),
            Action::make('reset_'.$event)->label('Kembalikan bawaan')->link()->color('gray')->icon(Heroicon::OutlinedArrowUturnLeft)
                ->visible(fn (): bool => $this->canEditSettings() && $this->templateBody($event) !== $this->defaultBody($event))
                ->requiresConfirmation()->modalHeading('Kembalikan template bawaan?')
                ->modalDescription('Isi pesan ini akan diganti dengan template bawaan. Jadwal dan template lainnya tetap.')
                ->modalSubmitActionLabel('Kembalikan bawaan')
                ->action(function () use ($event): void {
                    abort_unless($this->canEditSettings(), 403);
                    DB::transaction(function () use ($event): void {
                        $setting = ReminderTemplate::where('global_key', 'global')->lockForUpdate()->first();
                        if ($setting === null) {
                            return;
                        }
                        if ($event === 'reminder') {
                            $setting->body = ReminderTemplate::DEFAULT_BODY;
                        } elseif ($event === 'overdue') {
                            $setting->overdue_body = null;
                        } elseif ($event === 'request_reminder') {
                            $setting->request_reminder_body = null;
                        } else {
                            $setting->request_templates = [...($setting->request_templates ?? []), $event => ReminderTemplate::DEFAULT_REQUEST_TEMPLATES[$event]];
                        }
                        $setting->save();
                    });
                    Notification::make()->title('Template bawaan dipulihkan')->success()->send();
                }),
        ];
    }

    private function editRequestReminderSettingsAction(): Action
    {
        return Action::make('edit_request_reminder_settings')->label('Atur jadwal')->outlined()->color('gray')
            ->modalHeading('Jadwal pengingat pengajuan')->modalSubmitActionLabel('Simpan')->modalCancelActionLabel('Batal')->modalWidth('lg')
            ->visible(fn (): bool => $this->canEditSettings())
            ->fillForm(fn (): array => [
                'request_reminder_enabled' => ReminderTemplate::requestReminderSettings()['enabled'],
                'request_reminder_after_days' => ReminderTemplate::requestReminderSettings()['after_days'],
                'request_reminder_interval_days' => ReminderTemplate::requestReminderSettings()['interval_days'],
                'request_reminder_max' => ReminderTemplate::requestReminderSettings()['max'],
            ])
            ->schema(ReminderTemplateResource::requestReminderSettingsFields())
            ->action(function (array $data): void {
                abort_unless($this->canEditSettings(), 403);
                DB::transaction(function () use ($data): void {
                    $setting = ReminderTemplate::where('global_key', 'global')->lockForUpdate()->first();
                    $setting ??= new ReminderTemplate([
                        'name' => 'Pengingat Semua Dokumen', 'is_active' => false, 'body' => ReminderTemplate::DEFAULT_BODY,
                        'schedule_mode' => 'interval', 'request_templates' => ReminderTemplate::DEFAULT_REQUEST_TEMPLATES,
                    ]);
                    $setting->fill(Arr::only($data, ['request_reminder_enabled', 'request_reminder_after_days', 'request_reminder_interval_days', 'request_reminder_max']));
                    $setting->save();
                });
                Notification::make()->title('Jadwal pengingat pengajuan disimpan')->success()->send();
            });
    }

    private function editTemplateAction(string $event, string $label): Action
    {
        return Action::make('edit_'.$event)->label($event === 'schedule' ? 'Atur jadwal' : 'Ubah template')->outlined()->color('gray')->modalHeading($label)->modalSubmitActionLabel('Simpan')->modalCancelActionLabel('Batal')->modalWidth('xl')
            ->visible(fn (): bool => $this->canEditSettings())
            ->fillForm(function () use ($event): array {
                $setting = ReminderTemplate::globalSetting();

                if ($event === 'schedule') {
                    return $setting?->attributesToArray() ?? [
                        'name' => 'Pengingat Semua Dokumen', 'is_active' => true, 'body' => ReminderTemplate::DEFAULT_BODY,
                        'schedule_mode' => 'interval', 'start_before_days' => 30, 'interval_days' => 7, 'scheduled_days' => [30, 7, 1],
                    ];
                }

                return match ($event) {
                    'reminder' => ['body' => $setting?->body ?? ReminderTemplate::DEFAULT_BODY],
                    'overdue' => ['overdue_body' => $setting?->overdueBody() ?? ReminderTemplate::DEFAULT_OVERDUE_BODY],
                    'request_reminder' => ['request_reminder_body' => $setting?->requestReminderBody() ?? ReminderTemplate::DEFAULT_REQUEST_REMINDER_BODY],
                    default => ['request_templates' => [$event => $setting?->requestTemplate($event) ?? ReminderTemplate::DEFAULT_REQUEST_TEMPLATES[$event]]],
                };
            })
            ->schema(match ($event) {
                'schedule' => ReminderTemplateResource::reminderScheduleFields(),
                'reminder' => ReminderTemplateResource::reminderFields(),
                'overdue' => ReminderTemplateResource::overdueFields(),
                'request_reminder' => ReminderTemplateResource::requestReminderFields(),
                default => ReminderTemplateResource::requestTemplateFields($event),
            })
            ->action(function (array $data) use ($event): void {
                abort_unless($this->canEditSettings(), 403);
                DB::transaction(function () use ($event, $data): void {
                    $setting = ReminderTemplate::where('global_key', 'global')->lockForUpdate()->first();
                    $setting ??= new ReminderTemplate([
                        'name' => 'Pengingat Semua Dokumen', 'is_active' => false, 'body' => ReminderTemplate::DEFAULT_BODY,
                        'schedule_mode' => 'interval', 'request_templates' => ReminderTemplate::DEFAULT_REQUEST_TEMPLATES,
                    ]);
                    if ($event === 'schedule') {
                        $setting->fill(Arr::only($data, ['is_active', 'schedule_mode', 'start_before_days', 'interval_days', 'scheduled_days', 'overdue_days']));
                    } elseif ($event === 'reminder') {
                        $setting->body = $data['body'];
                    } elseif ($event === 'overdue') {
                        $setting->overdue_body = $data['overdue_body'] === ReminderTemplate::DEFAULT_OVERDUE_BODY ? null : $data['overdue_body'];
                    } elseif ($event === 'request_reminder') {
                        $setting->request_reminder_body = $data['request_reminder_body'] === ReminderTemplate::DEFAULT_REQUEST_REMINDER_BODY ? null : $data['request_reminder_body'];
                    } else {
                        $setting->request_templates = [...($setting->request_templates ?? []), $event => $data['request_templates'][$event]];
                    }
                    $setting->save();
                });
                Notification::make()->title($event === 'schedule' ? 'Jadwal pengingat disimpan' : 'Template WhatsApp disimpan')->success()->send();
            });
    }
}
