<?php

namespace App\Filament\Auth;

use App\Models\User;
use App\Services\WaghubService;
use Closure;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Support\RawJs;

class EditProfile extends BaseEditProfile
{
    public function form(Schema $schema): Schema
    {
        return $schema->components([
            $this->getNameFormComponent(),
            $this->getEmailFormComponent(),
            TextInput::make('phone')->label('Nomor WhatsApp')->tel()->mask(RawJs::make(WaghubService::PHONE_INPUT_MASK))->extraInputAttributes(['inputmode' => 'tel'])->maxLength(30)
                ->helperText('Digunakan untuk pengingat dokumen dan masuk dengan kode WhatsApp. Contoh: 081234567890.')
                ->rules([fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                    $phone = app(WaghubService::class)->normalizePhone($value);
                    if (filled($value) && $phone === null) {
                        $fail('Masukkan nomor WhatsApp Indonesia yang valid.');
                    } elseif ($phone !== null && User::phoneIsTaken($phone, $this->getUser()->getKey())) {
                        $fail('Nomor WhatsApp sudah dipakai user lain.');
                    }
                }])
                ->dehydrateStateUsing(fn (?string $state): ?string => app(WaghubService::class)->normalizePhone($state)),
            $this->getPasswordFormComponent(),
            $this->getPasswordConfirmationFormComponent(),
            $this->getCurrentPasswordFormComponent(),
        ]);
    }
}
