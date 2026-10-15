<?php

namespace App\Filament\Resources\Documents\Pages;

use App\Filament\Resources\Documents\DocumentResource;
use App\Models\DocumentImport;
use App\Services\DocumentImporter;
use App\Services\DocumentImportTemplate;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Impor dokumen lama: 1 unduh template, 2 unggah Excel + ZIP, 3 periksa (dry run), 4 impor bertahap dari browser.
 * Proses impor dipanggil per CHUNK_SIZE baris oleh browser (tanpa queue worker) dan bisa dilanjutkan lewat ?batch=ID.
 */
class ImportDocuments extends Page
{
    public const EXCEL_MAX_SIZE_KB = 5120;

    protected static string $resource = DocumentResource::class;

    protected string $view = 'filament.resources.documents.import';

    #[Url(as: 'batch')]
    public ?int $importId = null;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public bool $skipPastReminders = true;

    public static function canAccess(array $parameters = []): bool
    {
        return DocumentResource::canCreate();
    }

    public function getTitle(): string
    {
        return 'Impor Dokumen Lama';
    }

    public function getBreadcrumbs(): array
    {
        return [DocumentResource::getUrl() => 'Dokumen', 'Impor'];
    }

    public function mount(): void
    {
        $this->form->fill();
        if ($this->importId !== null && $this->import() === null) {
            $this->importId = null;
        }
        $this->skipPastReminders = $this->import()?->skip_past_reminders ?? true;
    }

    public function form(Schema $schema): Schema
    {
        $zipMaxKb = (int) config('lms.import_max_zip_kb');

        return $schema->statePath('data')->components([
            FileUpload::make('excel')->label('File Excel (.xlsx)')->disk('local')->visibility('private')->storeFiles(false)
                ->preventFilePathTampering()->required()
                ->acceptedFileTypes(['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'])
                ->rules(['extensions:xlsx'])->maxSize(self::EXCEL_MAX_SIZE_KB)
                ->validationMessages(['extensions' => 'File Excel harus berformat .xlsx.'])
                ->helperText('Isi sesuai template. Maksimal '.config('lms.import_max_rows').' baris per impor.'),
            FileUpload::make('zips')->label('File ZIP berisi dokumen')->disk('local')->visibility('private')->storeFiles(false)
                ->preventFilePathTampering()->required()->multiple()->maxFiles(10)
                ->acceptedFileTypes(['application/zip', 'application/x-zip-compressed', 'application/x-zip', 'multipart/x-zip', 'application/octet-stream'])
                ->rules(['extensions:zip'])->maxSize($zipMaxKb)
                ->validationMessages(['extensions' => 'File dokumen harus dikemas dalam .zip.'])
                ->helperText('Boleh lebih dari satu ZIP. Maksimal '.DocumentImporter::megabytes($zipMaxKb).' MB per ZIP. Nama file harus sama dengan kolom "Nama File" di Excel.'),
        ]);
    }

    public function import(): ?DocumentImport
    {
        if ($this->importId === null) {
            return null;
        }

        return DocumentImport::whereKey($this->importId)->where('user_id', auth()->id())->first();
    }

    /** Batch terakhir milik user ini yang belum selesai, untuk ditawarkan dilanjutkan saat halaman dibuka tanpa ?batch. */
    public function unfinishedImport(): ?DocumentImport
    {
        if ($this->importId !== null) {
            return null;
        }

        return DocumentImport::where('user_id', auth()->id())->whereIn('status', ['checked', 'importing'])->latest('id')->first();
    }

    public function downloadTemplate(): StreamedResponse
    {
        return app(DocumentImportTemplate::class)->downloadTemplate();
    }

    public function check(): void
    {
        $data = $this->form->getState();
        $excel = $data['excel'] ?? null;
        $zips = array_values(Arr::wrap($data['zips'] ?? []));
        if (! $excel instanceof UploadedFile || $zips === [] || collect($zips)->contains(fn (mixed $zip): bool => ! $zip instanceof UploadedFile)) {
            throw ValidationException::withMessages(['data.excel' => 'Unggah file Excel dan minimal satu ZIP.']);
        }

        try {
            $import = app(DocumentImporter::class)->check(
                $excel->getRealPath(),
                $excel->getClientOriginalName(),
                array_map(fn (UploadedFile $zip): array => ['path' => $zip->getRealPath(), 'name' => $zip->getClientOriginalName()], $zips),
                auth()->user(),
            );
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(fn (array $messages, string $field): array => ['data.'.$field => $messages])->all());
        }

        foreach ([$excel, ...$zips] as $file) {
            rescue(fn () => $file->delete(), report: false);
        }
        $this->form->fill();
        $this->importId = $import->id;
        $this->skipPastReminders = true;
    }

    /** Mulai impor; browser lalu memanggil processNextChunk() berulang sampai selesai. */
    public function startImport(): bool
    {
        $import = $this->import();
        abort_if($import === null, 404);

        return app(DocumentImporter::class)->start($import, $this->skipPastReminders);
    }

    /**
     * Proses CHUNK_SIZE baris berikutnya. Mengembalikan true bila masih ada baris yang menunggu; false juga bila tahap ini
     * tidak memproses apa pun (batch sedang diproses tab lain), supaya browser berhenti dan menampilkan tombol lanjutkan.
     */
    public function processNextChunk(): bool
    {
        $import = $this->import();
        abort_if($import === null, 404);
        $processedBefore = $import->processedCount();
        $import = app(DocumentImporter::class)->processChunk($import, auth()->user());

        if ($import->status === 'completed') {
            Notification::make()->title('Impor selesai')
                ->body("{$import->imported_count} berhasil, {$import->skipped_count} dilewati, {$import->failed_count} gagal.")
                ->success()->send();
        }

        return $import->status === 'importing' && $import->countRows('ready') > 0 && $import->processedCount() > $processedBefore;
    }

    public function cancelImport(): void
    {
        $import = $this->import();
        if ($import !== null) {
            app(DocumentImporter::class)->cancel($import);
        }
        $this->startOver();
    }

    public function startOver(): void
    {
        $this->importId = null;
        $this->skipPastReminders = true;
        $this->form->fill();
    }

    public function downloadFailedRows(): StreamedResponse
    {
        $import = $this->import();
        abort_if($import === null, 404);

        return app(DocumentImportTemplate::class)->downloadFailedRows($import);
    }

    /** @return array{due: int, expired: int} */
    public function reminderImpact(): array
    {
        $import = $this->import();

        return $import === null ? ['due' => 0, 'expired' => 0] : app(DocumentImporter::class)->reminderImpact($import);
    }
}
