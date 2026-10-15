<?php

namespace App\Models;

use Database\Factories\DocumentImportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu batch impor dokumen lama. Status tiap baris Excel disimpan di kolom `rows` (JSON) supaya proses bisa dilanjutkan
 * bila koneksi putus, dan nomor batch (ID) dicatat pada catatan perubahan versi pertama dokumen yang diimpor.
 */
#[Fillable(['user_id', 'uuid', 'excel_name', 'zip_names', 'status', 'skip_past_reminders', 'total_rows', 'imported_count', 'skipped_count', 'failed_count', 'rows', 'notes', 'completed_at'])]
class DocumentImport extends Model
{
    /** @use HasFactory<DocumentImportFactory> */
    use HasFactory;

    public const STATUSES = [
        'checked' => 'Sudah diperiksa',
        'importing' => 'Sedang diimpor',
        'completed' => 'Selesai',
        'cancelled' => 'Dibatalkan',
        'expired' => 'Kedaluwarsa',
    ];

    public const ROW_STATUSES = [
        'ready' => 'Siap',
        'skipped' => 'Dilewati (sudah ada)',
        'error' => 'Perlu diperbaiki',
        'imported' => 'Berhasil diimpor',
        'failed' => 'Gagal diimpor',
    ];

    public const ROW_STATUS_COLORS = [
        'ready' => 'info', 'skipped' => 'gray', 'error' => 'danger', 'imported' => 'success', 'failed' => 'danger',
    ];

    protected function casts(): array
    {
        return [
            'zip_names' => 'array',
            'rows' => 'array',
            'notes' => 'array',
            'skip_past_reminders' => 'boolean',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Folder sementara (disk local) berisi file hasil ekstrak ZIP batch ini. */
    public function directory(): string
    {
        return 'imports/'.$this->uuid;
    }

    /** Catatan perubahan versi 1 untuk setiap dokumen yang diimpor dalam batch ini. */
    public function changeNote(): string
    {
        return "Diimpor dari arsip lama (batch #{$this->id})";
    }

    public function isUnfinished(): bool
    {
        return in_array($this->status, ['checked', 'importing'], true);
    }

    /** Jumlah baris dengan salah satu status yang diberikan. */
    public function countRows(string ...$statuses): int
    {
        return collect($this->rows)->whereIn('status', $statuses)->count();
    }

    /** Baris yang lolos pemeriksaan (dry run), termasuk yang sudah diproses. */
    public function checkedReadyCount(): int
    {
        return collect($this->rows)->where('check', 'ready')->count();
    }

    /** Baris "Siap" yang sudah diproses saat impor (berhasil, dilewati, atau gagal). */
    public function processedCount(): int
    {
        return collect($this->rows)->where('check', 'ready')->where('status', '!=', 'ready')->count();
    }

    public function refreshCounts(): void
    {
        $this->total_rows = count($this->rows);
        $this->imported_count = $this->countRows('imported');
        $this->skipped_count = $this->countRows('skipped');
        $this->failed_count = $this->countRows('error', 'failed');
    }
}
