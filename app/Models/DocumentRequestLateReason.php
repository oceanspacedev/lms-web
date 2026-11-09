<?php

namespace App\Models;

use Database\Factories\DocumentRequestLateReasonFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Alasan keterlambatan penyerahan dokumen fisik; user_id kosong bila diisi lewat tautan publik. */
#[Fillable(['reason', 'user_id'])]
class DocumentRequestLateReason extends Model
{
    /** @use HasFactory<DocumentRequestLateReasonFactory> */
    use HasFactory;

    public function documentRequest(): BelongsTo
    {
        return $this->belongsTo(DocumentRequest::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }
}
