<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Models\User;
use App\Services\WaghubService;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Support\RawJs;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Nama')->required()->maxLength(255),
            TextInput::make('email')->label('Email')->email()->required()->maxLength(255)->unique(ignoreRecord: true),
            TextInput::make('phone')->label('Nomor HP / WhatsApp')->tel()->mask(RawJs::make(WaghubService::PHONE_INPUT_MASK))->extraInputAttributes(['inputmode' => 'tel'])->maxLength(30)
                ->helperText('Digunakan untuk pengingat dokumen dan masuk dengan kode WhatsApp. Contoh: 081234567890.')
                ->rules([fn (?User $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                    $phone = app(WaghubService::class)->normalizePhone($value);
                    if (filled($value) && $phone === null) {
                        $fail('Masukkan nomor WhatsApp Indonesia yang valid.');
                    } elseif ($phone !== null && User::phoneIsTaken($phone, $record?->getKey())) {
                        $fail('Nomor WhatsApp sudah dipakai user lain.');
                    }
                }])
                ->dehydrateStateUsing(fn (?string $state): ?string => app(WaghubService::class)->normalizePhone($state)),
            Select::make('roles')->label('Peran')
                ->relationship('roles', 'name', modifyQueryUsing: fn (Builder $query): Builder => $query->where('guard_name', 'web'))
                ->multiple()->searchable()->preload()
                ->disabled(fn (): bool => ! Gate::allows('Update:Role'))
                ->helperText('Hak akses mengikuti peran. User tanpa peran atau izin tidak dapat masuk panel.'),
            TextInput::make('password')->label('Kata Sandi')->password()->revealable()->minLength(8)->maxLength(255)
                ->afterStateHydrated(fn (TextInput $component) => $component->state(null))
                ->required(fn (string $operation): bool => $operation === 'create')
                ->dehydrated(fn (?string $state): bool => filled($state))
                ->same('password_confirmation')
                ->helperText('Minimal 8 karakter. Saat mengubah user, kosongkan untuk mempertahankan kata sandi.'),
            TextInput::make('password_confirmation')->label('Konfirmasi Kata Sandi')->password()->revealable()
                ->requiredWith('password')->dehydrated(false),
        ]);
    }
}
