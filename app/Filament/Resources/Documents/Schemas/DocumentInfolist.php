<?php

namespace App\Filament\Resources\Documents\Schemas;

use App\Models\Document;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class DocumentInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('title')->label('Judul'),
                TextEntry::make('expiry_status')->label('Status')->badge()
                    ->formatStateUsing(fn (string $state): string => Document::EXPIRY_STATUSES[$state])
                    ->color(fn (string $state): string => Document::EXPIRY_STATUS_COLORS[$state]),
                TextEntry::make('document_number')->label('Nomor Dokumen')->placeholder('Belum diisi'),
                TextEntry::make('company.name')->label('Perusahaan'),
                TextEntry::make('documentType.name')->label('Jenis Dokumen'),
                TextEntry::make('counterparty')->label('Pihak Lawan')->placeholder('Belum diisi'),
                TextEntry::make('pic.name')->label('PIC'),
                TextEntry::make('currentVersion.version_number')->label('Versi Aktif')->prefix('v'),
                TextEntry::make('currentVersion.file_name')->label('File'),
                TextEntry::make('currentVersion.issued_date')->label('Tanggal Terbit')->date('d/m/Y')->placeholder('Belum diisi'),
                TextEntry::make('currentVersion.expiry_date')->label('Tanggal Berakhir')->date('d/m/Y')->placeholder('Tanpa Masa Berlaku'),
                TextEntry::make('notes')->label('Catatan')->placeholder('Belum diisi')->columnSpanFull(),
            ]);
    }
}
