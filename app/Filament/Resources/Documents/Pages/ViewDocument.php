<?php

namespace App\Filament\Resources\Documents\Pages;

use App\Filament\Resources\Documents\DocumentResource;
use App\Filament\Resources\Documents\Schemas\DocumentVersionForm;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

class ViewDocument extends ViewRecord
{
    protected static string $resource = DocumentResource::class;

    public function getTitle(): string
    {
        return $this->getRecord()->title;
    }

    public function getSubheading(): ?string
    {
        return collect([$this->getRecord()->company?->name, $this->getRecord()->documentType?->name])
            ->filter()->implode(' · ');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('download')->label('Unduh File')->icon('heroicon-o-arrow-down-tray')
                ->url(fn (): string => route('documents.download', $this->getRecord()))->openUrlInNewTab()
                ->visible(fn (): bool => $this->getRecord()->currentVersion !== null),
            EditAction::make()->label('Ubah Informasi')->icon('heroicon-o-pencil-square')->color('gray'),
            $this->versionAction('renew')->label('Perpanjang')->icon('heroicon-o-arrow-path-rounded-square')
                ->color(fn (): string => in_array($this->getRecord()->expiry_status, ['expiring', 'expired'], true) ? 'warning' : 'gray')
                ->visible(fn (): bool => (bool) $this->getRecord()->documentType?->has_expiry && $this->getRecord()->currentVersion?->expiry_date !== null)
                ->modalHeading('Perpanjang Dokumen')
                ->modalDescription('Tanggal dan catatan sudah diisi dari versi aktif. Periksa kembali sebelum menyimpan.')
                ->fillForm(fn (): array => $this->getRecord()->renewalSuggestion()),
            $this->versionAction('updateVersion')->label('Unggah Versi Baru')->icon('heroicon-o-arrow-up-tray')->color('gray')
                ->modalHeading('Perbarui Dokumen'),
        ];
    }

    private function versionAction(string $name): Action
    {
        return Action::make($name)
            ->authorize('update', $this->getRecord())
            ->modalSubmitActionLabel('Simpan Versi Baru')
            ->schema(fn (): array => DocumentVersionForm::components($this->getRecord()->documentType->has_expiry))
            ->action(function (array $data): void {
                Gate::authorize('update', $this->getRecord());
                if (! ($data['file'] ?? null) instanceof UploadedFile) {
                    throw ValidationException::withMessages(['mountedActions.0.data.file' => 'Unggah file dokumen yang valid.']);
                }

                try {
                    $this->getRecord()->appendVersion($data, $data['file'], auth()->user());
                } catch (ValidationException $exception) {
                    throw ValidationException::withMessages(collect($exception->errors())
                        ->mapWithKeys(fn (array $messages, string $field): array => ['mountedActions.0.data.'.$field => $messages])->all());
                } catch (Throwable $exception) {
                    report($exception);
                    Notification::make()->title('Versi baru belum tersimpan')->body('Penyimpanan gagal. Versi sebelumnya tetap aktif. Coba lagi.')->danger()->send();

                    throw new Halt;
                }

                $this->dispatch('document-version-updated');
                Notification::make()->title('Versi baru berhasil disimpan')->success()->send();
            });
    }
}
