<?php

namespace App\Filament\Resources\DocumentRequests\Pages;

use App\Filament\Concerns\HasBackToListAction;
use App\Filament\Resources\DocumentRequests\DocumentRequestResource;
use App\Filament\Resources\Documents\DocumentResource;
use App\Models\DocumentRequest;
use App\Policies\DocumentRequestPolicy;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class EditDocumentRequest extends EditRecord
{
    use HasBackToListAction;

    protected static string $resource = DocumentRequestResource::class;

    public function getTitle(): string
    {
        return $this->record->title;
    }

    /** Halaman hanya-baca (pengajuan tidak dapat diubah user ini) disebut "Detail", bukan "Ubah". */
    public function getBreadcrumb(): string
    {
        return $this->record->editableBy(auth()->user()) ? parent::getBreadcrumb() : 'Detail';
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return DocumentRequest::saveDraft($data, auth()->user(), $record);
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()->label('Simpan Draf')->visible(fn (): bool => $this->record->editableBy(auth()->user()));
    }

    protected function getCancelFormAction(): Action
    {
        return parent::getCancelFormAction()->visible(fn (): bool => $this->record->editableBy(auth()->user()));
    }

    protected function getHeaderActions(): array
    {
        $policy = new DocumentRequestPolicy;

        return [
            $this->backToListAction(),
            Action::make('submit')->label('Ajukan')->visible(fn (): bool => $this->record->editableBy(auth()->user()) && auth()->user()->can('update', $this->record))
                ->action(function (): void {
                    $this->save(shouldRedirect: false, shouldSendSavedNotification: false);
                    $this->record->refresh();
                    try {
                        $this->record->transition('submitted', auth()->user());
                    } catch (ValidationException $exception) {
                        throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(fn (array $messages, string $key): array => ['data.'.$key => $messages])->all());
                    }
                    $this->refreshFormData(['status', 'history']);
                    Notification::make()->title('Pengajuan dikirim')->success()->send();
                }),
            Action::make('revise')->label('Revisi')->color('gray')->visible(fn (): bool => $policy->revise(auth()->user(), $this->record))->schema([Textarea::make('note')->label('Catatan')->required()->maxLength(4000)->rows(3)])
                ->action(fn (array $data) => $this->decide('revision', $data['note'])),
            Action::make('reject')->label('Tolak')->color('danger')->visible(fn (): bool => $policy->revise(auth()->user(), $this->record))->schema([Textarea::make('note')->label('Alasan')->required()->maxLength(4000)->rows(3)])
                ->action(fn (array $data) => $this->decide('rejected', $data['note'])),
            Action::make('document')->label('Buka Dokumen')->color('gray')->visible(fn (): bool => $this->record->document_id && auth()->user()->can('View:Document'))->url(fn (): ?string => $this->record->document_id ? DocumentResource::getUrl('view', ['record' => $this->record->document_id]) : null),
        ];
    }

    private function decide(string $status, ?string $note = null): void
    {
        try {
            $this->record->transition($status, auth()->user(), $note);
        } catch (ValidationException|AuthorizationException|HttpExceptionInterface|ModelNotFoundException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            $this->record->refresh();
            Notification::make()->title('Status belum diperbarui')->body('Terjadi gangguan sistem. Tidak ada yang berubah; coba lagi.')->danger()->send();

            return;
        }
        $this->refreshFormData(['status', 'history']);
        Notification::make()->title('Status diperbarui')->success()->send();
    }
}
