<?php

namespace App\Models;

use Database\Factories\DocumentActivityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['document_id', 'document_version_id', 'user_id', 'event'])]
class DocumentActivity extends Model
{
    /** @use HasFactory<DocumentActivityFactory> */
    use HasFactory;

    public const EVENTS = ['uploaded' => 'Dokumen diunggah', 'version_updated' => 'Versi baru diunggah', 'metadata_updated' => 'Informasi dokumen diubah', 'downloaded' => 'Unduhan diminta', 'previewed' => 'Pratinjau dibuka'];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class)->withTrashed();
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'document_version_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Riwayat aktivitas tidak dapat diubah.');
        });
        static::deleting(function (): void {
            throw new LogicException('Riwayat aktivitas tidak dapat dihapus.');
        });
    }
}
