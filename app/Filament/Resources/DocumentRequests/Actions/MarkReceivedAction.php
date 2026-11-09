<?php

namespace App\Filament\Resources\DocumentRequests\Actions;

use App\Models\Company;
use App\Models\DocumentRequest;
use App\Support\StorageFailure;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Fisik Diterima, dipakai di halaman detail dan di baris tabel pengajuan.
 *
 * Cukup konfirmasi; data arsip diambil dari pengajuan. Isian hanya muncul untuk data yang memang belum ada: scan bertanda tangan
 * (diunggah sekaligus), badan usaha, atau tanggal berakhir untuk jenis yang punya masa berlaku.
 */
class MarkReceivedAction
{
    private const FIELDS = ['signed_scan', 'company_id', 'expiry_date'];

    public static function make(): Action
    {
        return Action::make('markReceived')->label('Fisik Diterima')->modalWidth('lg')->requiresConfirmation()
            ->modalHeading('Tandai fisik diterima?')
            ->modalDescription(fn (DocumentRequest $record): string => 'Dokumen asli sudah ada di tim legal. Pengajuan ditandai Selesai dan scan bertanda tangan diarsipkan ke menu Dokumen.'
                .($record->hasSignedScan() ? '' : ' Scan belum masuk, jadi unggah sekalian di bawah.')
                .' Nomor dokumen bisa dilengkapi nanti di menu Dokumen.')
            ->modalSubmitActionLabel('Ya, fisik diterima')
            ->visible(fn (DocumentRequest $record): bool => ! $record->trashed() && in_array($record->status, ['signing', 'handover'], true) && auth()->user()->can('markReceived', $record))
            ->schema([
                FileUpload::make('signed_scan')->label('Scan dokumen bertanda tangan')->disk('local')->visibility('private')->storeFiles(false)->preventFilePathTampering()
                    ->acceptedFileTypes(config('lms.allowed_mime_types'))->maxSize(config('lms.max_upload_size_kb'))->required()
                    ->visible(fn (DocumentRequest $record): bool => ! $record->hasSignedScan()),
                Select::make('company_id')->label('Badan Usaha arsip')->options(fn (): array => Company::where('is_active', true)->pluck('name', 'id')->all())->searchable()->required()
                    ->helperText('Pengajuan ini belum memakai badan usaha yang terdaftar.')
                    ->visible(fn (DocumentRequest $record): bool => $record->company_id === null),
                DatePicker::make('expiry_date')->label('Tanggal berakhir')->native(false)->required()
                    ->helperText('Jenis dokumen ini punya masa berlaku, tetapi pengajuan belum mencantumkan tanggal berakhir.')
                    ->visible(fn (DocumentRequest $record): bool => $record->documentType->has_expiry && $record->expiry_date === null),
            ])
            ->action(function (DocumentRequest $record, array $data, Action $action): void {
                try {
                    $record->markReceived(null, null, $data['expiry_date'] ?? null, auth()->user(), isset($data['company_id']) ? (int) $data['company_id'] : null, $data['signed_scan'] ?? null);
                } catch (ValidationException $exception) {
                    $record->refresh();
                    $errors = collect($exception->errors());
                    $mapped = $errors->only(self::FIELDS)->mapWithKeys(fn (array $messages, string $key): array => ['mountedActions.0.data.'.$key => $messages]);
                    if ($mapped->isNotEmpty()) {
                        throw ValidationException::withMessages($mapped->all());
                    }
                    Notification::make()->title('Fisik belum ditandai diterima')->body((string) $errors->flatten()->first())->danger()->send();
                    $action->cancel();
                } catch (AuthorizationException|HttpExceptionInterface|ModelNotFoundException $exception) {
                    throw $exception;
                } catch (Throwable $exception) {
                    report($exception);
                    $record->refresh();
                    Notification::make()->title('Fisik belum ditandai diterima')->body(StorageFailure::message($exception, 'Terjadi gangguan sistem. Tidak ada yang berubah; coba lagi.'))->danger()->send();
                    $action->cancel();
                }
                Notification::make()->title('Fisik diterima')->body('Pengajuan selesai dan dokumen sudah masuk menu Dokumen.')->success()->send();
            });
    }
}
