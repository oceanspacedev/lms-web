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
            'issued_date' => ['nullable', 'date'],
            'expiry_date' => [Rule::requiredIf($documentType->has_expiry), 'nullable', 'date', ...(! empty($data['issued_date']) ? ['after_or_equal:issued_date'] : [])],
            'file' => ['required', File::types(config('lms.allowed_extensions'))->max(config('lms.max_upload_size_kb')), 'extensions:'.implode(',', config('lms.allowed_extensions'))],
        ])->validate();

        $path = null;

        try {
            return DB::transaction(function () use ($validated, $file, $uploader, &$path): self {
                $document = self::create(Arr::only($validated, [
                    'company_id', 'document_type_id', 'document_number', 'title', 'counterparty', 'pic_user_id', 'notes',
                ]));
                $directory = "lms/documents/{$document->company_id}/{$document->id}/v1";
                $filename = Str::uuid().'.'.strtolower($file->getClientOriginalExtension());
                $path = $directory.'/'.$filename;

                if ($file->storeAs($directory, $filename, ['disk' => 's3', 'visibility' => 'private']) === false) {
                    throw new \RuntimeException('Penyimpanan file gagal.');
                }

                $version = $document->versions()->create([
                    'version_number' => 1,
                    'file_path' => $path,
                    'file_name' => basename(str_replace('\\', '/', $file->getClientOriginalName())),
                    'file_size' => $file->getSize(),
                    'mime_type' => $file->getMimeType(),
                    'issued_date' => $validated['issued_date'] ?? null,
                    'expiry_date' => $validated['expiry_date'] ?? null,
                    'is_current' => true,
                    'uploaded_by' => $uploader->id,
                ]);
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
}
