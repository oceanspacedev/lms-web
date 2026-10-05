<?php

namespace App\Models;

use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
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

        return $this->versions()->create([
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
    }
}
