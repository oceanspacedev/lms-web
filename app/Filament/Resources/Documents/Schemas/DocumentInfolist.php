<?php

namespace App\Filament\Resources\Documents\Schemas;

use App\Models\Document;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Number;

class DocumentInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(['default' => 1, 'xl' => 3])
            ->components([
                Section::make('Informasi Dokumen')
                    ->columnSpan(['default' => 1, 'xl' => 2])
                    ->columns(['default' => 1, 'sm' => 2])
                    ->schema([
                        TextEntry::make('expiry_status')->label('Status Masa Berlaku')->badge()
                            ->formatStateUsing(fn (string $state): string => Document::EXPIRY_STATUSES[$state])
                            ->color(fn (string $state): string => Document::EXPIRY_STATUS_COLORS[$state]),
                        TextEntry::make('document_number')->label('Nomor Dokumen')->placeholder('Belum diisi')->wrap(),
                        TextEntry::make('company.name')->label('Badan Usaha')->wrap(),
                        TextEntry::make('documentType.name')->label('Jenis Dokumen')->wrap(),
                        TextEntry::make('pic.name')->label('Penanggung Jawab (PIC)')->wrap(),
                        TextEntry::make('counterparty')->label('Pihak Lawan')->placeholder('Belum diisi')->wrap(),
                        TextEntry::make('notes')->label('Catatan')->placeholder('Tidak ada catatan')->wrap()->columnSpanFull(),
                    ]),
                Section::make('File Aktif')
                    ->columnSpan(['default' => 1, 'xl' => 1])
                    ->columns(2)
                    ->schema([
                        TextEntry::make('currentVersion.file_name')->label('Nama File')
                            ->icon('heroicon-o-document-text')->wrap()->columnSpanFull()->placeholder('Belum ada file'),
                        TextEntry::make('currentVersion.version_number')->label('Versi')->prefix('v')->placeholder('—'),
                        TextEntry::make('currentVersion.file_size')->label('Ukuran File')
                            ->formatStateUsing(fn (int $state): string => Number::fileSize($state, precision: 1))->placeholder('—'),
                        TextEntry::make('currentVersion.issued_date')->label('Tanggal Terbit')->date('d/m/Y')->placeholder('Belum diisi'),
                        TextEntry::make('currentVersion.expiry_date')->label('Tanggal Berakhir')->date('d/m/Y')->placeholder('Tanpa masa berlaku'),
                        TextEntry::make('currentVersion.uploader.name')->label('Diunggah Oleh')->wrap()->columnSpanFull()->placeholder('—'),
                        TextEntry::make('currentVersion.created_at')->label('Waktu Unggah')->dateTime('d/m/Y H:i')->columnSpanFull()->placeholder('—'),
                    ]),
            ]);
    }
}
