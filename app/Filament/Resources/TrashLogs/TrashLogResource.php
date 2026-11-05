<?php

namespace App\Filament\Resources\TrashLogs;

use App\Filament\Resources\TrashLogs\Pages\ListTrashLogs;
use App\Filament\Resources\TrashLogs\Tables\TrashLogsTable;
use App\Models\TrashLog;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Riwayat hapus, pulihkan, dan hapus permanen: halaman baca-saja untuk super admin (spec §3.5). Akses dikunci di TrashLogPolicy.
 */
class TrashLogResource extends Resource
{
    protected static ?string $model = TrashLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTrash;

    protected static ?string $modelLabel = 'Riwayat Penghapusan';

    protected static ?string $pluralModelLabel = 'Riwayat Penghapusan';

    protected static string|\UnitEnum|null $navigationGroup = 'Administrasi';

    protected static ?int $navigationSort = 2;

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

    public static function table(Table $table): Table
    {
        return TrashLogsTable::configure($table);
    }

    public static function getPages(): array
    {
        return ['index' => ListTrashLogs::route('/')];
    }
}
