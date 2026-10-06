<?php

namespace App\Filament\Resources\ReminderLogs;

use App\Filament\Resources\ReminderLogs\Pages\ListReminderLogs;
use App\Filament\Resources\ReminderLogs\Pages\ViewReminderLog;
use App\Filament\Resources\ReminderLogs\Schemas\ReminderLogInfolist;
use App\Filament\Resources\ReminderLogs\Tables\ReminderLogsTable;
use App\Models\ReminderLog;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ReminderLogResource extends Resource
{
    protected static ?string $model = ReminderLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBell;

    protected static ?string $modelLabel = 'Log Pengingat';

    protected static ?string $pluralModelLabel = 'Log Pengingat';

    protected static string|\UnitEnum|null $navigationGroup = 'Administrasi';

    protected static ?int $navigationSort = 1;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('documentVersion.document.company');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function canForceDelete(Model $record): bool
    {
        return false;
    }

    public static function canForceDeleteAny(): bool
    {
        return false;
    }

    public static function canRestore(Model $record): bool
    {
        return false;
    }

    public static function canRestoreAny(): bool
    {
        return false;
    }

    public static function canReplicate(Model $record): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return ReminderLogInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ReminderLogsTable::configure($table);
    }

    public static function getPages(): array
    {
        return ['index' => ListReminderLogs::route('/'), 'view' => ViewReminderLog::route('/{record}')];
    }
}
