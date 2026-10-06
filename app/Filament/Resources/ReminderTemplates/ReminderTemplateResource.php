<?php

namespace App\Filament\Resources\ReminderTemplates;

use App\Filament\Resources\ReminderTemplates\Pages\ManageReminderTemplates;
use App\Models\ReminderTemplate;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ReminderTemplateResource extends Resource
{
    protected static ?string $model = ReminderTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static ?string $modelLabel = 'Pengaturan Pengingat';

    protected static ?string $pluralModelLabel = 'Pengaturan Pengingat';

    protected static string|\UnitEnum|null $navigationGroup = 'Data Master';

    protected static ?int $navigationSort = 7;

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
        return $schema->columns(1)->components([
            Hidden::make('name')->default('Pengingat Semua Dokumen'),
            Toggle::make('is_active')->label('Aktif')->default(true),
            Select::make('schedule_mode')->label('Jadwal')->options(fn (?ReminderTemplate $record): array => [
                'interval' => 'Berulang',
                'specific_days' => 'Hari tertentu',
            ])->default('interval')->required()->live()->native(false),
            Grid::make(2)->schema([
                TextInput::make('start_before_days')->label('Mulai sebelum berakhir')->suffix('hari')->numeric()->integer()->minValue(1)->maxValue(3650)->default(30)->required()
                    ->visible(fn (Get $get): bool => $get('schedule_mode') === 'interval')->live(onBlur: true),
                TextInput::make('interval_days')->label('Ulangi setiap')->suffix('hari')->numeric()->integer()->minValue(1)->maxValue(3650)->default(7)->required()
                    ->visible(fn (Get $get): bool => $get('schedule_mode') === 'interval')->live(onBlur: true),
            ])->visible(fn (Get $get): bool => $get('schedule_mode') === 'interval'),
            TagsInput::make('scheduled_days')->label('Hari sebelum berakhir')->default([30, 7, 1])->required()->splitKeys([','])->live()
                ->nestedRecursiveRules(['integer', 'min:0', 'max:3650', 'distinct'])
                ->visible(fn (Get $get): bool => $get('schedule_mode') === 'specific_days')
                ->helperText('0 = tanggal berakhir.'),
            Hidden::make('show_variables')->default(false)->dehydrated(false),
            Select::make('message_variable')->label('Sisipkan variabel')->placeholder('Pilih untuk menambahkan')->live()->dehydrated(false)
                ->options(fn (): array => collect(ReminderTemplate::VARIABLE_LABELS)->map(fn (string $label, string $name): string => $label.' — '.ReminderTemplate::EXAMPLE_VALUES[$name])->all())
                ->visible(fn (Get $get): bool => (bool) $get('show_variables'))
                ->afterStateUpdated(function (?string $state, Get $get, Set $set): void {
                    if (isset(ReminderTemplate::VARIABLE_LABELS[$state ?? ''])) {
                        $set('body', rtrim((string) $get('body')).' {'.$state.'}');
                        $set('message_variable', null);
                        $set('show_variables', false);
                    }
                }),
            Textarea::make('body')->label('Pesan')->required()->maxLength(4000)->rows(4)->live(onBlur: true)->columnSpanFull()
                ->default(ReminderTemplate::DEFAULT_BODY)
                ->hintAction(Action::make('show_variables')->label('Sisipkan variabel')->link()->action(fn (Get $get, Set $set) => $set('show_variables', ! $get('show_variables'))))
                ->rules([fn (): \Closure => function (string $attribute, mixed $value, \Closure $fail): void {
                    $unknown = ReminderTemplate::unknownVariables((string) $value);
                    if ($unknown !== []) {
                        $fail('Variabel tidak dikenal: '.implode(', ', $unknown));
                    }
                }]),
            Section::make('Pratinjau pesan')->collapsed()->compact()->schema([
                TextEntry::make('preview')->hiddenLabel()->columnSpanFull()
                    ->state(fn (Get $get): string => ReminderTemplate::renderBody((string) $get('body'), ReminderTemplate::EXAMPLE_VALUES))->extraAttributes(['style' => 'white-space: pre-line']),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('Nama')->searchable()->sortable(),
            TextColumn::make('schedule')->label('Jadwal')->state(fn (ReminderTemplate $record): string => $record->scheduleDescription())->wrap(),
            TextColumn::make('is_active')->label('Status')->badge()->formatStateUsing(fn (bool $state): string => $state ? 'Aktif' : 'Nonaktif')->color(fn (bool $state): string => $state ? 'success' : 'gray'),
        ])->filters([TernaryFilter::make('is_active')->label('Status')->placeholder('Semua')->trueLabel('Aktif')->falseLabel('Nonaktif')])
            ->recordActions([EditAction::make()->label('Ubah')->modalWidth('2xl')])->defaultSort('name')
            ->emptyStateHeading('Belum ada data')->emptyStateDescription('Tambahkan data melalui tombol Tambah.');
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
        return ['index' => ManageReminderTemplates::route('/')];
    }
}
