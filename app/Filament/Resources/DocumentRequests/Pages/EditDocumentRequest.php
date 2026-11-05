<?php

namespace App\Filament\Resources\DocumentRequests\Pages;

use App\Filament\Concerns\HasBackToListAction;
use App\Filament\Concerns\ShowsTrashedRecordReadOnly;
use App\Filament\Concerns\TrashActions;
use App\Filament\Resources\DocumentRequests\DocumentRequestResource;
use App\Filament\Resources\Documents\DocumentResource;
use App\Models\Company;
use App\Models\DocumentRequest;
use App\Policies\DocumentRequestPolicy;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
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
            Action::make('issueDraft')->label(fn (): string => $this->record->draft_path ? 'Ganti Draft' : 'Keluarkan Draft')->modalWidth('lg')
                ->visible(fn (): bool => in_array($this->record->status, ['submitted', 'signing'], true) && auth()->user()->can('issueDraft', $this->record))
                ->schema([$this->flowFileUpload('Draft dokumen')])
                ->action(fn (array $data) => $this->runFlowAction(
                    fn () => $this->record->issueDraft($data['file'], auth()->user()),
                    ['file'], 'Draft dikirim ke pengaju', 'Draft belum disimpan',
                    fn (): string => 'Pengaju dikabari lewat WhatsApp untuk menandatangani dan mengunggah scan.',
                )),
            Action::make('uploadSigned')->label(fn (): string => $this->record->signed_path ? 'Ganti Scan' : 'Unggah Scan Bertanda Tangan')->modalWidth('lg')
                ->color(fn (): string => $this->record->signed_path ? 'gray' : 'primary')
                ->visible(fn (): bool => in_array($this->record->status, ['signing', 'handover'], true) && auth()->user()->can('uploadSigned', $this->record))
                ->schema([$this->flowFileUpload('Scan dokumen bertanda tangan')])
                ->action(fn (array $data) => $this->runFlowAction(
                    fn () => $this->record->uploadSigned($data['file'], auth()->user()),
                    ['file'], 'Scan disimpan', 'Scan belum disimpan',
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
            Action::make('markReceived')->label('Fisik Diterima')->modalWidth('lg')->modalSubmitActionLabel('Simpan & Arsipkan')
                ->modalDescription('Dokumen diarsipkan ke menu Dokumen dan pengajuan ditandai Selesai.')
                ->visible(fn (): bool => in_array($this->record->status, ['signing', 'handover'], true) && auth()->user()->can('markReceived', $this->record))
                ->schema([
                    TextInput::make('number')->label('Nomor Dokumen')->required()->maxLength(255),
                    Select::make('company_id')->label('Badan Usaha arsip')->options(fn (): array => Company::where('is_active', true)->pluck('name', 'id')->all())->searchable()->required()->visible(fn (): bool => $this->record->company_id === null),
                    DatePicker::make('issued_date')->label('Tanggal terbit')->default(fn (): ?string => $this->record->start_date?->toDateString()),
                    DatePicker::make('expiry_date')->label('Tanggal berakhir')->default(fn (): ?string => $this->record->expiry_date?->toDateString())->required(fn (): bool => $this->record->documentType->has_expiry)->visible(fn (): bool => $this->record->documentType->has_expiry)->afterOrEqual('issued_date'),
                    $this->flowFileUpload('Dokumen bertanda tangan', required: fn (): bool => blank($this->record->signed_path))
                        ->helperText(fn (): ?string => filled($this->record->signed_path) ? 'Opsional. Kosongkan untuk memakai scan yang sudah diunggah.' : null),
                ])
                ->action(fn (array $data) => $this->runFlowAction(
                    fn () => $this->record->markReceived($data['number'], $data['issued_date'] ?? null, $data['expiry_date'] ?? null, auth()->user(), isset($data['company_id']) ? (int) $data['company_id'] : null, ($data['file'] ?? null) instanceof UploadedFile ? $data['file'] : null),
                    ['number', 'company_id', 'expiry_date', 'file'], 'Dokumen diarsipkan', 'Dokumen belum diarsipkan',
                )),
            Action::make('cancel')->label('Batalkan')->color('danger')->modalWidth('lg')
                ->modalHeading('Batalkan pengajuan?')->modalSubmitActionLabel('Ya, batalkan')->modalCancelActionLabel('Kembali')
                ->visible(fn (): bool => in_array($this->record->status, DocumentRequest::CANCELLABLE_STATUSES, true) && auth()->user()->can('cancel', $this->record))
                ->schema([Textarea::make('note')->label('Alasan pembatalan')->required()->maxLength(4000)->rows(3)])
                ->action(fn (array $data) => $this->runFlowAction(
                    fn () => $this->record->transition('cancelled', auth()->user(), $data['note']),
                    ['note'], 'Pengajuan dibatalkan', 'Pengajuan belum dibatalkan',
                )),
        ];

        return [
            $this->backToListAction(),
            TrashActions::restore('pengajuan'),
            TrashActions::forceDelete('pengajuan'),
            // Pengajuan di tempat sampah hanya-baca: semua langkah alur disembunyikan sampai dipulihkan.
            ...array_map(fn (Action $action): Action => $action->hidden(fn (): bool => $this->record->trashed()), $flowActions),
            Action::make('document')->label('Buka Dokumen')->color('gray')->visible(fn (): bool => $this->record->document_id && auth()->user()->can('View:Document'))->url(fn (): ?string => $this->record->document_id ? DocumentResource::getUrl('view', ['record' => $this->record->document_id]) : null),
            TrashActions::delete('pengajuan'),
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
