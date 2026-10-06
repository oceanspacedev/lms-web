<?php

namespace App\Filament\Resources\Documents\Pages;

use App\Filament\Resources\Documents\DocumentResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class EditDocument extends EditRecord
{
    protected static string $resource = DocumentResource::class;

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Informasi dokumen berhasil disimpan';
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return DB::transaction(function () use ($record, $data): Model {
            $record->fill($data);
            $hasChanges = $record->isDirty();
            $record->save();
            if ($hasChanges) {
                $record->activities()->create([
                    'document_version_id' => $record->current_version_id,
                    'user_id' => auth()->id(),
                    'event' => 'metadata_updated',
                ]);
            }

            return $record;
        });
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make()->label('Lihat'),
            DeleteAction::make()->label('Hapus Dokumen'),
        ];
    }
}
