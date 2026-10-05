<?php

namespace App\Filament\Resources\Documents\Pages;

use App\Filament\Resources\Documents\DocumentResource;
use App\Models\Document;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Throwable;

class CreateDocument extends CreateRecord
{
    protected static string $resource = DocumentResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        if (! ($data['file'] ?? null) instanceof UploadedFile) {
            throw ValidationException::withMessages(['data.file' => 'Unggah file dokumen yang valid.']);
        }

        try {
            return Document::archive($data, $data['file'], auth()->user());
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())
                ->mapWithKeys(fn (array $messages, string $field): array => ['data.'.$field => $messages])
                ->all());
        } catch (Throwable $exception) {
            report($exception);
            Notification::make()->title('Dokumen belum tersimpan')->body('Upload ke penyimpanan gagal. Periksa konfigurasi S3 atau coba lagi.')->danger()->send();

            throw new Halt;
        }
    }
}
