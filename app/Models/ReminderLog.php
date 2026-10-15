<?php

namespace App\Models;

use App\Services\DocumentReminderSender;
use Carbon\CarbonImmutable;
use Database\Factories\ReminderLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['document_version_id', 'offset_days', 'recipient_phone', 'status', 'attempts', 'request_payload', 'provider_response', 'error_message', 'last_attempt_at', 'sent_at', 'resent_by', 'resent_at'])]
class ReminderLog extends Model
{
    /** @use HasFactory<ReminderLogFactory> */
    use HasFactory;

    public const STATUSES = ['pending' => 'Diproses', 'sent' => 'Terkirim', 'accepted' => 'Diterima WagHub', 'failed' => 'Gagal', 'cancelled' => 'Dibatalkan'];

    /** Alasan pembatalan bila dokumennya dihapus; dipakai untuk mengenali log yang dibuka kembali saat dokumen dipulihkan. */
    public const DOCUMENT_DELETED_MESSAGE = 'Dokumen dihapus; pengingat dilanjutkan bila dokumen dipulihkan.';

    /** Alasan pembatalan bila versi dokumen sudah diganti atau jadwalnya tidak berlaku lagi. */
    public const OUTDATED_SCHEDULE_MESSAGE = 'Versi atau jadwal pengingat sudah tidak berlaku.';

    /** Keterangan pengingat yang batas percobaannya habis sebelum dokumen dihapus, lalu dokumennya dipulihkan. */
    public const EXHAUSTED_BEFORE_DELETION_MESSAGE = 'Batas percobaan pengiriman sudah habis sebelum dokumen dihapus. Gunakan Kirim ulang bila pesan masih perlu dikirim.';

    protected function casts(): array
    {
        return ['request_payload' => 'array', 'provider_response' => 'array', 'last_attempt_at' => 'datetime', 'sent_at' => 'datetime', 'resent_at' => 'datetime'];
    }

    public function documentVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class);
    }

    /**
     * Saat dokumen dipulihkan, hanya pengingat versi aktif untuk jadwal yang sedang jatuh tempo hari ini yang dibuka kembali,
     * dengan isi pesan dan penerima disusun ulang. Pengingat jadwal lain yang terlewat selama dokumen dihapus tetap dibatalkan,
     * supaya PIC tidak menerima pesan lama ("Sisa waktu: 30 hari") bersamaan dengan pengingat yang berlaku sekarang.
     * Pengingat yang batas percobaannya sudah habis sebelum dihapus kembali berstatus Gagal (bisa dikirim ulang manual),
     * bukan dicoba otomatis dari awal.
     */
    public static function reopenForRestoredDocument(Document $document): int
    {
        $cancelled = static::where('document_version_id', $document->current_version_id)
            ->where('status', 'cancelled')->where('error_message', self::DOCUMENT_DELETED_MESSAGE);
        $document->loadMissing(['currentVersion', 'documentType']);
        $dueOffset = $document->documentType?->has_expiry && $document->currentVersion?->expiry_date
            ? app(DocumentReminderSender::class)->dueOffset($document, CarbonImmutable::today(config('lms.reminder_timezone')))
            : null;
        (clone $cancelled)->when($dueOffset !== null, fn (Builder $query): Builder => $query->where('offset_days', '!=', $dueOffset))
            ->update(['error_message' => self::OUTDATED_SCHEDULE_MESSAGE]);
        if ($dueOffset === null) {
            return 0;
        }
        $maxAttempts = (int) config('lms.reminder_max_attempts');
        $exhausted = (clone $cancelled)->where('offset_days', $dueOffset)->where('attempts', '>=', $maxAttempts)
            ->update(['request_payload' => null, 'status' => 'failed', 'error_message' => self::EXHAUSTED_BEFORE_DELETION_MESSAGE]);

        return $exhausted + (clone $cancelled)->where('offset_days', $dueOffset)->where('attempts', '<', $maxAttempts)
            ->update(['request_payload' => null, 'recipient_phone' => null, 'last_attempt_at' => null, 'status' => 'pending', 'attempts' => 0, 'error_message' => null]);
    }
}
