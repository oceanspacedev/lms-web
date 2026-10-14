<?php

namespace App\Filament\Resources\ReminderLogs;

use App\Filament\Resources\ReminderLogs\Pages\ListReminderLogs;
use App\Filament\Resources\ReminderLogs\Pages\ViewReminderLog;
use App\Filament\Resources\ReminderLogs\Schemas\ReminderLogInfolist;
use App\Filament\Resources\ReminderLogs\Tables\ReminderLogsTable;
use App\Filament\Resources\ReminderTemplates\ReminderTemplateResource;
use App\Models\ReminderLog;
use App\Models\ReminderTemplate;
use App\Services\DocumentReminderSender;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class ReminderLogResource extends Resource
{
    protected static ?string $model = ReminderLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBell;

    protected static ?string $modelLabel = 'Log Pengingat';

    protected static ?string $pluralModelLabel = 'Log Pengingat';

    protected static string|\UnitEnum|null $navigationGroup = 'Administrasi';

    protected static ?int $navigationSort = 1;

    /** Dokumen yang dihapus tetap dimuat, supaya log pengingatnya tetap menunjukkan dokumen mana yang perlu dipulihkan. */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            'documentVersion.document' => fn (BelongsTo $query): BelongsTo => $query->withTrashed(),
            'documentVersion.document.company',
        ]);
    }

    /** Hanya user yang boleh mengubah pengaturan WhatsApp yang boleh mengirim ulang pesan gagal. */
    public static function canResend(): bool
    {
        $setting = ReminderTemplate::globalSetting();

        return $setting !== null && ReminderTemplateResource::canEdit($setting);
    }

    /** Tombol "Kirim ulang" bersama untuk baris tabel dan halaman detail; hanya tampil untuk log berstatus gagal. */
    public static function resendAction(): Action
    {
        $canResend = null;

        return Action::make('resend')->label('Kirim ulang')->icon(Heroicon::OutlinedArrowPath)->color('gray')
            ->visible(function (ReminderLog $record) use (&$canResend): bool {
                // Hak kirim ulang sama untuk semua baris, jadi dicek sekali per tabel (dan hanya bila ada log gagal), bukan per baris.
                return $record->status === 'failed' && ($canResend ??= static::canResend());
            })
            ->requiresConfirmation()->modalHeading('Kirim ulang pengingat?')
            ->modalDescription('Kunci pengiriman sama, jadi pesan tidak akan terkirim ganda.')
            ->modalSubmitActionLabel('Kirim ulang')
            ->action(function (ReminderLog $record): void {
                abort_unless(static::canResend(), 403);
                static::resendAndNotify(fn (): ?string => app(DocumentReminderSender::class)->resend($record, auth()->user()), fn (): ?string => $record->error_message);
            });
    }

    /**
     * Jalankan kirim ulang lalu tampilkan hasilnya. Konfigurasi WagHub yang belum lengkap (WaghubService::assertConfigured)
     * dilaporkan sebagai notifikasi, bukan halaman error; dipakai juga oleh riwayat pengiriman WhatsApp.
     *
     * @param  Closure(): ?string  $resend
     * @param  (Closure(): ?string)|null  $cancelReason  Keterangan log setelah kirim ulang, untuk menjelaskan pembatalan.
     */
    public static function resendAndNotify(Closure $resend, ?Closure $cancelReason = null): void
    {
        try {
            $outcome = $resend();
        } catch (RuntimeException $exception) {
            report($exception);
            Notification::make()->title('Konfigurasi WagHub belum lengkap')->body('Pesan belum dikirim ulang. Hubungi administrator.')->danger()->send();

            return;
        }
        static::notifyResendOutcome($outcome, $cancelReason ? $cancelReason() : null);
    }

    /** Notifikasi hasil kirim ulang; dipakai juga oleh riwayat pengiriman WhatsApp. */
    public static function notifyResendOutcome(?string $outcome, ?string $cancelReason = null): void
    {
        match (true) {
            $outcome === 'accepted' => Notification::make()->title('Pesan diterima WagHub')->body('Status ini belum memastikan pesan diterima di WhatsApp tujuan.')->success()->send(),
            $outcome === 'failed' => Notification::make()->title('Pengiriman gagal lagi')->body('Periksa nomor penerima dan konfigurasi WagHub, lalu coba lagi.')->danger()->send(),
            $outcome === 'cancelled' && $cancelReason === ReminderLog::DOCUMENT_DELETED_MESSAGE => Notification::make()->title('Pesan tidak dikirim')
                ->body('Dokumennya sudah dihapus. Pulihkan dokumen agar pengingatnya berjalan lagi.')->warning()->send(),
            $outcome === 'cancelled' => Notification::make()->title('Pesan tidak dikirim')->body('Pengajuan atau dokumen sudah berpindah tahap, sehingga pesan ini tidak berlaku lagi.')->warning()->send(),
            default => Notification::make()->title('Pesan ini tidak dapat dikirim ulang')->body('Hanya pesan berstatus Gagal yang dapat dikirim ulang.')->warning()->send(),
        };
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function canForceDelete(Model $record): bool
    {
        return false;
    }

    public static function canForceDeleteAny(): bool
    {
        return false;
    }

    public static function canRestore(Model $record): bool
    {
        return false;
    }

    public static function canRestoreAny(): bool
    {
        return false;
    }

    public static function canReplicate(Model $record): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return ReminderLogInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ReminderLogsTable::configure($table);
    }

    public static function getPages(): array
    {
        return ['index' => ListReminderLogs::route('/'), 'view' => ViewReminderLog::route('/{record}')];
    }
}
