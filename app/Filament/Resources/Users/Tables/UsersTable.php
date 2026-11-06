<?php

namespace App\Filament\Resources\Users\Tables;

use App\Filament\Concerns\TrashActions;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
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
                TextColumn::make('email')->label('Email')->searchable()->sortable()->visibleFrom('md'),
                TextColumn::make('phone')->label('WhatsApp')->searchable()->placeholder('Belum diisi')->visibleFrom('md'),
                TextColumn::make('roles.name')->label('Peran')->badge()->placeholder('Belum ada peran')->visibleFrom('md'),
                TextColumn::make('status')->label('Status')->badge()
                    ->state(fn (User $record): string => match (true) {
                        $record->trashed() => 'Dihapus',
                        $record->isActive() => 'Aktif',
                        default => 'Nonaktif',
                    })
                    ->color(fn (User $record): string => $record->isActive() && ! $record->trashed() ? 'success' : 'gray')
                    ->visibleFrom(TrashActions::statusVisibleFrom())
                    ->description(fn (User $record): ?string => $record->isActive() || $record->trashed() ? null
                        : 'Dinonaktifkan oleh '.($record->deactivatedBy?->name ?? '-').' pada '.$record->deactivated_at->timezone(config('lms.reminder_timezone'))->format('d/m/Y H:i')),
                TrashActions::deletedColumn(),
            ])
            ->filters([
                // User di tempat sampah selalu nonaktif. "Aktif" (bawaan) tetap memuatnya hanya pada "Hanya data dihapus", supaya isi
                // tempat sampah langsung tampil; pada "Dengan data dihapus" user di tempat sampah tidak ikut disebut aktif.
                TernaryFilter::make('status')->label('Status')->placeholder('Semua status')->trueLabel('Aktif')->falseLabel('Nonaktif')->default(true)
                    ->query(fn (Builder $query, array $data, HasTable $livewire): Builder => match (true) {
                        blank($data['value'] ?? null) => $query,
                        ! $data['value'] => $query->whereNotNull('deactivated_at'),
                        TrashActions::isShowingOnlyTrashed($livewire) => $query->where(fn (Builder $query): Builder => $query->whereNull('deactivated_at')->orWhereNotNull('users.deleted_at')),
                        default => $query->whereNull('deactivated_at')->whereNull('users.deleted_at'),
                    }),
                SelectFilter::make('roles')->label('Peran')->relationship('roles', 'name', modifyQueryUsing: fn (Builder $query): Builder => $query->where('guard_name', 'web'))->searchable()->preload(),
                TrashActions::filter(),
            ])
            ->recordActions([
                EditAction::make()->label('Ubah')->iconButton()->tooltip('Ubah')->hidden(fn (User $record): bool => $record->trashed()),
                TrashActions::viewTrashed('user'),
                UserResource::deactivateAction()->iconButton()->tooltip('Nonaktifkan'),
                UserResource::reactivateAction()->iconButton()->tooltip('Aktifkan kembali'),
                TrashActions::delete('user')->iconButton(),
                TrashActions::restore('user')->iconButton(),
                TrashActions::forceDelete('user')->iconButton(),
            ])
            ->toolbarActions(TrashActions::bulkActions('user'))
            ->defaultSort('name')
            ->emptyStateHeading(TrashActions::emptyStateHeading('Belum ada user'))
            ->emptyStateDescription(TrashActions::emptyStateDescription('user', 'Tambahkan user dan pilih peran sesuai tanggung jawabnya.'));
    }
}
