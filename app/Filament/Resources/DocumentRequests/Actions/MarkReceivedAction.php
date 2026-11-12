<?php

namespace App\Filament\Resources\DocumentRequests\Actions;

use App\Models\DocumentRequest;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Fisik Diterima, dipakai di halaman detail dan di baris tabel pengajuan. Cukup konfirmasi: pengajuan masuk tahap Scanning,
 * lalu tim legal mengunggah scan dokumen fisik lewat "Unggah Scan & Selesaikan" (CompleteWithScanAction).
 */
class MarkReceivedAction
{
    public static function make(): Action
    {
        return Action::make('markReceived')->label('Fisik Diterima')->requiresConfirmation()
            ->modalHeading('Tandai fisik diterima?')
            ->modalDescription(fn (DocumentRequest $record): string => 'Dokumen asli sudah ada di tim legal. Pengajuan masuk tahap Scanning dan pengingat keterlambatan berhenti.'
                .' Setelah dokumen discan, unggah scannya lewat tombol "Unggah Scan & Selesaikan".'
                .($record->signed_path ? '' : ' Pengaju belum mengunggah soft file bertanda tangan; itu tidak menghalangi.'))
            ->modalSubmitActionLabel('Ya, fisik diterima')
            ->visible(fn (DocumentRequest $record): bool => ! $record->trashed() && in_array($record->status, ['signing', 'handover'], true) && auth()->user()->can('markReceived', $record))
            ->action(function (DocumentRequest $record, Action $action): void {
                try {
                    $record->markReceived(auth()->user());
                } catch (ValidationException $exception) {
                    $record->refresh();
                    Notification::make()->title('Fisik belum ditandai diterima')->body((string) collect($exception->errors())->flatten()->first())->danger()->send();
                    $action->cancel();
                } catch (AuthorizationException|HttpExceptionInterface|ModelNotFoundException $exception) {
                    throw $exception;
                } catch (Throwable $exception) {
                    report($exception);
                    $record->refresh();
                    Notification::make()->title('Fisik belum ditandai diterima')->body('Terjadi gangguan sistem. Tidak ada yang berubah; coba lagi.')->danger()->send();
                    $action->cancel();
                }
                Notification::make()->title('Fisik diterima')->body('Pengajuan masuk tahap Scanning. Unggah scan dokumen fisik untuk menyelesaikannya.')->success()->send();
            });
    }
}
