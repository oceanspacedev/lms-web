<?php

namespace App\Filament\Resources\Companies\Tables;

use App\Filament\Concerns\TrashActions;
use App\Models\Company;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class CompaniesTable
{
    public static function configure(Table $table): Table
    {
        return $table->striped()->paginated([10, 25, 50])
            ->columns([
                TextColumn::make('name')->label('Badan Usaha')->searchable()->sortable()->weight('medium')->limit(40)->wrap(),
                TextColumn::make('legal_form')->label('Bentuk Badan Usaha')->searchable()->sortable()->visibleFrom('md'),
                TextColumn::make('npwp')->label('NPWP')->searchable()->placeholder('Belum diisi')->visibleFrom('md'),
                TextColumn::make('is_active')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (bool $state, Company $record): string => $record->trashed() ? 'Dihapus' : ($state ? 'Aktif' : 'Nonaktif'))
                    ->color(fn (bool $state, Company $record): string => $state && ! $record->trashed() ? 'success' : 'gray')
                    ->visibleFrom(TrashActions::statusVisibleFrom())
                    ->sortable(),
                TrashActions::deletedColumn(),
                TextColumn::make('updated_at')->label('Terakhir Diperbarui')->dateTime('d/m/Y H:i')->timezone(config('lms.reminder_timezone'))->sortable()->toggleable(isToggledHiddenByDefault: true)->visibleFrom('md'),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Status')->placeholder('Semua status')->trueLabel('Aktif')->falseLabel('Nonaktif'),
                TrashActions::filter(),
            ])
            ->recordActions([
                EditAction::make()->label('Ubah')->iconButton()->tooltip('Ubah')->hidden(fn (Company $record): bool => $record->trashed()),
                TrashActions::viewTrashed('badan usaha'),
                TrashActions::delete('badan usaha')->iconButton(),
                TrashActions::restore('badan usaha')->iconButton(),
                TrashActions::forceDelete('badan usaha')->iconButton(),
            ])
            ->toolbarActions(TrashActions::bulkActions('badan usaha'))
            ->defaultSort('name')
            ->emptyStateHeading(TrashActions::emptyStateHeading('Belum ada badan usaha'))
            ->emptyStateDescription(TrashActions::emptyStateDescription('badan usaha', null));
    }
}
