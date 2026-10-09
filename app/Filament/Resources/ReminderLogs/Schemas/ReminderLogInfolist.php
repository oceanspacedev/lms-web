<?php

namespace App\Filament\Resources\ReminderLogs\Schemas;

use App\Models\ReminderLog;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ReminderLogInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Pengingat Dokumen')->columns(2)->columnSpanFull()->schema([
                TextEntry::make('documentVersion.document.title')->label('Dokumen'),
                TextEntry::make('documentVersion.document.company.name')->label('Badan Usaha'),
                TextEntry::make('documentVersion.version_number')->label('Versi')->prefix('v'),
                TextEntry::make('offset_days')->label('Jadwal')->formatStateUsing(fn (int $state): string => $state < 0 ? 'H+'.abs($state) : 'H-'.$state),
                TextEntry::make('recipient_phone')->label('WhatsApp')->placeholder('-'),
                TextEntry::make('status')->label('Status')->badge()->formatStateUsing(fn (string $state): string => ReminderLog::STATUSES[$state] ?? $state),
                TextEntry::make('attempts')->label('Jumlah Percobaan'),
                TextEntry::make('last_attempt_at')->label('Percobaan Terakhir')->dateTime('d M Y H:i')->timezone(config('lms.reminder_timezone'))->placeholder('-'),
                TextEntry::make('error_message')->label('Keterangan Gagal')->placeholder('-')->columnSpanFull(),
                TextEntry::make('request_payload.message.text')->label('Isi Pesan')->placeholder('-')->columnSpanFull(),
            ]),
        ]);
    }
}
