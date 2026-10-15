<?php

namespace App\Filament\Resources\Users\Tables;

use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table->striped()->paginated([10, 25, 50])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('deactivatedBy'))
            ->columns([
                TextColumn::make('name')->label('Nama')->searchable()->sortable()->weight('medium')->limit(35)->wrap(),
                TextColumn::make('email')->label('Email')->searchable()->sortable(),
                TextColumn::make('phone')->label('WhatsApp')->searchable()->placeholder('Belum diisi'),
                TextColumn::make('roles.name')->label('Peran')->badge()->placeholder('Belum ada peran'),
                TextColumn::make('status')->label('Status')->badge()
                    ->state(fn (User $record): string => $record->isActive() ? 'Aktif' : 'Nonaktif')
                    ->color(fn (User $record): string => $record->isActive() ? 'success' : 'gray')
                    ->description(fn (User $record): ?string => $record->isActive() ? null
                        : 'Dinonaktifkan oleh '.($record->deactivatedBy?->name ?? '-').' pada '.$record->deactivated_at->timezone(config('lms.reminder_timezone'))->format('d/m/Y H:i')),
            ])
            ->filters([
                TernaryFilter::make('status')->label('Status')->placeholder('Semua status')->trueLabel('Aktif')->falseLabel('Nonaktif')->default(true)
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNull('deactivated_at'),
                        false: fn (Builder $query): Builder => $query->whereNotNull('deactivated_at'),
                        blank: fn (Builder $query): Builder => $query,
                    ),
                SelectFilter::make('roles')->label('Peran')->relationship('roles', 'name', modifyQueryUsing: fn (Builder $query): Builder => $query->where('guard_name', 'web'))->searchable()->preload(),
            ])
            ->recordActions([
                EditAction::make()->label('Ubah')->iconButton()->tooltip('Ubah'),
                UserResource::deactivateAction()->iconButton()->tooltip('Nonaktifkan'),
                UserResource::reactivateAction()->iconButton()->tooltip('Aktifkan kembali'),
            ])
            ->defaultSort('name')
            ->emptyStateHeading('Belum ada user')
            ->emptyStateDescription('Tambahkan user dan pilih peran sesuai tanggung jawabnya.');
    }
}
