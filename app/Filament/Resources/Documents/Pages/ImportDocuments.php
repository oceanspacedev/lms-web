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
use Throwable;

/**
 * Impor dokumen lama: 1 unduh template, 2 unggah Excel + ZIP, 3 periksa (dry run), 4 impor bertahap.
 * Pemeriksaan dan impor berjalan di antrean (queue worker), jadi halaman boleh ditutup; batch bisa dibuka lagi lewat ?batch=ID.
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

        return DocumentImport::where('user_id', auth()->id())->whereIn('status', DocumentImport::UNFINISHED_STATUSES)->latest('id')->first();
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
            // File dipindah ke folder batch lalu diperiksa di antrean; halaman memantau sampai pemeriksaan selesai.
            $import = app(DocumentImporter::class)->queueCheck($excel, $zips, auth()->user());
        } catch (Throwable $exception) {
            report($exception);
            throw ValidationException::withMessages(['data.excel' => 'File tidak bisa diperiksa karena gangguan sistem. Coba lagi; bila berulang, hubungi admin.']);
        }

        foreach ([$excel, ...$zips] as $file) {
            rescue(fn () => $file->delete(), report: false);
        }
        $this->form->fill();
        $this->skipPastReminders = true;

        // Antrean sinkron (tes, atau server tanpa worker) sudah selesai memeriksa: tampilkan masalah file di form seperti biasa.
        if ($import->status === 'check_failed') {
            throw ValidationException::withMessages(collect($import->notes['check_errors'] ?? [])->mapWithKeys(fn (array $messages, string $field): array => ['data.'.$field => $messages])->all());
        }
        $this->importId = $import->id;
    }

    /**
     * Mulai impor di antrean (ProcessDocumentImport). Halaman ini boleh ditutup; kemajuan dipantau lewat polling dan
     * notifikasi lonceng dikirim saat selesai.
     */
    public function startImport(): bool
    {
        $import = $this->import();
        abort_if($import === null, 404);

        return app(DocumentImporter::class)->start($import, $this->skipPastReminders);
    }

    /** Antrekan ulang impor yang berhenti di tengah (misalnya worker dimulai ulang). Baris yang sudah masuk tidak diulang. */
    public function resumeImport(): void
    {
        $import = $this->import();
        abort_if($import === null, 404);
        app(DocumentImporter::class)->resume($import);
        Notification::make()->title('Impor dilanjutkan')->body('Sisa dokumen diproses di latar belakang.')->info()->send();
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
