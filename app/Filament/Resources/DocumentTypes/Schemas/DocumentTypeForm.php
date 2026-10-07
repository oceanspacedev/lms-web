<?php

namespace App\Filament\Resources\DocumentTypes\Schemas;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class DocumentTypeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nama Jenis Dokumen')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
                Toggle::make('is_active')
                    ->label('Aktif')
                    ->default(true)
                    ->helperText('Nonaktifkan jenis dokumen yang tidak digunakan lagi. Data tetap tersimpan.'),
                Toggle::make('has_expiry')
                    ->label('Memiliki Masa Berlaku')
                    ->default(false)
                    ->live(),
                Section::make('Form Pengajuan')->collapsed()->columnSpanFull()->schema([
                    Select::make('request_pic_id')->label('PIC pengajuan')->options(fn (): array => User::permission('Review:DocumentRequest')->whereNotNull('phone')->pluck('name', 'id')->all())->searchable()->placeholder('Otomatis (tim pemeriksa)'),
                    Actions::make([
                        Action::make('cooperation')->label('Gunakan contoh Kerja Sama')->color('gray')
                            ->requiresConfirmation()->modalHeading('Ganti form pengajuan?')->modalDescription('Field dan daftar lampiran akan diganti.')
                            ->action(function (Set $set): void {
                                $set('request_fields', [
                                    ['label' => 'Tujuan', 'type' => 'textarea', 'required' => true],
                                    ['label' => 'Ruang Lingkup', 'type' => 'textarea', 'required' => true],
                                    ['label' => 'Hak dan Kewajiban', 'type' => 'textarea', 'required' => false],
                                    ['label' => 'Calon Penandatangan', 'type' => 'text', 'required' => true],
                                ]);
                                $set('request_attachments', [
                                    ['label' => 'Proposal', 'required' => true, 'when' => 'always'],
                                    ['label' => 'Profil Mitra', 'required' => true, 'when' => 'always'],
                                    ['label' => 'Draf MoU / PKS', 'required' => false, 'when' => 'always'],
                                    ['label' => 'Legalitas Mitra', 'required' => false, 'when' => 'always'],
                                    ['label' => 'RAB / Penawaran', 'required' => true, 'when' => 'cost'],
                                    ['label' => 'Perjanjian Sebelumnya', 'required' => true, 'when' => 'renewal'],
                                ]);
                            }),
                    ]),
                    Repeater::make('request_fields')->label('Isian tambahan')->maxItems(20)->defaultItems(0)->addActionLabel('Tambah isian')->columns(3)->schema([
                        Hidden::make('key'),
                        TextInput::make('label')->label('Nama isian')->required()->maxLength(100),
                        Select::make('type')->label('Tipe')->options(['text' => 'Teks', 'textarea' => 'Paragraf', 'date' => 'Tanggal', 'number' => 'Angka'])->default('text')->required(),
                        Toggle::make('required')->label('Wajib')->default(true),
                    ])->collapsed()->columnSpanFull()->itemLabel(fn (array $state): string => $state['label'] ?? 'Isian'),
                    Repeater::make('request_attachments')->label('Lampiran')->maxItems(20)->defaultItems(0)->addActionLabel('Tambah lampiran')->columns(3)->schema([
                        Hidden::make('key'),
                        TextInput::make('label')->label('Nama lampiran')->required()->maxLength(100),
                        Select::make('when')->label('Diperlukan')->options(['always' => 'Selalu', 'cost' => 'Ada biaya', 'renewal' => 'Perpanjangan / adendum'])->default('always')->required(),
                        Toggle::make('required')->label('Wajib')->default(true),
                    ])->collapsed()->columnSpanFull()->itemLabel(fn (array $state): string => $state['label'] ?? 'Lampiran'),
                    Select::make('request_reviewer_id')->label('Pemeriksa')->options(fn (): array => User::permission('Review:DocumentRequest')->pluck('name', 'id')->all())->searchable()->placeholder('Tim pemeriksa'),
                    Select::make('request_approver_id')->label('Pemberi persetujuan')->options(fn (): array => User::permission('Approve:DocumentRequest')->pluck('name', 'id')->all())->searchable()->placeholder('Tim persetujuan'),
                ])->columns(2),
            ]);
    }
}
