<?php

namespace App\Models;

use Database\Factories\DocumentVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use LogicException;

#[Fillable(['document_id', 'version_number', 'disk', 'file_path', 'file_name', 'file_size', 'mime_type', 'issued_date', 'expiry_date', 'is_current', 'change_note', 'uploaded_by'])]
class DocumentVersion extends Model
{
    /** @use HasFactory<DocumentVersionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['issued_date' => 'date', 'expiry_date' => 'date', 'is_current' => 'boolean'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * Nama file tersimpan yang mudah dilacak, dipakai untuk tampilan dan nama unduhan.
     * Nama dari komputer pengunggah tetap ada di `file_name`.
     */
    protected function storedName(): Attribute
    {
        return Attribute::get(fn (): string => basename(str_replace('\\', '/', (string) $this->file_path)));
    }

    /**
     * Disk tempat file versi ini disimpan, bukan disk default saat ini.
     */
    public function storage(): FilesystemAdapter
    {
        return Storage::disk($this->disk ?? 's3');
    }

    protected static function booted(): void
    {
        static::updating(function (DocumentVersion $version): void {
            if ($version->isDirty(['document_id', 'version_number', 'disk', 'file_path', 'file_name', 'file_size', 'mime_type', 'issued_date', 'expiry_date', 'change_note', 'uploaded_by'])) {
                throw new LogicException('Versi dokumen tidak dapat diubah. Unggah versi baru untuk memperbarui dokumen.');
            }
        });
        static::deleting(function (DocumentVersion $version): void {
            throw new LogicException('Versi dokumen tidak dapat dihapus.');
        });
    }
}
