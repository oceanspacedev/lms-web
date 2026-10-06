<?php

namespace App\Filament\Resources\Users\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('Nama')->searchable()->sortable(),
            TextColumn::make('email')->label('Email')->searchable()->sortable(),
            TextColumn::make('phone')->label('Nomor HP / WhatsApp')->searchable()->placeholder('Belum diisi'),
            TextColumn::make('roles.name')->label('Role')->badge()->placeholder('Belum ada role'),
        ])
            ->filters([
                SelectFilter::make('roles')->label('Role')->relationship('roles', 'name', modifyQueryUsing: fn (Builder $query): Builder => $query->where('guard_name', 'web'))->searchable()->preload(),
            ])
            ->recordActions([EditAction::make()->label('Ubah')])
            ->defaultSort('name')
            ->emptyStateHeading('Belum ada user')
            ->emptyStateDescription('Tambahkan user dan pilih role sesuai tanggung jawabnya.');
    }
}
