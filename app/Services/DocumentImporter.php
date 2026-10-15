<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentImport;
use App\Models\DocumentType;
use App\Models\DocumentVersion;
use App\Models\ReminderLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\File as FileRule;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;
use Throwable;
use ZipArchive;

/**
 * Impor dokumen lama dari satu file Excel dan satu atau lebih ZIP: periksa (dry run) tanpa menyimpan apa pun,
 * lalu impor bertahap per CHUNK_SIZE baris memakai Document::archive() apa adanya.
 */
class DocumentImporter
{
    public const CHUNK_SIZE = 20;

    public const DISK = 'local';

    public const DIRECTORY = 'imports';

    /** Folder sementara yang lebih tua dari ini dihapus oleh pembersihan harian. */
    public const STALE_HOURS = 24;

    public const SKIPPED_REMINDER_MESSAGE = 'Dilewati saat impor';

    /** Kolom sheet "Dokumen": kunci internal => judul kolom (tanpa tanda bintang). */
    public const COLUMNS = [
        'title' => 'Judul',
        'document_number' => 'Nomor Dokumen',
        'company' => 'Badan Usaha',
        'document_type' => 'Jenis Dokumen',
        'counterparty' => 'Pihak Lawan',
        'pic_email' => 'Email PIC',
        'issued_date' => 'Tanggal Terbit',
        'expiry_date' => 'Tanggal Berakhir',
        'notes' => 'Catatan',
        'file_name' => 'Nama File',
    ];

    public const REQUIRED_COLUMNS = ['title', 'company', 'document_type', 'pic_email', 'file_name'];

    /** Berhenti membaca bila sebanyak ini baris kosong berturut-turut (sheet berformat sampai baris terakhir Excel). */
    private const MAX_CONSECUTIVE_BLANK_ROWS = 1000;

    /** Pesan baris bila Document::archive() menolak data saat impor, misalnya master data berubah setelah pemeriksaan. */
    private const ARCHIVE_FIELD_MESSAGES = [
        'company_id' => 'Badan usaha tidak ditemukan atau sudah nonaktif.',
        'document_type_id' => 'Jenis dokumen tidak ditemukan atau sudah nonaktif.',
        'title' => 'Judul wajib diisi, maksimal 255 karakter.',
        'document_number' => 'Nomor Dokumen maksimal 255 karakter.',
        'counterparty' => 'Pihak Lawan maksimal 255 karakter.',
        'pic_user_id' => 'PIC tidak ditemukan atau sudah nonaktif.',
        'notes' => 'Catatan maksimal 10.000 karakter.',
        'change_note' => 'Catatan perubahan tidak valid.',
        'issued_date' => 'Tanggal Terbit tidak valid.',
        'expiry_date' => 'Tanggal Berakhir wajib diisi dan tidak boleh sebelum Tanggal Terbit.',
    ];

    public function __construct(private DocumentReminderSender $reminders) {}

    /**
     * Periksa Excel dan ZIP lalu simpan hasilnya sebagai batch berstatus "checked". Belum ada dokumen yang dibuat.
     * Masalah di tingkat file dilempar sebagai ValidationException dengan kunci `excel` atau `zips`.
     *
     * @param  list<array{path: string, name: string}>  $zips
     */
    public function check(string $excelPath, string $excelName, array $zips, User $user): DocumentImport
    {
        $rows = $this->readRows($excelPath);
        $uuid = (string) Str::uuid();
        $directory = self::DIRECTORY.'/'.$uuid;
        $disk = Storage::disk(self::DISK);
        $disk->makeDirectory($directory.'/files');

        try {
            $referenced = collect($rows)->map(fn (array $row): string => Str::lower((string) $this->baseName($this->text($row['values']['file_name'] ?? null))))
                ->filter()->unique()->values()->all();
            $files = $this->extractZips($zips, $directory, $referenced);
            $unused = collect($files)->reject(fn (array $file, string $key): bool => in_array($key, $referenced, true))->pluck('name')->values();

            $import = new DocumentImport([
                'user_id' => $user->id,
                'uuid' => $uuid,
                'excel_name' => basename(str_replace('\\', '/', $excelName)),
                'zip_names' => array_map(fn (array $zip): string => basename(str_replace('\\', '/', $zip['name'])), $zips),
                'status' => 'checked',
                'skip_past_reminders' => true,
                'notes' => ['unused_files' => $unused->take(20)->all(), 'unused_count' => $unused->count()],
            ]);
            $import->rows = $this->evaluateRows($rows, $files);
            $import->refreshCounts();
            $import->save();

            return $import;
        } catch (Throwable $exception) {
            $disk->deleteDirectory($directory);

            throw $exception;
        }
    }

    /** Mulai impor batch yang sudah diperiksa. Mengembalikan false bila tidak ada baris "Siap". */
    public function start(DocumentImport $import, bool $skipPastReminders): bool
    {
        if ($import->status !== 'checked' || $import->countRows('ready') === 0) {
            return $import->status === 'importing';
        }

        $import->update(['status' => 'importing', 'skip_past_reminders' => $skipPastReminders]);

        return true;
    }

    /**
     * Impor paling banyak $limit baris "Siap" berikutnya. Aman dipanggil ulang setelah koneksi putus: baris yang sudah
     * diproses tidak diulang, dan kunci cache mencegah dua tab memproses batch yang sama bersamaan.
     */
    public function processChunk(DocumentImport $import, User $user, int $limit = self::CHUNK_SIZE): DocumentImport
    {
        $lock = Cache::lock('document-import-'.$import->id, 600);
        if (! $lock->get()) {
            return $import->refresh();
        }

        try {
            $import->refresh();
            if ($import->status !== 'importing') {
                return $import;
            }
            // Satu tahap mengunggah hingga CHUNK_SIZE file ke penyimpanan; beri waktu lebih dari batas bawaan PHP.
            @set_time_limit(300);

            $rows = $import->rows;
            $processed = 0;
            foreach ($rows as $index => $row) {
                if ($row['status'] !== 'ready') {
                    continue;
                }
                if ($processed >= $limit) {
                    break;
                }
                $rows[$index] = $this->importRow($import, $row, $user);
                $processed++;
                $import->rows = $rows;
                $import->refreshCounts();
                $import->save();
            }

            if ($import->countRows('ready') === 0) {
                $import->update(['status' => 'completed', 'completed_at' => now()]);
                Storage::disk(self::DISK)->deleteDirectory($import->directory());
            }

            return $import;
        } finally {
            $lock->release();
        }
    }

    /** Batalkan batch yang belum diimpor dan hapus file sementaranya. */
    public function cancel(DocumentImport $import): void
    {
        if ($import->status !== 'checked') {
            return;
        }

        $import->update(['status' => 'cancelled']);
        Storage::disk(self::DISK)->deleteDirectory($import->directory());
    }

    /**
     * Hapus folder sementara impor yang lebih tua dari STALE_HOURS, dan tandai batch yang belum selesai sebagai kedaluwarsa.
     *
     * @return int Jumlah folder yang dihapus.
     */
    public function cleanupStaleFiles(): int
    {
        $disk = Storage::disk(self::DISK);
        $cutoff = now()->subHours(self::STALE_HOURS);
        $deleted = 0;

        DocumentImport::whereIn('status', ['checked', 'importing'])->where('updated_at', '<', $cutoff)
            ->each(fn (DocumentImport $import): bool => $import->update(['status' => 'expired']));

        $root = $disk->path(self::DIRECTORY);
        if (! is_dir($root)) {
            return 0;
        }

        foreach (File::directories($root) as $path) {
            if (File::lastModified($path) >= $cutoff->getTimestamp()) {
                continue;
            }
            $import = DocumentImport::where('uuid', basename($path))->first();
            if ($import?->isUnfinished()) {
                continue;
            }
            File::deleteDirectory($path);
            $deleted++;
        }

        return $deleted;
    }

    /**
     * Perkiraan pengingat WhatsApp yang langsung terkirim pada pengiriman berikutnya untuk baris "Siap":
     * `due` = jadwal pengingat sudah tercapai (catch-up), `expired` = sudah kedaluwarsa.
     *
     * @return array{due: int, expired: int}
     */
    public function reminderImpact(DocumentImport $import): array
    {
        $rows = collect($import->rows)->where('status', 'ready')->filter(fn (array $row): bool => filled($row['data']['expiry_date'] ?? null));
        $types = DocumentType::findMany($rows->pluck('data.document_type_id')->unique())->keyBy('id');
        $today = CarbonImmutable::today(config('lms.reminder_timezone'));
        $impact = ['due' => 0, 'expired' => 0];

        foreach ($rows as $row) {
            $type = $types->get($row['data']['document_type_id']);
            if (! $type?->has_expiry) {
                continue;
            }
            $document = (new Document)->setRelation('documentType', $type)
                ->setRelation('currentVersion', new DocumentVersion(['expiry_date' => $row['data']['expiry_date']]));
            if ($row['data']['expiry_date'] < $today->toDateString()) {
                $impact['expired']++;
            }
            if ($this->reminders->dueOffset($document, $today) !== null) {
                $impact['due']++;
            }
        }

        return $impact;
    }

    /**
     * Baris yang perlu diperbaiki lalu diimpor ulang: gagal saat pemeriksaan atau impor, ditambah baris "Siap" yang
     * tidak sempat diproses karena batch kedaluwarsa.
     *
     * @return list<array<string, mixed>>
     */
    public function failedRows(DocumentImport $import): array
    {
        $statuses = $import->status === 'expired' ? ['error', 'failed', 'ready'] : ['error', 'failed'];

        return collect($import->rows)->whereIn('status', $statuses)->values()->all();
    }

    public static function megabytes(int $kilobytes): string
    {
        return rtrim(rtrim(number_format($kilobytes / 1024, 1, ',', '.'), '0'), ',');
    }

    /**
     * @return list<array{line: int, values: array<string, mixed>}>
     */
    private function readRows(string $path): array
    {
        $options = new Options;
        $options->SHOULD_PRESERVE_EMPTY_ROWS = true;
        $reader = new Reader($options);

        try {
            $reader->open($path);
        } catch (Throwable) {
            throw ValidationException::withMessages(['excel' => 'File Excel tidak bisa dibaca. Simpan sebagai .xlsx (gunakan template dari halaman ini) lalu unggah lagi.']);
        }

        try {
            $target = null;
            foreach ($reader->getSheetIterator() as $sheet) {
                if (Str::lower(trim($sheet->getName())) === Str::lower(DocumentSpreadsheetExporter::SHEET_NAME)) {
                    $target = $sheet;

                    break;
                }
            }
            if ($target === null) {
                foreach ($reader->getSheetIterator() as $sheet) {
                    $target = $sheet;

                    break;
                }
            }
            $rows = $target === null ? null : $this->readSheet($target->getRowIterator());
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw ValidationException::withMessages(['excel' => 'File Excel tidak bisa dibaca. Simpan sebagai .xlsx (gunakan template dari halaman ini) lalu unggah lagi.']);
        } finally {
            $reader->close();
        }

        if ($rows === null || $rows === []) {
            throw ValidationException::withMessages(['excel' => 'File Excel belum berisi baris dokumen. Isi sheet "Dokumen" mulai baris ke-2.']);
        }

        return $rows;
    }

    /**
     * @param  iterable<int, Row>  $iterator
     * @return list<array{line: int, values: array<string, mixed>}>
     */
    private function readSheet(iterable $iterator): array
    {
        $max = (int) config('lms.import_max_rows');
        $header = null;
        $rows = [];
        $blank = 0;

        foreach ($iterator as $line => $row) {
            $cells = $row->toArray();
            if (collect($cells)->every(fn (mixed $value): bool => $this->text($value) === null)) {
                if (++$blank >= self::MAX_CONSECUTIVE_BLANK_ROWS) {
                    break;
                }

                continue;
            }
            $blank = 0;

            if ($header === null) {
                $header = $this->mapHeader($cells);

                continue;
            }

            $values = [];
            foreach ($header as $index => $key) {
                $values[$key] = $cells[$index] ?? null;
            }
            $rows[] = ['line' => (int) $line, 'values' => $values];

            if (count($rows) > $max) {
                throw ValidationException::withMessages(['excel' => "File Excel berisi lebih dari {$max} baris dokumen. Bagi menjadi beberapa batch, maksimal {$max} baris per impor."]);
            }
        }

        if ($header === null) {
            throw ValidationException::withMessages(['excel' => 'File Excel kosong. Gunakan template dari halaman ini.']);
        }

        return $rows;
    }

    /**
     * @param  array<int, mixed>  $cells
     * @return array<int, string> Indeks kolom => kunci internal.
     */
    private function mapHeader(array $cells): array
    {
        $known = collect(self::COLUMNS)->mapWithKeys(fn (string $label, string $key): array => [$this->matchKey($label) => $key]);
        $header = [];
        foreach ($cells as $index => $value) {
            $key = $known->get($this->matchKey(str_replace('*', '', (string) $this->text($value))));
            if ($key !== null && ! in_array($key, $header, true)) {
                $header[$index] = $key;
            }
        }

        $missing = collect(self::REQUIRED_COLUMNS)->reject(fn (string $key): bool => in_array($key, $header, true))
            ->map(fn (string $key): string => self::COLUMNS[$key]);
        if ($missing->isNotEmpty()) {
            throw ValidationException::withMessages(['excel' => 'Kolom wajib tidak ditemukan: '.$missing->implode(', ').'. Gunakan template dari halaman ini dan jangan ubah judul kolom.']);
        }

        return $header;
    }

    /**
     * Daftar file dari semua ZIP, dikunci dengan nama file (tanpa folder, huruf kecil). Hanya file yang disebut di Excel
     * yang diekstrak, dengan nama acak di folder sementara, sehingga path di dalam ZIP tidak pernah dipakai (zip-slip).
     *
     * @param  list<array{path: string, name: string}>  $zips
     * @param  list<string>  $referenced
     * @return array<string, array{name: string, zip: int, index: int, size: int, duplicate: bool, too_large: bool, unreadable: bool, path: ?string}>
     */
    private function extractZips(array $zips, string $directory, array $referenced): array
    {
        $maxEntries = (int) config('lms.import_max_rows');
        $maxBytes = (int) config('lms.import_max_zip_kb') * 1024;
        $maxFileBytes = (int) config('lms.max_upload_size_kb') * 1024;
        $entries = [];
        $count = 0;
        $total = 0;

        foreach ($zips as $zipIndex => $zipFile) {
            $zip = $this->openZip($zipFile);
            try {
                if ($zip->numFiles > $maxEntries * 5) {
                    throw ValidationException::withMessages(['zips' => "ZIP \"{$zipFile['name']}\" berisi terlalu banyak entri. Satu impor maksimal {$maxEntries} file."]);
                }
                for ($index = 0; $index < $zip->numFiles; $index++) {
                    $stat = $zip->statIndex($index);
                    $name = $stat === false ? null : $this->zipEntryBaseName((string) $stat['name']);
                    if ($name === null) {
                        continue;
                    }
                    if (++$count > $maxEntries) {
                        throw ValidationException::withMessages(['zips' => "Isi ZIP lebih dari {$maxEntries} file. Satu impor maksimal {$maxEntries} file; bagi ZIP sesuai batch Excel."]);
                    }
                    $total += (int) $stat['size'];
                    if ($total > $maxBytes) {
                        throw ValidationException::withMessages(['zips' => 'Total isi ZIP setelah diekstrak melebihi '.self::megabytes((int) config('lms.import_max_zip_kb')).' MB. Bagi menjadi beberapa batch.']);
                    }
                    $key = Str::lower($name);
                    if (isset($entries[$key])) {
                        $entries[$key]['duplicate'] = true;

                        continue;
                    }
                    $entries[$key] = [
                        'name' => $name, 'zip' => $zipIndex, 'index' => $index, 'size' => (int) $stat['size'],
                        'duplicate' => false, 'too_large' => false, 'unreadable' => false, 'path' => null,
                    ];
                }
            } finally {
                $zip->close();
            }
        }

        $disk = Storage::disk(self::DISK);
        $sequence = 0;
        foreach ($zips as $zipIndex => $zipFile) {
            $wanted = array_filter($entries, fn (array $entry, string $key): bool => $entry['zip'] === $zipIndex && ! $entry['duplicate']
                && in_array($key, $referenced, true), ARRAY_FILTER_USE_BOTH);
            if ($wanted === []) {
                continue;
            }
            $zip = $this->openZip($zipFile);
            try {
                foreach ($wanted as $key => $entry) {
                    if ($entry['size'] > $maxFileBytes) {
                        $entries[$key]['too_large'] = true;

                        continue;
                    }
                    $extension = Str::limit((string) preg_replace('/[^a-z0-9]/', '', Str::lower(pathinfo($entry['name'], PATHINFO_EXTENSION))), 10, '');
                    $relative = $directory.'/files/'.(++$sequence).($extension !== '' ? '.'.$extension : '');
                    $copied = $this->copyEntry($zip, $entry['index'], $disk->path($relative), $maxFileBytes);
                    if ($copied === null || ($copied === 0 && $entry['size'] > 0)) {
                        $entries[$key]['unreadable'] = true;
                        $disk->delete($relative);
                    } elseif ($copied > $maxFileBytes) {
                        $entries[$key]['too_large'] = true;
                        $disk->delete($relative);
                    } else {
                        $entries[$key]['path'] = $relative;
                    }
                }
            } finally {
                $zip->close();
            }
        }

        return $entries;
    }

    /** @param array{path: string, name: string} $zipFile */
    private function openZip(array $zipFile): ZipArchive
    {
        $zip = new ZipArchive;
        if ($zip->open($zipFile['path'], ZipArchive::RDONLY) !== true) {
            throw ValidationException::withMessages(['zips' => "File \"{$zipFile['name']}\" bukan ZIP yang valid."]);
        }

        return $zip;
    }

    /** Salin isi entri ZIP paling banyak $limit + 1 byte; null bila entri tidak bisa dibaca (rusak atau berkata sandi). */
    private function copyEntry(ZipArchive $zip, int $index, string $target, int $limit): ?int
    {
        $input = $zip->getStreamIndex($index);
        if ($input === false) {
            return null;
        }
        $output = fopen($target, 'wb');
        try {
            $copied = @stream_copy_to_stream($input, $output, $limit + 1);
        } finally {
            fclose($input);
            fclose($output);
        }

        return $copied === false ? null : $copied;
    }

    /** Nama file entri ZIP tanpa folder; null untuk folder, isi __MACOSX, dan file tersembunyi atau sistem. */
    private function zipEntryBaseName(string $name): ?string
    {
        $name = str_replace('\\', '/', $name);
        if ($name === '' || str_ends_with($name, '/')) {
            return null;
        }
        $segments = explode('/', $name);
        if (collect($segments)->contains(fn (string $segment): bool => Str::lower($segment) === '__macosx')) {
            return null;
        }
        $base = (string) end($segments);
        if ($base === '' || str_starts_with($base, '.') || in_array(Str::lower($base), ['thumbs.db', 'desktop.ini'], true)) {
            return null;
        }

        return $base;
    }

    /**
     * @param  list<array{line: int, values: array<string, mixed>}>  $rows
     * @param  array<string, array<string, mixed>>  $files
     * @return list<array<string, mixed>>
     */
    private function evaluateRows(array $rows, array $files): array
    {
        $lookups = $this->lookups();
        $seen = [];
        $evaluated = [];
        foreach ($rows as $row) {
            $evaluated[] = $this->evaluateRow($row, $lookups, $files, $seen);
        }

        $sharedFiles = collect($evaluated)->filter(fn (array $row): bool => $row['status'] === 'ready' && $row['file_key'] !== null)
            ->groupBy('file_key')->filter(fn ($group): bool => $group->count() > 1);
        foreach ($evaluated as $index => $row) {
            $group = $row['status'] === 'ready' && $row['file_key'] !== null ? $sharedFiles->get($row['file_key']) : null;
            if ($group !== null) {
                $evaluated[$index]['messages'][] = "Nama File \"{$row['values']['file_name']}\" dipakai di lebih dari satu baris (baris ".$group->pluck('line')->implode(', ').'). Setiap dokumen harus punya file sendiri.';
                $evaluated[$index]['status'] = $evaluated[$index]['check'] = 'error';
            }
            unset($evaluated[$index]['file_key']);
        }

        return $evaluated;
    }

    /** @return array{companies: array<string, array<int, Company>>, types: array<string, array<int, DocumentType>>, users: array<string, User>} */
    private function lookups(): array
    {
        $companies = [];
        foreach (Company::all() as $company) {
            foreach ([$company->name, StorageFileName::companyName($company)] as $label) {
                $companies[$this->matchKey($label)][$company->id] = $company;
            }
        }
        $types = [];
        foreach (DocumentType::all() as $type) {
            $types[$this->matchKey($type->name)][$type->id] = $type;
        }
        $users = User::query()->get(['id', 'name', 'email', 'deactivated_at'])->keyBy(fn (User $user): string => Str::lower(trim($user->email)))->all();

        return ['companies' => $companies, 'types' => $types, 'users' => $users];
    }

    /**
     * @param  array{line: int, values: array<string, mixed>}  $row
     * @param  array{companies: array<string, array<int, Company>>, types: array<string, array<int, DocumentType>>, users: array<string, User>}  $lookups
     * @param  array<string, array<string, mixed>>  $files
     * @param  array<string, int>  $seen
     * @return array<string, mixed>
     */
    private function evaluateRow(array $row, array $lookups, array $files, array &$seen): array
    {
        $raw = $row['values'];
        $values = [];
        foreach (array_keys(self::COLUMNS) as $key) {
            $values[$key] = $this->text($raw[$key] ?? null);
        }
        $errors = [];
        $notes = [];

        foreach (['title' => 255, 'document_number' => 255, 'counterparty' => 255, 'notes' => 10000] as $key => $max) {
            if ($values[$key] !== null && mb_strlen($values[$key]) > $max) {
                $errors[] = self::COLUMNS[$key].' maksimal '.number_format($max, 0, ',', '.').' karakter.';
            }
        }
        if ($values['title'] === null) {
            $errors[] = 'Judul wajib diisi.';
        }

        $company = $this->resolve($lookups['companies'], $values['company'], 'Badan Usaha', $errors);
        $type = $this->resolve($lookups['types'], $values['document_type'], 'Jenis Dokumen', $errors);

        $pic = null;
        if ($values['pic_email'] === null) {
            $errors[] = 'Email PIC wajib diisi.';
        } else {
            $pic = $lookups['users'][Str::lower($values['pic_email'])] ?? null;
            if ($pic === null) {
                $errors[] = "Email PIC \"{$values['pic_email']}\" tidak terdaftar sebagai user.";
            } elseif (! $pic->isActive()) {
                $errors[] = "PIC \"{$values['pic_email']}\" sudah nonaktif. Pilih user aktif.";
                $pic = null;
            }
        }

        $issued = $this->date($raw['issued_date'] ?? null, 'Tanggal Terbit', $values, 'issued_date', $errors);
        $expiry = $this->date($raw['expiry_date'] ?? null, 'Tanggal Berakhir', $values, 'expiry_date', $errors);
        if ($type !== null && $type->has_expiry && $expiry === null && ! isset($errors['expiry_date'])) {
            $errors[] = "Tanggal Berakhir wajib diisi karena jenis dokumen \"{$type->name}\" memiliki masa berlaku.";
        }
        if ($type !== null && ! $type->has_expiry && $expiry !== null) {
            $notes[] = "Tanggal Berakhir diabaikan karena jenis dokumen \"{$type->name}\" tidak memiliki masa berlaku.";
            $expiry = null;
        }
        if ($issued !== null && $expiry !== null && $expiry < $issued) {
            $errors[] = 'Tanggal Berakhir tidak boleh sebelum Tanggal Terbit.';
        }

        [$file, $fileKey] = $this->resolveFile($values['file_name'], $files, $errors);

        $skipMessage = null;
        if ($company !== null && $type !== null && ($values['document_number'] !== null || $values['title'] !== null)) {
            $duplicateKey = $company->id.'|'.$type->id.'|'.($values['document_number'] !== null ? 'n:'.Str::lower($values['document_number']) : 't:'.Str::lower($values['title']));
            $existing = $this->findExisting($company->id, $type->id, $values['document_number'], $values['title']);
            if ($existing !== null) {
                $skipMessage = "Sudah ada di arsip: \"{$existing->title}\" (ID {$existing->id})".($existing->trashed() ? ', berada di dokumen terhapus.' : '.');
            } elseif (isset($seen[$duplicateKey])) {
                $skipMessage = "Sama dengan baris {$seen[$duplicateKey]} di file ini.";
            } elseif ($errors === []) {
                // Hanya baris yang akan diimpor yang dicatat, agar baris perbaikan setelah baris bermasalah tetap diimpor.
                $seen[$duplicateKey] = $row['line'];
            }
        }

        $status = $skipMessage !== null ? 'skipped' : ($errors !== [] ? 'error' : 'ready');

        return [
            'line' => $row['line'],
            'values' => $values,
            'data' => [
                'company_id' => $company?->id,
                'document_type_id' => $type?->id,
                'pic_user_id' => $pic?->id,
                'title' => $values['title'],
                'document_number' => $values['document_number'],
                'counterparty' => $values['counterparty'],
                'notes' => $values['notes'],
                'issued_date' => $issued,
                'expiry_date' => $expiry,
            ],
            'file' => $file,
            'file_key' => $fileKey,
            'status' => $status,
            'check' => $status,
            'messages' => $skipMessage !== null ? [$skipMessage] : array_values([...$errors, ...$notes]),
            'document_id' => null,
        ];
    }

    /**
     * Cocokkan nama (tanpa peduli huruf besar-kecil) dengan master data aktif.
     *
     * @param  array<string, array<int, Company|DocumentType>>  $index
     * @param  array<int|string, string>  $errors
     */
    private function resolve(array $index, ?string $value, string $label, array &$errors): Company|DocumentType|null
    {
        if ($value === null) {
            $errors[] = "{$label} wajib diisi.";

            return null;
        }
        $matches = $index[$this->matchKey($value)] ?? [];
        $active = array_filter($matches, fn (Company|DocumentType $model): bool => (bool) $model->is_active);
        if ($matches === []) {
            $errors[] = "{$label} \"{$value}\" tidak ditemukan. Lihat daftar di sheet Petunjuk pada template.";
        } elseif ($active === []) {
            $errors[] = "{$label} \"{$value}\" sudah nonaktif.";
        } elseif (count($active) > 1) {
            $errors[] = "{$label} \"{$value}\" cocok dengan lebih dari satu data. Tulis nama lengkapnya seperti di sheet Petunjuk.";
        } else {
            return reset($active);
        }

        return null;
    }

    /**
     * @param  array<string, array<string, mixed>>  $files
     * @param  array<int|string, string>  $errors
     * @return array{0: array{stored: string, name: string}|null, 1: string|null}
     */
    private function resolveFile(?string $fileName, array $files, array &$errors): array
    {
        $name = $this->baseName($fileName);
        if ($name === null) {
            $errors[] = 'Nama File wajib diisi.';

            return [null, null];
        }
        $key = Str::lower($name);
        $extension = Str::lower(pathinfo($name, PATHINFO_EXTENSION));
        $allowed = config('lms.allowed_extensions');
        $allowedLabel = Str::upper(implode(', ', array_slice($allowed, 0, -1))).', atau '.Str::upper(end($allowed));
        if (! in_array($extension, $allowed, true)) {
            $errors[] = $extension === ''
                ? "Nama File \"{$name}\" harus berisi ekstensi, misalnya Kontrak-001.pdf."
                : "Jenis file .{$extension} tidak diizinkan. Gunakan {$allowedLabel}.";

            return [null, $key];
        }
        $entry = $files[$key] ?? null;
        $error = match (true) {
            $entry === null => "File \"{$name}\" tidak ditemukan di ZIP.",
            $entry['duplicate'] => "Ada lebih dari satu file bernama \"{$name}\" di ZIP. Ganti nama salah satunya.",
            $entry['too_large'] => "Ukuran file \"{$name}\" melebihi ".self::megabytes((int) config('lms.max_upload_size_kb')).' MB.',
            $entry['unreadable'] || $entry['path'] === null => "File \"{$name}\" di ZIP tidak bisa dibaca (rusak atau terkunci kata sandi).",
            default => null,
        };
        if ($error === null && ! $this->hasAllowedContent($entry['path'], $entry['name'])) {
            $error = "Isi file \"{$name}\" tidak sesuai jenisnya atau rusak. Gunakan {$allowedLabel}.";
        }
        if ($error !== null) {
            $errors[] = $error;

            return [null, $key];
        }

        return [['stored' => $entry['path'], 'name' => $entry['name']], $key];
    }

    /** Pemeriksaan isi file yang sama dengan Document::archive(): jenis dari isi file, ekstensi, dan ukuran. */
    private function hasAllowedContent(string $relativePath, string $name): bool
    {
        $allowed = config('lms.allowed_extensions');

        return Validator::make(['file' => $this->uploadedFile($relativePath, $name)], [
            'file' => ['required', FileRule::types($allowed)->max(config('lms.max_upload_size_kb')), 'extensions:'.implode(',', $allowed)],
        ])->passes();
    }

    /**
     * Dokumen dengan badan usaha + jenis + nomor yang sama, atau judul yang sama bila nomor kosong. Termasuk dokumen terhapus,
     * supaya dokumen yang sengaja dihapus tidak muncul lagi saat batch yang sama diimpor ulang.
     */
    private function findExisting(int $companyId, int $typeId, ?string $number, ?string $title): ?Document
    {
        return Document::withTrashed()->where('company_id', $companyId)->where('document_type_id', $typeId)
            ->when($number !== null,
                fn (Builder $query): Builder => $query->whereRaw('LOWER(TRIM(document_number)) = ?', [Str::lower($number)]),
                fn (Builder $query): Builder => $query->where(fn (Builder $query): Builder => $query->whereNull('document_number')->orWhere('document_number', ''))
                    ->whereRaw('LOWER(TRIM(title)) = ?', [Str::lower((string) $title)]))
            ->orderBy('id')->first();
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function importRow(DocumentImport $import, array $row, User $user): array
    {
        $data = $row['data'];
        $existing = $this->findExisting($data['company_id'], $data['document_type_id'], $data['document_number'], $data['title']);
        if ($existing !== null) {
            return [...$row, 'status' => 'skipped', 'messages' => ["Sudah ada di arsip: \"{$existing->title}\" (ID {$existing->id})."]];
        }

        $path = Storage::disk(self::DISK)->path($row['file']['stored']);
        if (! is_file($path)) {
            return [...$row, 'status' => 'failed', 'messages' => ['File sementara sudah tidak ada. Unggah ulang Excel dan ZIP untuk baris ini.']];
        }

        try {
            $document = Document::archive([...$data, 'change_note' => $import->changeNote()], $this->uploadedFile($row['file']['stored'], $row['file']['name']), $user);
        } catch (ValidationException $exception) {
            $messages = collect(array_keys($exception->errors()))
                ->map(fn (string $field): string => self::ARCHIVE_FIELD_MESSAGES[$field] ?? 'File tidak diizinkan: gunakan PDF, DOC, DOCX, JPG, atau PNG maksimal '.self::megabytes((int) config('lms.max_upload_size_kb')).' MB.')
                ->unique()->values()->all();

            return [...$row, 'status' => 'failed', 'messages' => $messages];
        } catch (ModelNotFoundException) {
            return [...$row, 'status' => 'failed', 'messages' => [self::ARCHIVE_FIELD_MESSAGES['document_type_id']]];
        } catch (Throwable $exception) {
            report($exception);

            return [...$row, 'status' => 'failed', 'messages' => ['File gagal disimpan ke penyimpanan. Coba impor ulang baris ini nanti.']];
        }

        @unlink($path);
        $messages = [];
        if ($import->skip_past_reminders) {
            try {
                $this->skipPastReminders($document);
            } catch (Throwable $exception) {
                report($exception);
                $messages[] = 'Dokumen tersimpan, tetapi pengingat yang jadwalnya sudah lewat gagal dilewati.';
            }
        }

        return [...$row, 'status' => 'imported', 'document_id' => $document->id, 'messages' => $messages];
    }

    /**
     * Tandai jadwal pengingat yang sudah tercapai saat impor sebagai dibatalkan, sehingga pengiriman catch-up tidak
     * mengirim pesan untuk dokumen lama. Log unik per versi dan jadwal mencegah pengiriman; jadwal berikutnya tetap jalan.
     */
    private function skipPastReminders(Document $document): void
    {
        $document->loadMissing(['currentVersion', 'documentType']);
        if (! $document->documentType?->has_expiry || $document->currentVersion?->expiry_date === null) {
            return;
        }
        $offset = $this->reminders->dueOffset($document, CarbonImmutable::today(config('lms.reminder_timezone')));
        if ($offset === null) {
            return;
        }

        ReminderLog::firstOrCreate(
            ['document_version_id' => $document->current_version_id, 'offset_days' => $offset],
            ['status' => 'cancelled', 'error_message' => self::SKIPPED_REMINDER_MESSAGE],
        );
    }

    /** File hasil ekstrak dibungkus sebagai UploadedFile mode test agar lolos isValid() tanpa melalui unggahan HTTP. */
    private function uploadedFile(string $relativePath, string $name): UploadedFile
    {
        return new UploadedFile(Storage::disk(self::DISK)->path($relativePath), $name, null, null, true);
    }

    /**
     * @param  array<string, ?string>  $values
     * @param  array<int|string, string>  $errors
     */
    private function date(mixed $raw, string $label, array &$values, string $key, array &$errors): ?string
    {
        if ($raw instanceof DateTimeInterface) {
            $values[$key] = $raw->format('d/m/Y');

            return $raw->format('Y-m-d');
        }
        $text = $this->text($raw);
        if ($text === null) {
            return null;
        }
        if (preg_match('/^(\d{1,2})[\/.-](\d{1,2})[\/.-](\d{4})$/', $text, $match) && checkdate((int) $match[2], (int) $match[1], (int) $match[3])) {
            $values[$key] = sprintf('%02d/%02d/%04d', $match[1], $match[2], $match[3]);

            return sprintf('%04d-%02d-%02d', $match[3], $match[2], $match[1]);
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $text, $match) && checkdate((int) $match[2], (int) $match[3], (int) $match[1])) {
            $values[$key] = sprintf('%02d/%02d/%04d', $match[3], $match[2], $match[1]);

            return $text;
        }
        $errors[$key] = "{$label} \"{$text}\" tidak valid. Gunakan format dd/mm/yyyy, misalnya 31/12/2026.";

        return null;
    }

    /** Isi sel sebagai teks yang sudah dirapikan; null bila kosong. Angka bulat (mis. nomor dokumen) tanpa ".0". */
    private function text(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('d/m/Y');
        }
        if (is_float($value) && floor($value) === $value && abs($value) < 1e15) {
            $value = (int) $value;
        }
        if (is_bool($value)) {
            $value = $value ? 'TRUE' : 'FALSE';
        }
        if (! is_scalar($value)) {
            return null;
        }
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private function baseName(?string $fileName): ?string
    {
        if ($fileName === null) {
            return null;
        }
        $name = trim(basename(str_replace('\\', '/', $fileName)));

        return $name === '' ? null : $name;
    }

    private function matchKey(?string $value): string
    {
        return Str::lower(Str::squish((string) $value));
    }
}
