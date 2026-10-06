<?php

namespace App\Models;

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
use Illuminate\Support\Str;
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
        'no_expiry' => 'Tanpa Masa Tenggang',
    ];

    public const EXPIRY_STATUS_COLORS = [
        'active' => 'success', 'expiring' => 'warning', 'expired' => 'danger', 'no_expiry' => 'gray',
    ];

    /** @use HasFactory<DocumentFactory> */
    use HasFactory, SoftDeletes;

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

            $today = today();
            if ($expiryDate->lt($today)) {
                return 'expired';
            }

            $threshold = max([0, ...($this->documentType->reminder_days ?? [])]);

            return $threshold > 0 && $expiryDate->lte($today->addDays($threshold)) ? 'expiring' : 'active';
        });
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
            return $query->whereHas('currentVersion', fn (Builder $version): Builder => $version->whereDate('expiry_date', '<', today()->toDateString()));
        }

        if (! in_array($status, ['active', 'expiring'], true)) {
            return $query->whereRaw('1 = 0');
        }

        $typesByThreshold = DocumentType::query()->where('has_expiry', true)->get(['id', 'reminder_days'])
            ->groupBy(fn (DocumentType $type): int => max([0, ...($type->reminder_days ?? [])]));

        return $query->where(function (Builder $query) use ($status, $typesByThreshold): void {
            $query->whereRaw('1 = 0');
            foreach ($typesByThreshold as $threshold => $types) {
                $query->orWhere(function (Builder $query) use ($status, $threshold, $types): void {
                    $query->whereIn('document_type_id', $types->modelKeys())
                        ->whereHas('currentVersion', function (Builder $version) use ($status, $threshold): void {
                            $cutoff = today()->addDays((int) $threshold)->toDateString();
                            if ($status === 'expiring') {
                                if ((int) $threshold === 0) {
                                    $version->whereRaw('1 = 0');
                                } else {
                                    $version->whereDate('expiry_date', '>=', today()->toDateString())->whereDate('expiry_date', '<=', $cutoff);
                                }
                            } else {
                                $version->whereDate('expiry_date', (int) $threshold === 0 ? '>=' : '>', $cutoff);
                            }
                        });
                });
            }
        });
    }

    public function scopeExpiringWithin(Builder $query, int $days): Builder
    {
        return $query->whereHas('documentType', fn (Builder $type): Builder => $type->where('has_expiry', true))
            ->whereHas('currentVersion', fn (Builder $version): Builder => $version
                ->whereDate('expiry_date', '>=', today()->toDateString())
                ->whereDate('expiry_date', '<=', today()->addDays($days)->toDateString()));
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
            'pic_user_id' => ['required', 'exists:users,id'],
            'notes' => ['nullable', 'string', 'max:10000'],
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
                    Storage::disk('s3')->delete($path);
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
                    Storage::disk('s3')->delete($path);
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

    /** @param array<string, mixed> $data */
    private function storeVersion(array $data, UploadedFile $file, User $uploader, int $number, ?string &$path): DocumentVersion
    {
        $directory = "lms/documents/{$this->company_id}/{$this->id}/v{$number}";
        $filename = Str::uuid().'.'.strtolower($file->getClientOriginalExtension());
        $path = $directory.'/'.$filename;

        if ($file->storeAs($directory, $filename, ['disk' => 's3', 'visibility' => 'private']) === false) {
            throw new \RuntimeException('Penyimpanan file gagal.');
        }

        $version = $this->versions()->create([
            'version_number' => $number,
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

        $this->activities()->create([
            'document_version_id' => $version->id,
            'user_id' => $uploader->id,
            'event' => $number === 1 ? 'uploaded' : 'version_updated',
        ]);

        return $version;
    }
}
