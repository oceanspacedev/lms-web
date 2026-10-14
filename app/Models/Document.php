<?php

namespace App\Models;

use App\Services\StorageFileName;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use Throwable;

#[Fillable(['company_id', 'document_type_id', 'document_number', 'title', 'counterparty', 'pic_user_id', 'notes'])]
class Document extends Model
{
    public const EXPIRY_STATUSES = [
        'active' => 'Aktif',
        'expiring' => 'Segera Berakhir',
        'expired' => 'Kedaluwarsa',
        'no_expiry' => 'Tanpa Masa Berlaku',
    ];

    public const EXPIRY_STATUS_COLORS = [
        'active' => 'success', 'expiring' => 'warning', 'expired' => 'danger', 'no_expiry' => 'gray',
    ];

    /** Pengganti label status masa berlaku untuk dokumen yang sudah dihapus (daftar, grid, dan ekspor Excel). */
    public const DELETED_STATUS_LABEL = 'Dihapus';

    /** Tipe file yang bisa dibuka inline (pratinjau) oleh browser. */
    public const PREVIEWABLE_MIME_TYPES = ['application/pdf', 'image/jpeg', 'image/png'];

    /** Jendela "Segera Berakhir" (hari) bila jadwal pengingat kosong atau nonaktif; sama dengan widget "Berakhir dalam 30 Hari". */
    public const DEFAULT_EXPIRING_DAYS = 30;

    /**
     * Berapa hari sebelum berakhir dokumen berstatus "Segera Berakhir": hari terjauh pada jadwal pengingat,
     * atau DEFAULT_EXPIRING_DAYS bila jenis dokumen tidak punya hari pengingat sebelum tanggal berakhir.
     */
    public static function expiringWindowDays(DocumentType $type): int
    {
        $furthestReminder = max([0, ...$type->effectiveReminderDays()]);

        return $furthestReminder > 0 ? $furthestReminder : self::DEFAULT_EXPIRING_DAYS;
    }

    /** @use HasFactory<DocumentFactory> */
    use HasFactory, SoftDeletes;

    protected static function booted(): void
    {
        static::restored(function (Document $document): void {
            ReminderLog::reopenForRestoredDocument($document);
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function pic(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pic_user_id');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'current_version_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(DocumentActivity::class);
    }

    protected function expiryStatus(): Attribute
    {
        return Attribute::get(function (): string {
            $expiryDate = $this->currentVersion?->expiry_date;
            if (! $this->documentType?->has_expiry || $expiryDate === null) {
                return 'no_expiry';
            }

            $today = today(config('lms.reminder_timezone'));
            $expiryDate = CarbonImmutable::parse($expiryDate->toDateString(), config('lms.reminder_timezone'));
            if ($expiryDate->lt($today)) {
                return 'expired';
            }

            return $expiryDate->lte($today->addDays(self::expiringWindowDays($this->documentType))) ? 'expiring' : 'active';
        });
    }

    /** Keterangan waktu penghapusan dalam zona waktu aplikasi, mis. "Dihapus pada 05/10/2026 22:00 WIB"; null bila belum dihapus. */
    public function deletedAtLabel(): ?string
    {
        if ($this->deleted_at === null) {
            return null;
        }

        $timezone = config('lms.reminder_timezone');

        return 'Dihapus pada '.$this->deleted_at->copy()->timezone($timezone)->format('d/m/Y H:i').' '.($timezone === 'Asia/Jakarta' ? 'WIB' : $timezone);
    }

    public function scopeWithExpiryStatus(Builder $query, string $status): Builder
    {
        if ($status === 'no_expiry') {
            return $query->where(function (Builder $query): void {
                $query->whereHas('documentType', fn (Builder $type): Builder => $type->where('has_expiry', false))
                    ->orWhereDoesntHave('currentVersion')
                    ->orWhereHas('currentVersion', fn (Builder $version): Builder => $version->whereNull('expiry_date'));
            });
        }

        $query->whereHas('documentType', fn (Builder $type): Builder => $type->where('has_expiry', true));
        if ($status === 'expired') {
            return $query->whereHas('currentVersion', fn (Builder $version): Builder => $version->whereDate('expiry_date', '<', today(config('lms.reminder_timezone'))->toDateString()));
        }

        if (! in_array($status, ['active', 'expiring'], true)) {
            return $query->whereRaw('1 = 0');
        }

        $today = CarbonImmutable::today(config('lms.reminder_timezone'));
        $typesByWindow = DocumentType::query()->where('has_expiry', true)->get()
            ->groupBy(fn (DocumentType $type): int => self::expiringWindowDays($type));

        return $query->where(function (Builder $query) use ($status, $typesByWindow, $today): void {
            $query->whereRaw('1 = 0');
            foreach ($typesByWindow as $window => $types) {
                $cutoff = $today->addDays((int) $window)->toDateString();
                $query->orWhere(function (Builder $query) use ($status, $types, $today, $cutoff): void {
                    $query->whereIn('document_type_id', $types->modelKeys())
                        ->whereHas('currentVersion', fn (Builder $version): Builder => $status === 'expiring'
                            ? $version->whereDate('expiry_date', '>=', $today->toDateString())->whereDate('expiry_date', '<=', $cutoff)
                            : $version->whereDate('expiry_date', '>', $cutoff));
                });
            }
        });
    }

    public function scopeExpiringWithin(Builder $query, int $days): Builder
    {
        return $this->scopeExpiringBetween($query, 0, $days);
    }

    /** Dokumen yang berakhir antara $fromDays dan $toDays hari dari hari ini, kedua ujung termasuk. */
    public function scopeExpiringBetween(Builder $query, int $fromDays, int $toDays): Builder
    {
        return $query->whereHas('documentType', fn (Builder $type): Builder => $type->where('has_expiry', true))
            ->whereHas('currentVersion', fn (Builder $version): Builder => $version
                ->whereDate('expiry_date', '>=', today(config('lms.reminder_timezone'))->addDays($fromDays)->toDateString())
                ->whereDate('expiry_date', '<=', today(config('lms.reminder_timezone'))->addDays($toDays)->toDateString()));
    }

    /**
     * Saran isian form perpanjangan: masa baru dimulai saat versi aktif berakhir (atau hari ini bila sudah lewat)
     * dan berlaku selama masa versi aktif, atau setahun bila lama masa tidak diketahui.
     *
     * @return array{issued_date: string, expiry_date: string, change_note: string}
     */
    public function renewalSuggestion(): array
    {
        $timezone = config('lms.reminder_timezone');
        $today = CarbonImmutable::today($timezone);
        $current = $this->currentVersion;
        $oldExpiry = CarbonImmutable::parse($current->expiry_date->toDateString(), $timezone);
        $oldIssued = $current->issued_date ? CarbonImmutable::parse($current->issued_date->toDateString(), $timezone) : null;
        $issued = $oldExpiry->gt($today) ? $oldExpiry : $today;

        if ($oldIssued === null || $oldIssued->gte($oldExpiry)) {
            $expiry = $issued->addYearNoOverflow();
        } else {
            $months = (int) round(abs($oldIssued->diffInMonths($oldExpiry)));
            $expiry = $months >= 1 && abs($oldIssued->addMonthsNoOverflow($months)->diffInDays($oldExpiry)) <= 1
                ? $issued->addMonthsNoOverflow($months)
                : $issued->addDays((int) abs($oldIssued->diffInDays($oldExpiry)));
        }

        return [
            'issued_date' => $issued->toDateString(),
            'expiry_date' => $expiry->toDateString(),
            'change_note' => 'Perpanjangan masa berlaku dokumen.',
        ];
    }

    /** @param array<string, mixed> $data */
    public static function archive(array $data, UploadedFile $file, User $uploader): self
    {
        $documentType = DocumentType::findOrFail($data['document_type_id'] ?? null);
        $data['expiry_date'] = $documentType->has_expiry ? ($data['expiry_date'] ?? null) : null;
        $validated = Validator::make([...$data, 'file' => $file], [
            'company_id' => ['required', Rule::exists('companies', 'id')->where('is_active', true)],
            'document_type_id' => ['required', Rule::exists('document_types', 'id')->where('is_active', true)],
            'title' => ['required', 'string', 'max:255'],
            'document_number' => ['nullable', 'string', 'max:255'],
            'counterparty' => ['nullable', 'string', 'max:255'],
            'pic_user_id' => ['required', 'exists:users,id,deactivated_at,NULL'],
            'notes' => ['nullable', 'string', 'max:10000'],
            'change_note' => ['nullable', 'string', 'max:10000'],
            ...self::versionValidationRules($documentType->has_expiry, $data['issued_date'] ?? null),
        ])->validate();

        $path = null;

        try {
            return DB::transaction(function () use ($validated, $file, $uploader, &$path): self {
                $document = self::create(Arr::only($validated, [
                    'company_id', 'document_type_id', 'document_number', 'title', 'counterparty', 'pic_user_id', 'notes',
                ]));
                $version = $document->storeVersion($validated, $file, $uploader, 1, $path);
                $document->current_version_id = $version->id;
                $document->save();

                return $document;
            });
        } catch (Throwable $exception) {
            if ($path !== null) {
                try {
                    Storage::disk(self::storageDisk())->delete($path);
                } catch (Throwable $cleanupException) {
                    report($cleanupException);
                }
            }

            throw $exception;
        }
    }

    /** @param array<string, mixed> $data */
    public function appendVersion(array $data, UploadedFile $file, User $uploader): DocumentVersion
    {
        $path = null;

        try {
            $version = DB::transaction(function () use ($data, $file, $uploader, &$path): DocumentVersion {
                $document = self::whereKey($this->getKey())->lockForUpdate()->firstOrFail();
                $type = $document->documentType()->firstOrFail();
                $data['expiry_date'] = $type->has_expiry ? ($data['expiry_date'] ?? null) : null;
                $validated = Validator::make([...$data, 'file' => $file], [
                    ...self::versionValidationRules($type->has_expiry, $data['issued_date'] ?? null),
                    'change_note' => ['required', 'string', 'max:10000'],
                ])->validate();
                $document->versions()->where('is_current', true)->update(['is_current' => false]);
                $number = (int) $document->versions()->max('version_number') + 1;
                $version = $document->storeVersion($validated, $file, $uploader, $number, $path);
                $document->current_version_id = $version->id;
                $document->save();

                return $version;
            });
        } catch (Throwable $exception) {
            if ($path !== null) {
                try {
                    Storage::disk(self::storageDisk())->delete($path);
                } catch (Throwable $cleanupException) {
                    report($cleanupException);
                }
            }

            throw $exception;
        }

        $this->refresh();

        return $version;
    }

    /** @return array<string, array<mixed>> */
    private static function versionValidationRules(bool $hasExpiry, ?string $issuedDate): array
    {
        return [
            'issued_date' => ['nullable', 'date'],
            'expiry_date' => [Rule::requiredIf($hasExpiry), 'nullable', 'date', ...(! empty($issuedDate) ? ['after_or_equal:issued_date'] : [])],
            'file' => ['required', File::types(config('lms.allowed_extensions'))->max(config('lms.max_upload_size_kb')), 'extensions:'.implode(',', config('lms.allowed_extensions'))],
        ];
    }

    /**
     * Disk untuk file versi baru, mengikuti FILESYSTEM_DISK. Versi lama tetap dibaca dari disk yang tercatat padanya.
     */
    public static function storageDisk(): string
    {
        return config('filesystems.default');
    }

    /**
     * Path file: folder per jenis dokumen, nama file berisi badan usaha, jenis, nomor, judul, ID dan versi, serta waktu upload.
     */
    public function storagePath(int $versionNumber, string $extension, ?CarbonInterface $uploadedAt = null): string
    {
        $type = $this->documentType()->value('name');
        $filename = StorageFileName::build([
            StorageFileName::companyName(Company::find($this->company_id)),
            $type,
            $this->document_number,
            $this->title,
            "dok{$this->id}-v{$versionNumber}",
        ], $uploadedAt ?? now(), $extension);

        return StorageFileName::folder($type).'/'.$filename;
    }

    /** @param array<string, mixed> $data */
    private function storeVersion(array $data, UploadedFile $file, User $uploader, int $number, ?string &$path): DocumentVersion
    {
        $uploadedAt = now()->startOfSecond();
        $path = $this->storagePath($number, $file->getClientOriginalExtension(), $uploadedAt);
        $directory = dirname($path);
        $filename = basename($path);

        $disk = self::storageDisk();

        if ($file->storeAs($directory, $filename, ['disk' => $disk, 'visibility' => config("filesystems.disks.{$disk}.visibility", 'private')]) === false) {
            throw new \RuntimeException('Penyimpanan file gagal.');
        }

        $version = $this->versions()->make([
            'version_number' => $number,
            'disk' => $disk,
            'file_path' => $path,
            'file_name' => basename(str_replace('\\', '/', $file->getClientOriginalName())),
            'file_size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
            'issued_date' => $data['issued_date'] ?? null,
            'expiry_date' => $data['expiry_date'] ?? null,
            'is_current' => true,
            'change_note' => $data['change_note'] ?? null,
            'uploaded_by' => $uploader->id,
        ]);
        $version->created_at = $uploadedAt;
        $version->save();

        $this->activities()->create([
            'document_version_id' => $version->id,
            'user_id' => $uploader->id,
            'event' => $number === 1 ? 'uploaded' : 'version_updated',
        ]);

        return $version;
    }
}
