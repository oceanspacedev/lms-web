<?php

namespace App\Models;

use App\Services\DocumentRequestNotifier;
use Database\Factories\DocumentRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\ValidationException;
use Throwable;

#[Fillable(['company_id', 'document_type_id', 'pic_user_id', 'title', 'purpose', 'partner_name', 'partner_pic', 'partner_contact', 'start_date', 'expiry_date', 'target_date', 'has_cost', 'amount', 'payment_terms', 'details'])]
class DocumentRequest extends Model
{
    /** @use HasFactory<DocumentRequestFactory> */
    use HasFactory;

    public const STATUSES = ['draft' => 'Draf', 'submitted' => 'Diperiksa', 'review' => 'Menunggu Persetujuan', 'revision' => 'Perlu Revisi', 'approved' => 'Menunggu Tanda Tangan', 'rejected' => 'Ditolak', 'archived' => 'Selesai'];

    protected $attributes = ['details' => '{}', 'attachments' => '{}', 'requirements' => '{}', 'history' => '[]', 'status' => 'draft'];

    protected function casts(): array
    {
        return ['details' => 'array', 'attachments' => 'array', 'requirements' => 'array', 'history' => 'array', 'has_cost' => 'boolean', 'start_date' => 'date', 'expiry_date' => 'date', 'target_date' => 'date', 'amount' => 'decimal:2'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function pic(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pic_user_id');
    }

    public function editableBy(User $user): bool
    {
        return $this->requester_id === $user->id && in_array($this->status, ['draft', 'revision'], true);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(DocumentRequestNotification::class);
    }

    public function applicantName(): string
    {
        return $this->requester_name ?? $this->requester?->name ?? 'Pengaju';
    }

    /** @param array<string, mixed> $data */
    public static function submitPublic(array $data, ?self $existing = null): self
    {
        $storedPaths = [];
        try {
            return DB::transaction(function () use ($data, $existing, &$storedPaths): self {
                $request = $existing ? self::whereKey($existing->id)->lockForUpdate()->firstOrFail() : new self;
                if ($existing) {
                    abort_unless($request->public_token && $request->status === 'revision', 409, 'Pengajuan sudah diproses.');
                    unset($data['company_id'], $data['document_type_id']);
                } else {
                    $type = DocumentType::where('is_active', true)->findOrFail($data['document_type_id']);
                    $pic = $type->resolveRequestPic();
                    if (! $pic) {
                        throw ValidationException::withMessages(['document_type_id' => 'Tim pemeriksa belum tersedia. Hubungi admin.']);
                    }
                    $request->pic_user_id = $pic->id;
                    $request->reviewer_id = $type->request_reviewer_id ?? $pic->id;
                    $request->approver_id = $type->request_approver_id;
                    $request->public_token = Str::random(64);
                    $request->requirements = $type->publicRequirements();
                }
                unset($data['pic_user_id']);
                $data['details'] = array_intersect_key($data['details'] ?? [], array_flip(array_column($request->requirements['fields'] ?? [], 'key')));
                $request->fill($data);
                $request->requester_name = $data['requester_name'];
                $request->requester_phone = $data['requester_phone'];
                $request->requester_division = $data['requester_division'];
                $request->request_reason = $data['request_reason'];
                Validator::make($request->attributesToArray(), ['company_id' => ['required', Rule::exists('companies', 'id')->where('is_active', true)]])->validate();
                $attachments = $request->attachments;
                foreach ($request->requirements['attachments'] ?? [] as $item) {
                    $value = $data['attachments'][$item['key']] ?? null;
                    if ($value instanceof UploadedFile) {
                        Validator::make(['file' => $value], ['file' => ['required', File::types(config('lms.allowed_extensions'))->max(config('lms.max_upload_size_kb')), 'extensions:'.implode(',', config('lms.allowed_extensions'))]])->validate();
                        $path = $value->storeAs('lms/requests/public', Str::uuid().'.'.strtolower($value->getClientOriginalExtension()), ['disk' => 'local', 'visibility' => 'private']);
                        if ($path === false) {
                            throw new \RuntimeException('Lampiran gagal disimpan.');
                        }
                        $storedPaths[] = $path;
                        $attachments[$item['key']] = $path;
                    } elseif ($value !== null) {
                        throw ValidationException::withMessages(['attachments.'.$item['key'] => 'Unggah berkas lampiran.']);
                    }
                }
                $request->attachments = $attachments;
                $request->validateSubmission(false);
                $request->status = 'submitted';
                $request->recordEvent('submitted', null);
                $request->save();
                app(DocumentRequestNotifier::class)->enqueue($request);

                return $request;
            });
        } catch (Throwable $exception) {
            foreach ($storedPaths as $path) {
                Storage::disk('local')->delete($path);
            }
            throw $exception;
        }
    }

    /** @param array<string, mixed> $data */
    public static function saveDraft(array $data, User $user, ?self $existing = null): self
    {
        $storedPaths = [];
        try {
            return DB::transaction(function () use ($data, $user, $existing, &$storedPaths): self {
                $request = $existing ? self::whereKey($existing->id)->lockForUpdate()->firstOrFail() : new self;
                if ($existing) {
                    Gate::forUser($user)->authorize('update', $request);
                    if (! $request->editableBy($user)) {
                        throw ValidationException::withMessages(['data.title' => 'Pengajuan ini sudah dikirim.']);
                    }
                    unset($data['company_id'], $data['document_type_id']);
                } else {
                    Gate::forUser($user)->authorize('create', self::class);
                    $type = DocumentType::where('is_active', true)->findOrFail($data['document_type_id'] ?? null);
                    $request->requester_id = $user->id;
                    $request->reviewer_id = $type->request_reviewer_id;
                    $request->approver_id = $type->request_approver_id;
                    $request->requirements = ['fields' => $type->request_fields, 'attachments' => $type->request_attachments, 'has_expiry' => $type->has_expiry];
                }
                $request->fill($data);
                Validator::make($request->attributesToArray(), [
                    'company_id' => ['required', Rule::exists('companies', 'id')->where('is_active', true)],
                    'document_type_id' => ['required', 'exists:document_types,id'],
                    'pic_user_id' => ['required', 'exists:users,id'], 'title' => ['required', 'string', 'max:255'],
                    'partner_name' => ['required', 'string', 'max:255'], 'purpose' => ['required', 'in:new,renewal,amendment'],
                    'partner_pic' => ['nullable', 'string', 'max:255'], 'partner_contact' => ['nullable', 'string', 'max:255'],
                    'start_date' => ['nullable', 'date'], 'expiry_date' => ['nullable', 'date', 'after_or_equal:start_date'],
                    'target_date' => ['nullable', 'date'], 'has_cost' => ['boolean'], 'amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999999999'],
                    'payment_terms' => ['nullable', 'string', 'max:4000'], 'details' => ['array'],
                ])->validate();
                $attachments = $request->attachments;
                foreach ($request->requirements['attachments'] ?? [] as $item) {
                    $value = $data['attachments'][$item['key']] ?? null;
                    if ($value instanceof UploadedFile) {
                        Validator::make(['file' => $value], ['file' => ['required', File::types(config('lms.allowed_extensions'))->max(config('lms.max_upload_size_kb')), 'extensions:'.implode(',', config('lms.allowed_extensions'))]])->validate();
                        $path = $value->storeAs('lms/requests/'.$user->id, Str::uuid().'.'.strtolower($value->getClientOriginalExtension()), ['disk' => 'local', 'visibility' => 'private']);
                        if ($path === false) {
                            throw new \RuntimeException('Lampiran gagal disimpan.');
                        }
                        $storedPaths[] = $path;
                        $attachments[$item['key']] = $path;
                    } elseif ($value === null) {
                        unset($attachments[$item['key']]);
                    } elseif ($value !== ($attachments[$item['key']] ?? null)) {
                        throw ValidationException::withMessages(['data.attachments.'.$item['key'] => 'Unggah ulang lampiran.']);
                    }
                }
                $request->attachments = $attachments;
                if (! $existing) {
                    $request->recordEvent('draft', $user);
                }
                $request->save();

                return $request;
            });
        } catch (Throwable $exception) {
            foreach ($storedPaths as $path) {
                Storage::disk('local')->delete($path);
            }
            throw $exception;
        }
    }

    /** @param array<string, mixed> $item */
    public function needsAttachment(array $item): bool
    {
        return (bool) ($item['required'] ?? false) && match ($item['when'] ?? 'always') {
            'cost' => $this->has_cost,
            'renewal' => in_array($this->purpose, ['renewal', 'amendment'], true),
            default => true,
        };
    }

    public function validateSubmission(bool $requireDates = true): void
    {
        $rules = ['start_date' => [$requireDates ? 'required' : 'nullable', 'date'], 'expiry_date' => [Rule::requiredIf($requireDates && (bool) ($this->requirements['has_expiry'] ?? false)), 'nullable', 'date', 'after_or_equal:start_date'], 'amount' => [Rule::requiredIf($this->has_cost), 'nullable', 'numeric', 'min:0'], 'payment_terms' => [Rule::requiredIf($this->has_cost), 'nullable', 'string', 'max:4000']];
        $labels = ['start_date' => 'Mulai', 'expiry_date' => 'Berakhir', 'amount' => 'Nilai', 'payment_terms' => 'Pembayaran'];
        foreach ($this->requirements['fields'] ?? [] as $item) {
            $key = 'details.'.$item['key'];
            $rules[$key] = [($item['required'] ?? false) ? 'required' : 'nullable', match ($item['type']) {
                'number' => 'numeric', 'date' => 'date', default => 'string'
            }];
            if (in_array($item['type'], ['text', 'textarea'], true)) {
                $rules[$key][] = 'max:4000';
            }
            $labels[$key] = $item['label'];
        }
        foreach ($this->requirements['attachments'] ?? [] as $item) {
            $key = 'attachments.'.$item['key'];
            $rules[$key] = [$this->needsAttachment($item) ? 'required' : 'nullable', 'string'];
            $labels[$key] = $item['label'];
            $path = $this->attachments[$item['key']] ?? null;
            if ($path && ! Storage::disk('local')->exists($path)) {
                throw ValidationException::withMessages([$key => $item['label'].' tidak ditemukan. Unggah ulang.']);
            }
        }
        Validator::make($this->attributesToArray(), $rules, ['required' => ':attribute wajib diisi.', 'date' => ':attribute harus berupa tanggal.', 'numeric' => ':attribute harus berupa angka.', 'after_or_equal' => ':attribute tidak boleh sebelum tanggal mulai.'], $labels)->validate();
    }

    public function transition(string $next, User $user, ?string $note = null): void
    {
        DB::transaction(function () use ($next, $user, $note): void {
            $request = self::whereKey($this->id)->lockForUpdate()->firstOrFail();
            $allowed = match ($next) {
                'submitted' => $request->editableBy($user),
                'review' => $request->status === 'submitted' && $request->requester_id !== $user->id,
                'approved' => $request->status === 'review' && $request->requester_id !== $user->id,
                'revision', 'rejected' => in_array($request->status, ['submitted', 'review'], true) && $request->requester_id !== $user->id,
                default => false,
            };
            if (! $allowed) {
                throw ValidationException::withMessages(['status' => 'Tahap pengajuan tidak sesuai.']);
            }
            $ability = match ($next) {
                'submitted' => 'submit', 'review' => 'review', 'approved' => 'approve', 'revision', 'rejected' => 'decide', default => abort(422)
            };
            Gate::forUser($user)->authorize($ability, $request);
            if ($next === 'submitted') {
                $request->validateSubmission();
            }
            if (in_array($next, ['revision', 'rejected'], true)) {
                Validator::make(['note' => $note], ['note' => ['required', 'string', 'max:4000']])->validate();
            }
            $request->status = $next;
            $request->recordEvent($next, $user, $note);
            $request->save();
            app(DocumentRequestNotifier::class)->enqueue($request);
        });
        $this->refresh();
    }

    public function archiveSigned(UploadedFile $file, string $number, ?string $issuedDate, ?string $expiryDate, User $user): Document
    {
        return DB::transaction(function () use ($file, $number, $issuedDate, $expiryDate, $user): Document {
            $request = self::whereKey($this->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($user)->authorize('archive', $request);
            if ($request->status !== 'approved') {
                throw ValidationException::withMessages(['status' => 'Pengajuan belum disetujui.']);
            }
            Validator::make(['number' => $number], ['number' => ['required', 'string', 'max:255']])->validate();
            $document = Document::archive(['company_id' => $request->company_id, 'document_type_id' => $request->document_type_id, 'pic_user_id' => $request->pic_user_id, 'title' => $request->title, 'document_number' => $number, 'counterparty' => $request->partner_name, 'issued_date' => $issuedDate, 'expiry_date' => $expiryDate, 'notes' => 'Pengajuan #'.$request->id], $file, $user);
            $request->document_id = $document->id;
            $request->status = 'archived';
            $request->recordEvent('archived', $user);
            $request->save();
            app(DocumentRequestNotifier::class)->enqueue($request);
            $this->refresh();

            return $document;
        });
    }

    private function recordEvent(string $status, ?User $user, ?string $note = null): void
    {
        $this->history = [...$this->history, ['status' => $status, 'user' => $user?->name ?? $this->applicantName(), 'user_id' => $user?->id, 'at' => now()->toIso8601String(), 'note' => $note]];
    }
}
