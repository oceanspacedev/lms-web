<?php

namespace App\Filament\Auth;

use App\Services\WaghubService;
use Closure;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class EditProfile extends BaseEditProfile
{
    public function form(Schema $schema): Schema
    {
        return $schema->components([
            $this->getNameFormComponent(),
            $this->getEmailFormComponent(),
            TextInput::make('phone')->label('Nomor WhatsApp')->tel()->maxLength(30)
                ->helperText('Digunakan untuk pengingat dokumen. Contoh: 081234567890.')
                ->rules([fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                    if (filled($value) && app(WaghubService::class)->normalizePhone($value) === null) {
                        $fail('Masukkan nomor WhatsApp Indonesia yang valid.');
                    }
                }])
                ->dehydrateStateUsing(fn (?string $state): ?string => app(WaghubService::class)->normalizePhone($state)),
            $this->getPasswordFormComponent(),
            $this->getPasswordConfirmationFormComponent(),
            $this->getCurrentPasswordFormComponent(),
        ]);
    }
}
