<?php

namespace App\Filament\Resources\DocumentRequests\Pages;

use App\Filament\Resources\DocumentRequests\DocumentRequestResource;
use App\Filament\Resources\Documents\DocumentResource;
use App\Models\Company;
use App\Models\DocumentRequest;
use App\Policies\DocumentRequestPolicy;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class EditDocumentRequest extends EditRecord
{
    protected static string $resource = DocumentRequestResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return DocumentRequest::saveDraft($data, auth()->user(), $record);
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()->label('Simpan Draf')->visible(fn (): bool => $this->record->editableBy(auth()->user()));
    }

    protected function getHeaderActions(): array
    {
        $policy = new DocumentRequestPolicy;

        return [
            Action::make('submit')->label('Ajukan')->visible(fn (): bool => $this->record->editableBy(auth()->user()) && auth()->user()->can('update', $this->record))
                ->action(function (): void {
                    $this->save();
                    $this->record->refresh();
                    try {
                        $this->record->transition('submitted', auth()->user());
                    } catch (ValidationException $exception) {
                        throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(fn (array $messages, string $key): array => ['data.'.$key => $messages])->all());
                    }
                    $this->refreshFormData(['status', 'history']);
                    Notification::make()->title('Pengajuan dikirim')->success()->send();
                }),
            Action::make('review')->label('Lanjutkan')->visible(fn (): bool => $policy->review(auth()->user(), $this->record))->requiresConfirmation()->modalHeading('Lanjutkan ke persetujuan?')->modalDescription(null)
                ->action(fn () => $this->decide('review')),
            Action::make('approve')->label('Setujui')->visible(fn (): bool => $policy->approve(auth()->user(), $this->record))->requiresConfirmation()->modalHeading('Setujui pengajuan?')->modalDescription(null)
                ->action(fn () => $this->decide('approved')),
            Action::make('revise')->label('Revisi')->color('gray')->visible(fn (): bool => $policy->decide(auth()->user(), $this->record))->schema([Textarea::make('note')->label('Catatan')->required()->maxLength(4000)->rows(3)])
                ->action(fn (array $data) => $this->decide('revision', $data['note'])),
            Action::make('reject')->label('Tolak')->color('danger')->visible(fn (): bool => $policy->decide(auth()->user(), $this->record))->schema([Textarea::make('note')->label('Alasan')->required()->maxLength(4000)->rows(3)])
                ->action(fn (array $data) => $this->decide('rejected', $data['note'])),
            Action::make('archive')->label('Unggah Dokumen Final')->visible(fn (): bool => $policy->archive(auth()->user(), $this->record))->modalWidth('lg')->schema([
                TextInput::make('number')->label('Nomor Dokumen')->required()->maxLength(255),
                Select::make('company_id')->label('Badan Usaha arsip')->options(fn (): array => Company::where('is_active', true)->pluck('name', 'id')->all())->searchable()->required()->visible(fn (): bool => $this->record->company_id === null),
                DatePicker::make('issued_date')->label('Tanggal terbit')->default(fn (): ?string => $this->record->start_date?->toDateString()),
                DatePicker::make('expiry_date')->label('Tanggal berakhir')->default(fn (): ?string => $this->record->expiry_date?->toDateString())->required(fn (): bool => $this->record->documentType->has_expiry)->visible(fn (): bool => $this->record->documentType->has_expiry)->afterOrEqual('issued_date'),
                FileUpload::make('file')->label('Dokumen bertanda tangan')->disk('local')->visibility('private')->storeFiles(false)->preventFilePathTampering()->acceptedFileTypes(config('lms.allowed_mime_types'))->maxSize(config('lms.max_upload_size_kb'))->required(),
            ])->action(function (array $data): void {
                if (! ($data['file'] ?? null) instanceof UploadedFile) {
                    throw ValidationException::withMessages(['file' => 'Unggah dokumen final.']);
                }
                $this->record->archiveSigned($data['file'], $data['number'], $data['issued_date'] ?? null, $data['expiry_date'] ?? null, auth()->user(), isset($data['company_id']) ? (int) $data['company_id'] : null);
                $this->refreshFormData(['status', 'history']);
                Notification::make()->title('Dokumen diarsipkan')->success()->send();
            }),
            Action::make('document')->label('Buka Dokumen')->color('gray')->visible(fn (): bool => $this->record->document_id && auth()->user()->can('View:Document'))->url(fn (): ?string => $this->record->document_id ? DocumentResource::getUrl('view', ['record' => $this->record->document_id]) : null),
        ];
    }

    private function decide(string $status, ?string $note = null): void
    {
        $this->record->transition($status, auth()->user(), $note);
        $this->refreshFormData(['status', 'history']);
        Notification::make()->title('Status diperbarui')->success()->send();
    }
}
