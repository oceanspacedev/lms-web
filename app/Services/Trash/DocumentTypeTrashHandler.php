<?php

namespace App\Services\Trash;

use App\Models\DocumentType;
use App\Models\ReminderTemplate;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Jenis Dokumen: dipakai dokumen dan pengajuan lewat `document_type_id` (spec §4.3).
 * Template pengingatnya ikut dihapus permanen dan tidak dihitung sebagai "dipakai".
 */
class DocumentTypeTrashHandler extends MasterDataTrashHandler
{
    protected function modelClass(): string
    {
        return DocumentType::class;
    }

    protected function foreignKey(): string
    {
        return 'document_type_id';
    }

    protected function subjectName(): string
    {
        return 'jenis dokumen';
    }

    /**
     * Template pengingat dihapus setelah jenis dokumennya, kecuali pengaturan pengingat global
     * atau template yang masih dipasang di jenis dokumen lain (termasuk yang di tempat sampah).
     */
    public function purge(Model $record, User $actor): array
    {
        $templateId = $record->reminder_template_id;
        $files = parent::purge($record, $actor);

        if ($templateId !== null && ! DocumentType::withTrashed()->where('reminder_template_id', $templateId)->exists()) {
            ReminderTemplate::whereKey($templateId)->whereNull('global_key')->first()?->delete();
        }

        return $files;
    }
}
