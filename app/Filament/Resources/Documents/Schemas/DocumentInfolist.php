<?php

namespace App\Filament\Resources\Documents\Schemas;

use App\Filament\Concerns\TrashActions;
use App\Models\Document;
use App\Support\FileSize;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class DocumentInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(['default' => 1, 'xl' => 3])
            ->components([
                Callout::make(fn (Document $record): string => TrashActions::trashedNotice($record))->warning()->columnSpanFull()
                    ->visible(fn (Document $record): bool => $record->trashed()),
                Section::make('Informasi Dokumen')
                    ->columnSpan(['default' => 1, 'xl' => 2])
                    ->columns(['default' => 1, 'sm' => 2])
                    ->schema([
                        TextEntry::make('expiry_status')->label('Status Masa Berlaku')->badge()
                            ->formatStateUsing(fn (string $state): string => Document::EXPIRY_STATUSES[$state])
                            ->color(fn (string $state): string => Document::EXPIRY_STATUS_COLORS[$state]),
                        TextEntry::make('document_number')->label('Nomor Dokumen')->placeholder('Belum diisi')->wrap(),
                        TextEntry::make('company.name')->label('Badan Usaha')->state(fn (Document $record): ?string => $record->company?->displayName())->wrap(),
                        TextEntry::make('documentType.name')->label('Jenis Dokumen')->state(fn (Document $record): ?string => $record->documentType?->displayName())->wrap(),
                        TextEntry::make('pic.name')->label('Penanggung Jawab (PIC)')->state(fn (Document $record): ?string => $record->pic?->displayName())->wrap(),
                        TextEntry::make('counterparty')->label('Pihak Lawan')->placeholder('Belum diisi')->wrap(),
                        TextEntry::make('notes')->label('Catatan')->placeholder('Tidak ada catatan')->wrap()->columnSpanFull(),
                    ]),
                Section::make('File Aktif')
                    ->columnSpan(['default' => 1, 'xl' => 1])
                    ->columns(2)
                    ->schema([
                        // Nama asli lebih mudah dikenali pengguna, jadi tampil lebih dulu; nama penyimpanan dipakai saat file diunduh.
                        TextEntry::make('currentVersion.file_name')->label('Nama File Asli')
                            ->icon('heroicon-o-document-text')->weight('medium')->wrap()->columnSpanFull()->placeholder('Belum ada file'),
                        TextEntry::make('currentVersion.stored_name')->label('Nama File Saat Diunduh')
                            ->color('gray')->size('sm')->wrap()->columnSpanFull()->placeholder('—'),
                        TextEntry::make('currentVersion.version_number')->label('Versi')->prefix('v')->placeholder('—'),
                        TextEntry::make('currentVersion.file_size')->label('Ukuran File')
                            ->formatStateUsing(fn (int $state): string => FileSize::format($state))->placeholder('—'),
                        TextEntry::make('currentVersion.issued_date')->label('Tanggal Terbit')->date('d M Y')->placeholder('Belum diisi'),
                        TextEntry::make('currentVersion.expiry_date')->label('Tanggal Berakhir')->date('d M Y')->placeholder('Tanpa masa berlaku'),
                        TextEntry::make('currentVersion.uploader.name')->label('Diunggah Oleh')->state(fn (Document $record): ?string => $record->currentVersion?->uploader?->displayName())->wrap()->columnSpanFull()->placeholder('—'),
                        TextEntry::make('currentVersion.created_at')->label('Waktu Unggah')->dateTime('d M Y H:i')->timezone(config('lms.reminder_timezone'))->columnSpanFull()->placeholder('—'),
                    ]),
            ]);
    }
}
