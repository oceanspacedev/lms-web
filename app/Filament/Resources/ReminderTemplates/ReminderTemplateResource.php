<?php

namespace App\Filament\Resources\ReminderTemplates;

use App\Filament\Resources\ReminderTemplates\Pages\ManageReminderTemplates;
use App\Filament\Resources\ReminderTemplates\Pages\WhatsAppDeliveryHistory;
use App\Models\DocumentRequest;
use App\Models\ReminderTemplate;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ReminderTemplateResource extends Resource
{
    /** Daftar tanggal pengingat yang lebih panjang dari ini diringkas. */
    public const MAX_LISTED_REMINDERS = 14;

    /**
     * Input tag jumlah hari hanya menerima angka.
     *
     * @var array<string, string>
     */
    private const DAY_TAG_INPUT_ATTRIBUTES = [
        'inputmode' => 'numeric',
        'maxlength' => '4',
        'x-on:input' => "\$el.value = \$el.value.replace(/\\D/g, ''); newTag = \$el.value",
    ];

    protected static ?string $model = ReminderTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static ?string $modelLabel = 'Pengaturan WhatsApp';

    protected static ?string $pluralModelLabel = 'Pengaturan WhatsApp';

    protected static string|\UnitEnum|null $navigationGroup = 'Administrasi';

    /** Tepat di atas Log Pengingat (sort 1); Horizon dan Log Laravel di panel memakai 2 dan 3. */
    protected static ?int $navigationSort = 0;

    protected static ?string $recordTitleAttribute = 'name';

    protected static bool $isGloballySearchable = false;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('global_key', 'global');
    }

    public static function canCreate(): bool
    {
        return parent::canCreate() && ReminderTemplate::globalSetting() === null;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components(self::reminderFields());
    }

    /** @return array<Component> */
    public static function reminderFields(): array
    {
        return self::messageFields('body', ReminderTemplate::DEFAULT_BODY, ReminderTemplate::EXAMPLE_VALUES, ReminderTemplate::VARIABLE_LABELS);
    }

    /** @return array<Component> */
    public static function requestReminderFields(): array
    {
        return self::messageFields('request_reminder_body', ReminderTemplate::DEFAULT_REQUEST_REMINDER_BODY, ReminderTemplate::REQUEST_REMINDER_EXAMPLE_VALUES, ReminderTemplate::REQUEST_REMINDER_VARIABLE_LABELS);
    }

    /** @return array<Component> */
    public static function requestReminderSettingsFields(): array
    {
        return [
            Toggle::make('request_reminder_enabled')->label('Kirim pengingat pengajuan')->default(true),
            Grid::make(3)->schema([
                TextInput::make('request_reminder_after_days')->label('Mulai setelah')->suffix('hari')->numeric()->integer()->minValue(1)->maxValue(365)->default(2)->required(),
                TextInput::make('request_reminder_interval_days')->label('Ulangi setiap')->suffix('hari')->numeric()->integer()->minValue(1)->maxValue(365)->default(2)->required(),
                TextInput::make('request_reminder_max')->label('Maksimal')->suffix('kali')->numeric()->integer()->minValue(1)->maxValue(10)->default(3)->required(),
            ]),
            TextEntry::make('request_reminder_help')->hiddenLabel()
                ->state('Pemeriksa diingatkan saat pengajuan menunggu di tahap Diperiksa, penyetuju saat menunggu persetujuan. Pengingat dikirim sekitar pukul '.config('lms.reminder_time').' dan berhenti begitu pengajuan berpindah tahap.'),
        ];
    }

    /** @return array<Component> */
    public static function overdueFields(): array
    {
        return self::messageFields('overdue_body', ReminderTemplate::DEFAULT_OVERDUE_BODY, ReminderTemplate::OVERDUE_EXAMPLE_VALUES, ReminderTemplate::OVERDUE_VARIABLE_LABELS);
    }

    /** @return array<Component> */
    public static function reminderScheduleFields(): array
    {
        return [
            Toggle::make('is_active')->label('Kirim pengingat otomatis')->default(true),
            Select::make('schedule_mode')->label('Jadwal')->options(fn (?ReminderTemplate $record): array => [
                'interval' => 'Ulangi setiap beberapa hari',
                'specific_days' => 'Pilih hari pengiriman sendiri',
            ])->default('interval')->required()->live()->native(false),
            Grid::make(2)->schema([
                TextInput::make('start_before_days')->label('Mulai berapa hari sebelum berakhir?')->suffix('hari')->numeric()->integer()->minValue(1)->maxValue(3650)->default(30)->required()
                    ->visible(fn (Get $get): bool => $get('schedule_mode') === 'interval')->live(onBlur: true),
                TextInput::make('interval_days')->label('Ulangi setiap')->suffix('hari')->numeric()->integer()->minValue(1)->maxValue(3650)->default(7)->required()
                    ->visible(fn (Get $get): bool => $get('schedule_mode') === 'interval')->live(onBlur: true),
            ])->visible(fn (Get $get): bool => $get('schedule_mode') === 'interval'),
            TagsInput::make('scheduled_days')->label('Hari sebelum berakhir')->default([30, 7, 1])->required()->splitKeys([','])->live()->extraInputAttributes(self::DAY_TAG_INPUT_ATTRIBUTES)
                ->nestedRecursiveRules(['integer', 'min:0', 'max:3650', 'distinct'])
                ->visible(fn (Get $get): bool => $get('schedule_mode') === 'specific_days')
                ->helperText('Angka = hari sebelum berakhir. 0 = tanggal berakhir.'),
            TagsInput::make('overdue_days')->label('Setelah kedaluwarsa (opsional)')->default([])->splitKeys([','])->extraInputAttributes(self::DAY_TAG_INPUT_ATTRIBUTES)
                ->nestedRecursiveRules(['integer', 'min:1', 'max:3650', 'distinct'])
                ->helperText('Angka = hari setelah tanggal berakhir, misalnya 1 dan 7. Kosongkan bila tidak perlu.'),
            TextEntry::make('schedule_preview')->hiddenLabel()->icon(Heroicon::OutlinedCalendarDays)
                ->state(fn (Get $get): string => self::schedulePreview($get('schedule_mode'), $get('start_before_days'), $get('interval_days'), $get('scheduled_days'))),
            TextEntry::make('schedule_time')->label('Waktu pengiriman')->state(self::reminderTimeLabel()),
        ];
    }

    /**
     * Pratinjau jadwal dari isian form yang sedang diubah, memakai perhitungan yang sama dengan jadwal tersimpan.
     * Mode berulang dari H-30 setiap 7 hari berhenti di H-2 dan tidak pernah sampai tanggal berakhir.
     */
    public static function schedulePreview(mixed $scheduleMode, mixed $startBeforeDays, mixed $intervalDays, mixed $scheduledDays): string
    {
        $isDays = fn (mixed $value): bool => (is_int($value) || is_string($value)) && ctype_digit((string) $value) && (int) $value <= 3650;
        $offsets = match (true) {
            $scheduleMode === 'interval' && $isDays($startBeforeDays) && $isDays($intervalDays) && (int) $startBeforeDays >= 1 && (int) $intervalDays >= 1 => (new ReminderTemplate([
                'schedule_mode' => 'interval', 'start_before_days' => (int) $startBeforeDays, 'interval_days' => (int) $intervalDays,
            ]))->reminderOffsets(),
            $scheduleMode === 'specific_days' && is_array($scheduledDays) => (new ReminderTemplate([
                'schedule_mode' => 'specific_days', 'scheduled_days' => array_values(array_filter($scheduledDays, $isDays)),
            ]))->reminderOffsets(),
            default => [],
        };
        if ($offsets === null || $offsets === []) {
            return 'Pengingat dikirim pada: lengkapi isian jadwal untuk melihat pratinjau.';
        }
        $labels = array_map(self::reminderDayLabel(...), $offsets);
        $total = count($labels);

        return 'Pengingat dikirim pada: '.implode(', ', self::shortenReminderLabels($labels))
            .($total > self::MAX_LISTED_REMINDERS ? " ({$total} pengingat)" : '');
    }

    /** Label satu hari pengingat, sama di pratinjau form dan halaman pengaturan: "30 hari sebelum" atau "Tanggal berakhir". */
    public static function reminderDayLabel(int $day): string
    {
        return $day === 0 ? 'Tanggal berakhir' : $day.' hari sebelum';
    }

    /**
     * Daftar tanggal pengingat yang panjang diringkas: delapan pertama, tanda "…", lalu tiga terakhir. Dipakai pratinjau form dan halaman pengaturan.
     *
     * @param  list<string>  $labels
     * @return list<string>
     */
    public static function shortenReminderLabels(array $labels): array
    {
        return count($labels) > self::MAX_LISTED_REMINDERS ? [...array_slice($labels, 0, 8), '…', ...array_slice($labels, -3)] : $labels;
    }

    public static function reminderTimeLabel(): string
    {
        $timezone = config('lms.reminder_timezone');

        return config('lms.reminder_time').' '.($timezone === 'Asia/Jakarta' ? 'WIB' : $timezone);
    }

    /** @return array<Component> */
    public static function requestTemplateFields(string $event): array
    {
        return self::messageFields('request_templates.'.$event, ReminderTemplate::DEFAULT_REQUEST_TEMPLATES[$event], [
            ...ReminderTemplate::REQUEST_EXAMPLE_VALUES,
            'status' => DocumentRequest::STATUSES[$event] ?? 'Diperiksa',
        ], ReminderTemplate::REQUEST_VARIABLE_LABELS);
    }

    /**
     * @param  array<string, string>  $examples
     * @param  array<string, string>  $labels
     * @return array<Component>
     */
    private static function messageFields(string $field, string $default, array $examples, array $labels): array
    {
        return [
            Textarea::make($field)->label('Isi pesan')->required()->maxLength(4000)->rows(7)->live(debounce: 500)
                ->default($default)->formatStateUsing(fn (?string $state): string => $state ?? $default)
                ->view('filament.resources.reminder-templates.variable-message-editor', ['variableLabels' => $labels])
                ->rules([fn (): \Closure => function (string $attribute, mixed $value, \Closure $fail) use ($examples): void {
                    $unknown = ReminderTemplate::unknownVariables((string) $value, $examples);
                    if ($unknown !== []) {
                        $fail('Data otomatis tidak dikenali: '.implode(', ', $unknown).'. Gunakan pilihan data di bawah pesan.');
                    }
                }]),
            Section::make('Lihat contoh pesan')->collapsed()->compact()->schema([
                TextEntry::make('message_example')->hiddenLabel()
                    ->state(fn (Get $get): string => ReminderTemplate::renderBody((string) $get($field), $examples))
                    ->view('filament.resources.reminder-templates.message-preview'),
            ]),
        ];
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ManageReminderTemplates::route('/'), 'history' => WhatsAppDeliveryHistory::route('/history')];
    }
}
