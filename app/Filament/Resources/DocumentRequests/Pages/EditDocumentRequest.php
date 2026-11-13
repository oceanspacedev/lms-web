<?php

namespace App\Filament\Resources\DocumentRequests\Pages;

use App\Filament\Concerns\HasBackToListAction;
use App\Filament\Concerns\ShowsTrashedRecordReadOnly;
use App\Filament\Concerns\TrashActions;
use App\Filament\Resources\DocumentRequests\Actions\CompleteWithScanAction;
use App\Filament\Resources\DocumentRequests\Actions\MarkReceivedAction;
use App\Filament\Resources\DocumentRequests\DocumentRequestResource;
use App\Filament\Resources\Documents\DocumentResource;
use App\Models\DocumentRequest;
use App\Policies\DocumentRequestPolicy;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\FileUpload;
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
    use ShowsTrashedRecordReadOnly;

    protected static string $resource = DocumentRequestResource::class;

    public function getTitle(): string
    {
        return ($this->record->trashed() ? 'Detail ' : '').$this->record->title;
    }

    /** Halaman hanya-baca (pengajuan tidak dapat diubah user ini, atau ada di tempat sampah) disebut "Detail", bukan "Ubah". */
    public function getBreadcrumb(): string
    {
        return $this->record->editableBy(auth()->user()) && ! $this->record->trashed() ? parent::getBreadcrumb() : 'Detail';
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

        $cancel = Action::make('cancel')->label('Batalkan')->icon('heroicon-o-x-circle')->color('danger')->modalWidth('lg')
            ->modalHeading('Batalkan pengajuan?')->modalSubmitActionLabel('Ya, batalkan')->modalCancelActionLabel('Kembali')
            ->visible(fn (): bool => in_array($this->record->status, DocumentRequest::CANCELLABLE_STATUSES, true) && auth()->user()->can('cancel', $this->record))
            ->schema([Textarea::make('note')->label('Alasan pembatalan')->required()->maxLength(4000)->rows(3)])
            ->action(fn (array $data) => $this->runFlowAction(
                fn () => $this->record->transition('cancelled', auth()->user(), $data['note']),
                ['note'], 'Pengajuan dibatalkan', 'Pengajuan belum dibatalkan',
            ));

        $flowActions = [
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
            Action::make('startReview')->label('Periksa')->icon('heroicon-o-magnifying-glass')->requiresConfirmation()
                ->modalHeading('Mulai periksa pengajuan ini?')
                ->modalDescription('Pengajuan ditandai sedang diperiksa tim legal. Riwayat pengaju mencatat "Tim Legal memeriksa pengajuan dokumen" dan pengaju dikabari lewat WhatsApp. Setelah lengkap, klik Proses Drafting.')
                ->modalSubmitActionLabel('Ya, periksa')
                ->visible(fn (): bool => $this->record->status === 'submitted' && auth()->user()->can('startReview', $this->record))
                ->action(fn () => $this->decide('reviewing')),
            Action::make('startDrafting')->label('Proses Drafting')->icon('heroicon-o-pencil-square')->requiresConfirmation()
                ->modalHeading('Mulai susun draft?')
                ->modalDescription('Pengajuan ditandai sedang dibuatkan draft. Riwayat pengaju mencatat "Tim Legal sedang melakukan pembuatan draft" dan pengaju dikabari lewat WhatsApp. Setelah draft siap, klik Keluarkan Draft.')
                ->modalSubmitActionLabel('Ya, proses')
                ->color(fn (): string => $this->record->status === 'submitted' ? 'gray' : 'primary')
                ->visible(fn (): bool => in_array($this->record->status, ['submitted', 'reviewing'], true) && auth()->user()->can('startDrafting', $this->record))
                ->action(fn () => $this->decide('drafting')),
            Action::make('issueDraft')->label(fn (): string => $this->record->draft_path ? 'Ganti Draft' : 'Keluarkan Draft')->modalWidth('lg')
                ->color(fn (): string => in_array($this->record->status, ['submitted', 'reviewing'], true) ? 'gray' : 'primary')
                ->visible(fn (): bool => in_array($this->record->status, [...DocumentRequest::REVIEW_STATUSES, 'signing'], true) && auth()->user()->can('issueDraft', $this->record))
                ->schema([$this->flowFileUpload('Draft dokumen')])
                ->action(fn (array $data) => $this->runFlowAction(
                    fn () => $this->record->issueDraft($data['file'], auth()->user()),
                    ['file'], 'Draft dikirim ke pengaju', 'Draft belum disimpan',
                    fn (): string => 'Pengaju dikabari lewat WhatsApp untuk menandatangani dan mengunggah soft file bertanda tangan.',
                )),
            Action::make('uploadSigned')->label(fn (): string => $this->record->signed_path ? 'Ganti Soft File TTD' : 'Unggah Soft File TTD')->modalWidth('lg')
                ->color(fn (): string => $this->record->signed_path ? 'gray' : 'primary')
                ->visible(fn (): bool => in_array($this->record->status, ['signing', 'handover'], true) && auth()->user()->can('uploadSigned', $this->record))
                ->schema([$this->flowFileUpload('Soft file dokumen bertanda tangan')])
                ->action(fn (array $data) => $this->runFlowAction(
                    fn () => $this->record->uploadSigned($data['file'], auth()->user()),
                    ['file'], 'Soft file disimpan', 'Soft file belum disimpan',
                    fn (): ?string => ($due = $this->record->handoverDueDate()) ? 'Dokumen asli harus diterima tim legal paling lambat '.$due->translatedFormat('d M Y').'.' : null,
                )),
            Action::make('addLateReason')->label(fn (): string => $this->record->requester_id === auth()->id() ? 'Tulis Alasan Keterlambatan' : 'Catat Alasan Keterlambatan')->color('warning')->modalWidth('lg')
                ->modalDescription(fn (): ?string => $this->record->requester_id === auth()->id() ? null : 'Catat alasan yang disampaikan pengaju, misalnya lewat telepon atau chat. Alasan tercatat sebagai dicatat oleh tim legal.')
                ->visible(fn (): bool => $this->record->isHandoverLate() && auth()->user()->can('addLateReason', $this->record))
                ->schema([Textarea::make('reason')->label('Alasan keterlambatan')->required()->maxLength(2000)->rows(3)])
                ->action(fn (array $data) => $this->runFlowAction(
                    fn () => $this->record->addLateReason($data['reason'], auth()->user()),
                    ['reason'], 'Alasan dicatat', 'Alasan belum dicatat',
                )),
            MarkReceivedAction::make()->after(fn () => $this->refreshFormData(['status', 'history'])),
            CompleteWithScanAction::make()->after(fn () => $this->refreshFormData(['status', 'history'])),
        ];

        return [
            $this->backToListAction(),
            TrashActions::restore('pengajuan'),
            TrashActions::forceDelete('pengajuan'),
            // Pengajuan di tempat sampah hanya-baca: semua langkah alur disembunyikan sampai dipulihkan.
            ...array_map(fn (Action $action): Action => $action->hidden(fn (): bool => $this->record->trashed()), $flowActions),
            Action::make('document')->label('Buka Dokumen')->color('gray')->visible(fn (): bool => $this->record->document_id && auth()->user()->can('View:Document'))->url(fn (): ?string => $this->record->document_id ? DocumentResource::getUrl('view', ['record' => $this->record->document_id]) : null),
            // Aksi yang jarang dipakai dan berisiko dikumpulkan di menu Lainnya agar tidak sejajar dengan tombol utama (mis. Fisik Diterima).
            ActionGroup::make([
                $cancel->hidden(fn (): bool => $this->record->trashed()),
                TrashActions::delete('pengajuan'),
            ])->label('Lainnya')->icon('heroicon-m-ellipsis-horizontal')->color('gray')->button(),
        ];
    }

    private function flowFileUpload(string $label, bool|Closure $required = true): FileUpload
    {
        return FileUpload::make('file')->label($label)->disk('local')->visibility('private')->storeFiles(false)->preventFilePathTampering()
            ->acceptedFileTypes(config('lms.allowed_mime_types'))->maxSize(config('lms.max_upload_size_kb'))->required($required);
    }

    /**
     * Jalankan aksi alur tanda tangan. Galat validasi dipetakan ke kolom form aksi (kunci lain jadi notifikasi),
     * dan kegagalan penyimpanan dilaporkan tanpa 500.
     *
     * @param  list<string>  $fields  Kolom form aksi yang boleh menerima pesan galat.
     * @param  (Closure(): ?string)|null  $successBody  Isi notifikasi sukses, dihitung setelah aksi berhasil.
     */
    private function runFlowAction(Closure $callback, array $fields, string $successTitle, string $failureTitle, ?Closure $successBody = null): void
    {
        try {
            $callback();
        } catch (ValidationException $exception) {
            $this->record->refresh();
            $errors = collect($exception->errors());
            $mapped = $errors->only($fields)->mapWithKeys(fn (array $messages, string $key): array => ['mountedActions.0.data.'.$key => $messages]);
            if ($mapped->isEmpty()) {
                Notification::make()->title($failureTitle)->body((string) $errors->flatten()->first())->danger()->send();

                return;
            }

            throw ValidationException::withMessages($mapped->all());
        } catch (AuthorizationException|HttpExceptionInterface|ModelNotFoundException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            $this->record->refresh();
            Notification::make()->title($failureTitle)->body('Terjadi gangguan sistem. Tidak ada yang berubah; coba lagi.')->danger()->send();

            return;
        }
        $this->refreshFormData(['status', 'history']);
        Notification::make()->title($successTitle)->body($successBody ? $successBody() : null)->success()->send();
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
