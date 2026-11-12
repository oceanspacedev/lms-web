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
 * Unggah Scan & Selesaikan (tahap Scanning), dipakai di halaman detail dan di baris tabel pengajuan.
 * Scan dokumen fisik menjadi file arsip di menu Dokumen, pengajuan Selesai, dan pengaju dikabari bahwa scan bisa diunduh.
 * Isian selain scan hanya muncul untuk data arsip yang memang belum ada: badan usaha, atau tanggal berakhir untuk jenis yang punya masa berlaku.
 */
class CompleteWithScanAction
{
    private const FIELDS = ['scan', 'company_id', 'expiry_date'];

    public static function make(): Action
    {
        return Action::make('completeWithScan')->label('Unggah Scan & Selesaikan')->modalWidth('lg')
            ->modalHeading('Unggah scan dokumen fisik')
            ->modalDescription('Scan dokumen asli yang sudah ditandatangani (dan dicap). Pengajuan ditandai Selesai, scan masuk menu Dokumen, dan pengaju dikabari lewat WhatsApp bahwa scan bisa diunduh. Nomor dokumen bisa dilengkapi nanti di menu Dokumen.')
            ->modalSubmitActionLabel('Unggah & selesaikan')
            ->visible(fn (DocumentRequest $record): bool => ! $record->trashed() && $record->status === 'scanning' && auth()->user()->can('completeWithScan', $record))
            ->schema([
                FileUpload::make('scan')->label('Scan dokumen fisik')->disk('local')->visibility('private')->storeFiles(false)->preventFilePathTampering()
                    ->acceptedFileTypes(config('lms.allowed_mime_types'))->maxSize(config('lms.max_upload_size_kb'))->required(),
                Select::make('company_id')->label('Badan Usaha arsip')->options(fn (): array => Company::where('is_active', true)->pluck('name', 'id')->all())->searchable()->required()
                    ->helperText('Pengajuan ini belum memakai badan usaha yang terdaftar.')
                    ->visible(fn (DocumentRequest $record): bool => $record->company_id === null),
                DatePicker::make('expiry_date')->label('Tanggal berakhir')->native(false)->required()
                    ->helperText('Jenis dokumen ini punya masa berlaku, tetapi pengajuan belum mencantumkan tanggal berakhir.')
                    ->visible(fn (DocumentRequest $record): bool => $record->documentType->has_expiry && $record->expiry_date === null),
            ])
            ->action(function (DocumentRequest $record, array $data, Action $action): void {
                try {
                    $record->completeWithScan($data['scan'], auth()->user(), $data['expiry_date'] ?? null, isset($data['company_id']) ? (int) $data['company_id'] : null);
                } catch (ValidationException $exception) {
                    $record->refresh();
                    $errors = collect($exception->errors());
                    $mapped = $errors->only(self::FIELDS)->mapWithKeys(fn (array $messages, string $key): array => ['mountedActions.0.data.'.$key => $messages]);
                    if ($mapped->isNotEmpty()) {
                        throw ValidationException::withMessages($mapped->all());
                    }
                    Notification::make()->title('Pengajuan belum diselesaikan')->body((string) $errors->flatten()->first())->danger()->send();
                    $action->cancel();
                } catch (AuthorizationException|HttpExceptionInterface|ModelNotFoundException $exception) {
                    throw $exception;
                } catch (Throwable $exception) {
                    report($exception);
                    $record->refresh();
                    Notification::make()->title('Pengajuan belum diselesaikan')->body(StorageFailure::message($exception, 'Terjadi gangguan sistem. Tidak ada yang berubah; coba lagi.'))->danger()->send();
                    $action->cancel();
                }
                Notification::make()->title('Pengajuan selesai')->body('Scan sudah masuk menu Dokumen dan pengaju dikabari lewat WhatsApp.')->success()->send();
            });
    }
}
