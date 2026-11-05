<?php

namespace App\Models;

use App\Models\Concerns\Trashable;
use App\Services\DocumentRequestNotifier;
use App\Services\StorageFileName;
use App\Services\WaghubService;
use Carbon\CarbonImmutable;
use Database\Factories\DocumentRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;
use Throwable;

#[Fillable(['company_id', 'document_type_id', 'pic_user_id', 'title', 'purpose', 'partner_name', 'partner_pic', 'partner_contact', 'start_date', 'expiry_date', 'target_date', 'has_cost', 'amount', 'payment_terms', 'details'])]
class DocumentRequest extends Model
{
    /** @use HasFactory<DocumentRequestFactory> */
    use HasFactory, Trashable;

    public function trashSubject(): string
    {
        return 'document_request';
    }

    public function trashLabel(): string
    {
        return (string) $this->title;
    }

    public const STATUSES = ['draft' => 'Draf', 'submitted' => 'Diperiksa', 'revision' => 'Perlu Revisi', 'signing' => 'Menunggu Tanda Tangan', 'handover' => 'Menunggu Dokumen Fisik', 'rejected' => 'Ditolak', 'archived' => 'Selesai', 'cancelled' => 'Dibatalkan'];

    /** Label status tahap persetujuan lama; hanya untuk membaca data lama, tidak lagi menjadi status yang sah. */
    private const LEGACY_STATUS_LABELS = ['review' => 'Menunggu Persetujuan', 'approved' => 'Menunggu Tanda Tangan'];

    /** Status setelah draf keluar yang boleh dilihat semua Staff Legal. */
    public const LEGAL_VISIBLE_STATUSES = ['signing', 'handover', 'archived', 'cancelled'];

    /** Batas hari menyerahkan dokumen fisik setelah scan pertama; pengingat lanjutan di hari ke-7, 14, 28. */
    public const HANDOVER_DAYS = 7;

    public const LATE_REMINDER_DAYS = [7, 14, 28];

    public const SCAN_REMINDER_DAYS = 7;

    public const PURPOSES = ['new' => 'Baru', 'renewal' => 'Perpanjangan', 'amendment' => 'Adendum'];

    /** Status yang boleh dibatalkan tim legal: revisi yang ditinggal pengaju, atau setelah draft keluar. */
    public const CANCELLABLE_STATUSES = ['revision', 'signing', 'handover'];

    /** Status saat pengajuan menunggu tindakan staf. */
    public const WAITING_STATUSES = ['submitted'];

    public const PROGRESS_STEPS = ['submitted' => 'Diperiksa', 'signing' => 'Menunggu Tanda Tangan', 'handover' => 'Menunggu Dokumen Fisik', 'archived' => 'Selesai'];

    public const TIMELINE_LABELS = [
        'submitted' => 'Pengajuan diterima', 'review' => 'Diteruskan untuk persetujuan', 'approved' => 'Disetujui, menunggu tanda tangan',
        'revision' => 'Perlu revisi', 'rejected' => 'Ditolak', 'archived' => 'Selesai, dokumen fisik diterima dan diarsipkan',
        'signing' => 'Draft dokumen diterbitkan, menunggu tanda tangan', 'handover' => 'Scan diunggah, menunggu dokumen fisik', 'cancelled' => 'Dibatalkan',
    ];

    public const TIMELINE_EVENT_LABELS = ['draft_replaced' => 'Draft dokumen diganti', 'signed_replaced' => 'Scan diganti', 'late_reason' => 'Alasan keterlambatan dari pengaju'];

    protected $attributes = ['details' => '{}', 'attachments' => '{}', 'requirements' => '{}', 'history' => '[]', 'status' => 'draft'];

    protected function casts(): array
    {
        return ['details' => 'array', 'attachments' => 'array', 'requirements' => 'array', 'history' => 'array', 'status_changed_at' => 'datetime', 'draft_issued_at' => 'datetime', 'signed_uploaded_at' => 'datetime', 'physical_received_at' => 'datetime', 'has_cost' => 'boolean', 'start_date' => 'date', 'expiry_date' => 'date', 'target_date' => 'date', 'amount' => 'decimal:2'];
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

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    /**
     * Staf legal penerima notifikasi: pemeriksa pengajuan bila masih aktif, punya izin Review, dan nomornya valid;
     * kalau tidak, semua user aktif pemegang `Review:DocumentRequest` dengan nomor valid.
     *
     * @return Collection<int, User>
     */
    public function legalStaffRecipients(): Collection
    {
        $reviewer = $this->reviewer;
        if ($reviewer !== null && $reviewer->isActive() && $this->hasValidPhone($reviewer) && $this->canReview($reviewer)) {
            return collect([$reviewer]);
        }

        return $this->usersWithPermission('Review:DocumentRequest');
    }

    /**
     * Koordinator penerima eskalasi: user aktif pemegang `ReceiveEscalation:DocumentRequest` dengan nomor valid.
     *
     * @return Collection<int, User>
     */
    public function coordinatorRecipients(): Collection
    {
        return $this->usersWithPermission('ReceiveEscalation:DocumentRequest');
    }

    /** @return Collection<int, User> */
    private function usersWithPermission(string $permission): Collection
    {
        try {
            return User::permission($permission)->active()->orderBy('id')->get()->filter(fn (User $user): bool => $this->hasValidPhone($user))->values();
        } catch (PermissionDoesNotExist) {
            return collect();
        }
    }

    private function canReview(User $user): bool
    {
        return $this->usersWithPermission('Review:DocumentRequest')->contains('id', $user->id);
    }

    private function hasValidPhone(User $user): bool
    {
        return app(WaghubService::class)->normalizePhone($user->phone) !== null;
    }

    /** Waktu masuk ke tahap saat ini: kolom status_changed_at, atau peristiwa terakhir di riwayat bila belum terisi. */
    public function waitingSince(): CarbonImmutable
    {
        $at = $this->status_changed_at ?? collect($this->history)->last()['at'] ?? null;

        return CarbonImmutable::parse($at ?? $this->updated_at);
    }

    /** Pengajuan yang menunggu tindakan pemeriksa atau penyetuju lebih lama dari $days hari. */
    public function scopeWaitingLongerThan(Builder $query, int $days): Builder
    {
        return $query->whereIn('status', self::WAITING_STATUSES)
            ->whereRaw('coalesce(document_requests.status_changed_at, document_requests.updated_at) <= ?', [now()->subDays($days)->toDateTimeString()]);
    }

    public function lateReasons(): HasMany
    {
        return $this->hasMany(DocumentRequestLateReason::class)->orderBy('created_at');
    }

    /** Tenggat penyerahan dokumen fisik: 7 hari kalender (WIB) setelah scan pertama diunggah, atau null bila belum ada scan. */
    public function handoverDueDate(): ?CarbonImmutable
    {
        return $this->jakartaDate($this->signed_uploaded_at)?->addDays(self::HANDOVER_DAYS);
    }

    /** Selisih hari kalender (WIB) sejak scan pertama diunggah. */
    public function daysSinceSigned(?CarbonImmutable $today = null): ?int
    {
        return $this->daysSince($this->signed_uploaded_at, $today);
    }

    /** Selisih hari kalender (WIB) sejak draf pertama diterbitkan. */
    public function daysSinceDraft(?CarbonImmutable $today = null): ?int
    {
        return $this->daysSince($this->draft_issued_at, $today);
    }

    public function isHandoverLate(?CarbonImmutable $today = null): bool
    {
        return $this->status === 'handover' && ($this->daysSinceSigned($today) ?? 0) > self::HANDOVER_DAYS;
    }

    /** Sisa waktu penyerahan fisik untuk tampilan: "Sisa 3 hari", "Batas hari ini", atau "Terlambat 2 hari". */
    public function handoverRemainingLabel(): string
    {
        if ($this->isHandoverLate()) {
            return 'Terlambat '.$this->daysLate().' hari';
        }
        $daysLeft = max(0, self::HANDOVER_DAYS - ($this->daysSinceSigned() ?? 0));

        return $daysLeft === 0 ? 'Batas hari ini' : "Sisa {$daysLeft} hari";
    }

    /** Jumlah hari melewati tenggat; 0 bila tidak terlambat. */
    public function daysLate(): int
    {
        return max(0, ($this->daysSinceSigned() ?? 0) - self::HANDOVER_DAYS);
    }

    /** Jumlah hari terlambat saat dokumen fisik diterima (kalender WIB, dihitung dari scan pertama); 0 bila tepat waktu atau tanpa scan. */
    public function daysLateAtReceipt(): int
    {
        if ($this->signed_uploaded_at === null || $this->physical_received_at === null) {
            return 0;
        }

        return max(0, ($this->daysSince($this->signed_uploaded_at, CarbonImmutable::parse($this->physical_received_at)) ?? 0) - self::HANDOVER_DAYS);
    }

    /** Pengajuan menunggu dokumen fisik yang belum melewati tenggat. */
    public function scopeHandoverOnTime(Builder $query): Builder
    {
        return $query->where('status', 'handover')->where('signed_uploaded_at', '>=', self::lateBoundary());
    }

    /** Pengajuan menunggu dokumen fisik yang sudah melewati tenggat. */
    public function scopeHandoverLate(Builder $query): Builder
    {
        return $query->where('status', 'handover')->where('signed_uploaded_at', '<', self::lateBoundary());
    }

    /** Pengajuan menunggu scan tanda tangan, draf terbit lebih dari 7 hari lalu. */
    public function scopeScanOverdue(Builder $query): Builder
    {
        return $query->where('status', 'signing')->whereNull('signed_uploaded_at')->where('draft_issued_at', '<', self::lateBoundary());
    }

    /** Pengajuan terlambat yang belum punya alasan keterlambatan. */
    public function scopeLateWithoutReason(Builder $query): Builder
    {
        return $query->handoverLate()->whereDoesntHave('lateReasons');
    }

    /**
     * Batas awal hari WIB (dalam UTC) tujuh hari lalu; unggahan sebelum batas ini sudah terlambat.
     * Perbandingan SQL mengasumsikan timestamp tersimpan dalam UTC (`config('app.timezone') === 'UTC'`).
     */
    private static function lateBoundary(): string
    {
        return CarbonImmutable::now(config('lms.reminder_timezone'))->startOfDay()->subDays(self::HANDOVER_DAYS)->utc()->toDateTimeString();
    }

    private function jakartaDate(mixed $at): ?CarbonImmutable
    {
        return $at === null ? null : CarbonImmutable::parse($at)->timezone(config('lms.reminder_timezone'))->startOfDay();
    }

    private function daysSince(mixed $at, ?CarbonImmutable $today): ?int
    {
        $start = $this->jakartaDate($at);
        if ($start === null) {
            return null;
        }
        $today = ($today ?? CarbonImmutable::now())->timezone(config('lms.reminder_timezone'))->startOfDay();

        return (int) $start->diffInDays($today);
    }

    /** Label status; status tahap persetujuan lama dan status tak dikenal tidak boleh membuat tampilan error. */
    public static function statusLabel(string $status): string
    {
        return self::STATUSES[$status] ?? self::LEGACY_STATUS_LABELS[$status] ?? $status;
    }

    public function editableBy(User $user): bool
    {
        return $this->requester_id === $user->id && in_array($this->status, ['draft', 'revision'], true);
    }

    public function revisionNumber(): int
    {
        return collect($this->history)->where('status', 'revision')->count();
    }

    /**
     * Tahap utama yang ditampilkan di halaman status publik.
     *
     * @return list<array{label: string, state: 'done'|'current'|'stopped'|'pending'}>
     */
    public function progressSteps(): array
    {
        $stage = match ($this->status) {
            'cancelled' => $this->lastStageBeforeCancellation(),
            'approved' => 'signing',
            'handover', 'signing', 'archived' => $this->status,
            default => 'submitted',
        };
        $position = array_search($stage, array_keys(self::PROGRESS_STEPS), true);
        $position = $position === false ? 0 : $position;
        $steps = [];
        foreach (array_values(self::PROGRESS_STEPS) as $index => $label) {
            $state = match (true) {
                $index < $position => 'done',
                $index === $position => match ($this->status) {
                    'archived' => 'done', 'rejected', 'cancelled' => 'stopped', default => 'current',
                },
                default => 'pending',
            };
            $steps[] = ['label' => $label, 'state' => $state];
        }

        return $steps;
    }

    /** Tahap terakhir yang dicapai sebelum pembatalan, dibaca dari riwayat status. */
    private function lastStageBeforeCancellation(): string
    {
        $stage = 'submitted';
        foreach ($this->history as $event) {
            if (isset($event['event'])) {
                continue;
            }
            $status = $event['status'] ?? null;
            if ($status === 'approved') {
                $status = 'signing';
            }
            if (in_array($status, ['signing', 'handover'], true)) {
                $stage = $status;
            }
        }

        return $stage;
    }

    /**
     * Riwayat untuk pengaju, terbaru di atas. Hanya peran, tidak memuat nama staf.
     *
     * @return list<array{label: string, actor: string, at: ?CarbonImmutable, note: ?string, tone: 'normal'|'warning'|'danger'}>
     */
    public function publicTimeline(): array
    {
        $entries = [];
        $previous = null;
        foreach ($this->history as $event) {
            $kind = $event['event'] ?? null;
            if ($kind !== null) {
                if (isset(self::TIMELINE_EVENT_LABELS[$kind])) {
                    $byApplicant = $this->isApplicantUpload($event);
                    $entries[] = [
                        'label' => $this->timelineEventLabel($event),
                        'actor' => match ($kind) {
                            'signed_replaced', 'late_reason' => $byApplicant ? 'Pengaju' : 'Tim legal',
                            default => 'Tim legal',
                        },
                        'at' => isset($event['at']) ? CarbonImmutable::parse($event['at'])->timezone(config('lms.reminder_timezone')) : null,
                        'note' => filled($event['note'] ?? null) ? (string) $event['note'] : null,
                        'tone' => $kind === 'late_reason' ? 'warning' : 'normal',
                    ];
                }

                continue;
            }
            $status = $event['status'] ?? null;
            if ($status !== 'draft' && isset(self::TIMELINE_LABELS[$status])) {
                $entries[] = [
                    'label' => $status === 'submitted' && $previous === 'revision' ? 'Revisi dikirim' : self::TIMELINE_LABELS[$status],
                    'actor' => match ($status) {
                        'submitted' => 'Pengaju',
                        'review' => 'Pemeriksa',
                        'approved' => 'Penyetuju',
                        'revision', 'rejected' => $previous === 'review' ? 'Penyetuju' : 'Pemeriksa',
                        'handover' => $this->isApplicantUpload($event) ? 'Pengaju' : 'Tim legal',
                        'signing', 'archived', 'cancelled' => 'Tim legal',
                        default => 'Tim pemeriksa',
                    },
                    'at' => isset($event['at']) ? CarbonImmutable::parse($event['at'])->timezone(config('lms.reminder_timezone')) : null,
                    'note' => filled($event['note'] ?? null) ? (string) $event['note'] : null,
                    'tone' => match ($status) {
                        'revision' => 'warning', 'rejected', 'cancelled' => 'danger', default => 'normal',
                    },
                ];
            }
            $previous = $status;
        }

        return array_reverse($entries);
    }

    /**
     * Unggahan scan dianggap dari pengaju bila tanpa user (tautan publik) atau user-nya pengaju sendiri; selain itu dari tim legal.
     *
     * @param  array<string, mixed>  $event
     */
    private function isApplicantUpload(array $event): bool
    {
        $userId = $event['user_id'] ?? null;

        return $userId === null || $userId === $this->requester_id;
    }

    /**
     * Label kegiatan tanpa perubahan status (ganti draft/scan, alasan terlambat), atau null bila bukan kegiatan yang ditampilkan.
     * Alasan terlambat yang dicatat tim legal atas nama pengaju diberi label tersendiri.
     *
     * @param  array<string, mixed>  $event
     */
    public function timelineEventLabel(array $event): ?string
    {
        $kind = $event['event'] ?? null;
        if (! is_string($kind) || ! isset(self::TIMELINE_EVENT_LABELS[$kind])) {
            return null;
        }

        return $kind === 'late_reason' && ! $this->isApplicantUpload($event) ? 'Alasan keterlambatan dicatat tim legal' : self::TIMELINE_EVENT_LABELS[$kind];
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(DocumentRequestNotification::class);
    }

    public function applicantName(): string
    {
        return $this->requester_name ?? $this->requester?->name ?? 'Pengaju';
    }

    /**
     * Pihak lawan dokumen, atau null bila tidak ada.
     * Pengajuan publik lama mengisi kolom ini dengan nama badan usaha pengaju sendiri; nilai itu bukan pihak lawan dan diabaikan.
     */
    public function counterpartyName(): ?string
    {
        $name = trim((string) $this->partner_name);
        if ($name === '') {
            return null;
        }
        if ($this->public_token !== null) {
            foreach ([$this->company?->name, $this->other_business_name] as $ownBusiness) {
                if (filled($ownBusiness) && mb_strtolower(trim($ownBusiness)) === mb_strtolower($name)) {
                    return null;
                }
            }
        }

        return $name;
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
                $request->other_business_name = $data['other_business_name'] ?? null;
                Validator::make($request->attributesToArray(), ['company_id' => ['nullable', Rule::exists('companies', 'id')->where('is_active', true)], 'other_business_name' => [Rule::requiredIf($request->company_id === null), 'nullable', 'string', 'max:255']])->validate();
                $attachments = $request->attachments;
                foreach ($request->requirements['attachments'] ?? [] as $item) {
                    $value = $data['attachments'][$item['key']] ?? null;
                    if ($value instanceof UploadedFile) {
                        Validator::make(['file' => $value], ['file' => ['required', File::types(config('lms.allowed_extensions'))->max(config('lms.max_upload_size_kb')), 'extensions:'.implode(',', config('lms.allowed_extensions'))]])->validate();
                        $path = $request->storeAttachment($value, $item);
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
                    $request->requirements = ['fields' => $type->request_fields, 'attachments' => $type->request_attachments, 'has_expiry' => $type->has_expiry];
                }
                $request->fill($data);
                Validator::make($request->attributesToArray(), [
                    'company_id' => ['required', Rule::exists('companies', 'id')->where('is_active', true)],
                    'document_type_id' => ['required', 'exists:document_types,id'],
                    'pic_user_id' => ['required', 'exists:users,id,deactivated_at,NULL'], 'title' => ['required', 'string', 'max:255'],
                    'partner_name' => ['nullable', 'string', 'max:255'], 'purpose' => ['required', 'in:new,renewal,amendment'],
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
                        $path = $request->storeAttachment($value, $item);
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

    /**
     * Simpan lampiran di `lms/requests/{jenis-dokumen}/` dengan nama berisi badan usaha, jenis, nama lampiran, nomor pengajuan, dan waktu upload.
     *
     * @param  array<string, mixed>  $item
     */
    private function storeAttachment(UploadedFile $file, array $item): string
    {
        $disk = Storage::disk('local');
        $type = DocumentType::whereKey($this->document_type_id)->value('name');
        $directory = 'lms/requests/'.StorageFileName::folder($type);
        $filename = StorageFileName::available($disk, $directory, StorageFileName::build([
            StorageFileName::companyName(Company::find($this->company_id)) ?? $this->other_business_name,
            $type,
            $item['label'] ?? $item['key'],
            $this->exists ? "pengajuan{$this->id}" : null,
        ], now(), $file->getClientOriginalExtension()));

        $path = $file->storeAs($directory, $filename, ['disk' => 'local', 'visibility' => 'private']);
        if ($path === false) {
            throw new \RuntimeException('Lampiran gagal disimpan.');
        }

        return $path;
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

    /**
     * Pindahkan status: kirim pengajuan (`submitted`), revisi atau tolak saat diperiksa, atau batalkan saat revisi atau setelah draft keluar (`cancelled`).
     * Pembatalan tidak menghapus draf maupun scan; keduanya tetap ada sebagai catatan.
     */
    public function transition(string $next, User $user, ?string $note = null): void
    {
        DB::transaction(function () use ($next, $user, $note): void {
            $request = self::whereKey($this->id)->lockForUpdate()->firstOrFail();
            $allowed = match ($next) {
                'submitted' => $request->editableBy($user),
                'revision', 'rejected' => $request->status === 'submitted' && $request->requester_id !== $user->id,
                'cancelled' => in_array($request->status, self::CANCELLABLE_STATUSES, true),
                default => false,
            };
            if (! $allowed) {
                throw ValidationException::withMessages(['status' => 'Tahap pengajuan tidak sesuai.']);
            }
            $ability = match ($next) {
                'submitted' => 'submit', 'revision', 'rejected' => 'revise', 'cancelled' => 'cancel',
            };
            Gate::forUser($user)->authorize($ability, $request);
            if ($next === 'submitted') {
                $request->validateSubmission();
            }
            if (in_array($next, ['revision', 'rejected', 'cancelled'], true)) {
                Validator::make(['note' => $note], ['note' => ['required', 'string', 'max:4000']])->validate();
            }
            $request->status = $next;
            $request->recordEvent($next, $user, $note);
            $request->save();
            app(DocumentRequestNotifier::class)->enqueue($request);
        });
        $this->refresh();
    }

    /**
     * Keluarkan draf: dari Diperiksa ke Menunggu Tanda Tangan. Selama scan belum diunggah, draf boleh diganti;
     * penggantian tidak mengubah `draft_issued_at` sehingga hitungan pengingat scan tidak mengulang.
     */
    public function issueDraft(UploadedFile $file, User $user): void
    {
        $this->saveFlowFile($file, 'draft', function (self $request) use ($user): void {
            $allowed = ($request->status === 'submitted' && $request->requester_id !== $user->id)
                || ($request->status === 'signing' && $request->signed_path === null);
            if (! $allowed) {
                throw ValidationException::withMessages(['status' => 'Tahap pengajuan tidak sesuai.']);
            }
            Gate::forUser($user)->authorize('issueDraft', $request);
        }, function (self $request) use ($user): void {
            $request->draft_issued_at ??= now();
            if ($request->status === 'submitted') {
                $request->status = 'signing';
                $request->recordEvent('signing', $user);
            } elseif ($request->getOriginal('draft_path') === null) {
                // Pengajuan hasil migrasi berstatus signing tanpa draf: draf pertama diperlakukan sebagai penerbitan pertama.
                $request->recordEvent('signing', $user);
            } else {
                $request->recordActivity('draft_replaced', $user);
            }
            $request->save();
            app(DocumentRequestNotifier::class)->enqueue($request);
        });
    }

    /**
     * Unggah scan bertanda tangan: dari Menunggu Tanda Tangan ke Menunggu Dokumen Fisik. Scan boleh diganti selama fisik
     * belum diterima; penggantian tidak mengubah `signed_uploaded_at` sehingga batas 7 hari tidak mengulang.
     * `$user` null berarti dari tautan publik bertoken, yang otorisasinya dilakukan controller.
     */
    public function uploadSigned(UploadedFile $file, ?User $user): void
    {
        $this->saveFlowFile($file, 'signed', function (self $request) use ($user): void {
            if (! in_array($request->status, ['signing', 'handover'], true)) {
                throw ValidationException::withMessages(['status' => 'Tahap pengajuan tidak sesuai.']);
            }
            if ($user !== null) {
                Gate::forUser($user)->authorize('uploadSigned', $request);
            }
        }, function (self $request) use ($user): void {
            if ($request->status === 'signing') {
                $request->status = 'handover';
                $request->signed_uploaded_at ??= now();
                $request->recordEvent('handover', $user);
                $request->save();
                app(DocumentRequestNotifier::class)->enqueue($request);

                return;
            }
            $request->recordActivity('signed_replaced', $user);
            $request->save();
        });
    }

    /**
     * Catat alasan keterlambatan penyerahan fisik. Boleh berkali-kali, tidak perlu disetujui, dan tidak memperpanjang batas.
     * `$user` null berarti dari tautan publik bertoken.
     */
    public function addLateReason(string $reason, ?User $user): DocumentRequestLateReason
    {
        $lateReason = DB::transaction(function () use ($reason, $user): DocumentRequestLateReason {
            $request = self::whereKey($this->id)->lockForUpdate()->firstOrFail();
            if (! $request->isHandoverLate()) {
                throw ValidationException::withMessages(['reason' => 'Alasan keterlambatan baru bisa diisi setelah batas penyerahan dokumen fisik lewat.']);
            }
            if ($user !== null) {
                Gate::forUser($user)->authorize('addLateReason', $request);
            }
            Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'max:2000']], [], ['reason' => 'Alasan keterlambatan'])->validate();
            $lateReason = $request->lateReasons()->create(['reason' => $reason, 'user_id' => $user?->id]);
            $request->recordActivity('late_reason', $user, $reason);
            $request->save();

            return $lateReason;
        });
        $this->refresh();

        return $lateReason;
    }

    /**
     * Fisik diterima: arsipkan dokumen lalu tandai pengajuan Selesai. File arsip memakai `$file` bila diunggah,
     * kalau tidak memakai scan bertanda tangan yang sudah ada.
     */
    public function markReceived(string $number, ?string $issuedDate, ?string $expiryDate, User $user, ?int $companyId = null, ?UploadedFile $file = null): Document
    {
        $storedFile = null;

        try {
            return $this->markReceivedInTransaction($number, $issuedDate, $expiryDate, $user, $companyId, $file, $storedFile);
        } catch (Throwable $exception) {
            // Document::archive sudah menyimpan file; bila langkah di luarnya gagal, DB di-rollback tetapi file tertinggal.
            if ($storedFile !== null) {
                try {
                    Storage::disk($storedFile[0])->delete($storedFile[1]);
                } catch (Throwable $cleanupException) {
                    report($cleanupException);
                }
            }

            throw $exception;
        }
    }

    /** @param array{0: string, 1: string}|null $storedFile */
    private function markReceivedInTransaction(string $number, ?string $issuedDate, ?string $expiryDate, User $user, ?int $companyId, ?UploadedFile $file, ?array &$storedFile): Document
    {
        return DB::transaction(function () use ($number, $issuedDate, $expiryDate, $user, $companyId, $file, &$storedFile): Document {
            $request = self::whereKey($this->id)->lockForUpdate()->firstOrFail();
            if (! in_array($request->status, ['signing', 'handover'], true)) {
                throw ValidationException::withMessages(['status' => 'Tahap pengajuan tidak sesuai.']);
            }
            Gate::forUser($user)->authorize('markReceived', $request);
            $file ??= $request->signedScanFile();
            if ($file === null) {
                throw ValidationException::withMessages(['file' => 'Unggah dokumen yang sudah ditandatangani.']);
            }
            Validator::make(['number' => $number], ['number' => ['required', 'string', 'max:255']])->validate();
            $companyId = $request->company_id ?? $companyId;
            Validator::make(['company_id' => $companyId], ['company_id' => ['required', Rule::exists('companies', 'id')->where('is_active', true)]])->validate();
            $request->company_id = $companyId;
            $document = Document::archive(['company_id' => $request->company_id, 'document_type_id' => $request->document_type_id, 'pic_user_id' => $request->pic_user_id, 'title' => $request->title, 'document_number' => $number, 'counterparty' => $request->counterpartyName(), 'issued_date' => $issuedDate, 'expiry_date' => $expiryDate, 'notes' => 'Pengajuan #'.$request->id], $file, $user);
            $storedFile = [$document->currentVersion->disk, $document->currentVersion->file_path];
            $request->document_id = $document->id;
            $request->physical_received_at = now();
            $request->status = 'archived';
            $daysLate = $request->daysLateAtReceipt();
            $request->recordEvent('archived', $user, $daysLate > 0 ? "Diterima terlambat {$daysLate} hari" : null);
            $request->save();
            app(DocumentRequestNotifier::class)->enqueue($request);
            $this->refresh();

            return $document;
        });
    }

    /** Scan bertanda tangan yang tersimpan, sebagai berkas unggahan agar bisa diarsipkan; null bila belum ada. */
    private function signedScanFile(): ?UploadedFile
    {
        $disk = Storage::disk('local');
        if ($this->signed_path === null || ! $disk->exists($this->signed_path)) {
            return null;
        }

        return new UploadedFile($disk->path($this->signed_path), $this->signed_name ?? basename($this->signed_path), null, null, true);
    }

    /**
     * Simpan draf atau scan dalam transaksi terkunci. `$guard` memeriksa tahap dan izin sebelum file disimpan,
     * `$apply` mengubah dan menyimpan pengajuan. File lama dihapus setelah commit; bila gagal, file baru yang dihapus.
     *
     * @param  'draft'|'signed'  $kind
     * @param  callable(self): void  $guard
     * @param  callable(self): void  $apply
     */
    private function saveFlowFile(UploadedFile $file, string $kind, callable $guard, callable $apply): void
    {
        $newPath = null;
        $oldPath = null;
        try {
            DB::transaction(function () use ($file, $kind, $guard, $apply, &$newPath, &$oldPath): void {
                $request = self::whereKey($this->id)->lockForUpdate()->firstOrFail();
                $guard($request);
                Validator::make(['file' => $file], ['file' => ['required', File::types(config('lms.allowed_extensions'))->max(config('lms.max_upload_size_kb')), 'extensions:'.implode(',', config('lms.allowed_extensions'))]])->validate();
                $newPath = $request->storeFlowFile($file, $kind);
                $oldPath = $request->{"{$kind}_path"};
                $request->{"{$kind}_path"} = $newPath;
                $request->{"{$kind}_name"} = basename(str_replace('\\', '/', $file->getClientOriginalName()));
                $apply($request);
            });
        } catch (Throwable $exception) {
            if ($newPath !== null) {
                Storage::disk('local')->delete($newPath);
            }
            throw $exception;
        }
        if ($oldPath !== null && $oldPath !== $newPath) {
            try {
                Storage::disk('local')->delete($oldPath);
            } catch (Throwable $cleanupException) {
                report($cleanupException);
            }
        }
        $this->refresh();
    }

    /**
     * Simpan draf (`draft`) atau scan bertanda tangan (`signed`) di `lms/requests/{jenis-dokumen}/`.
     *
     * @param  'draft'|'signed'  $kind
     */
    private function storeFlowFile(UploadedFile $file, string $kind): string
    {
        $disk = Storage::disk('local');
        $type = DocumentType::whereKey($this->document_type_id)->value('name');
        $directory = 'lms/requests/'.StorageFileName::folder($type);
        $filename = StorageFileName::available($disk, $directory, StorageFileName::build([
            StorageFileName::companyName(Company::find($this->company_id)) ?? $this->other_business_name,
            $type,
            $kind === 'draft' ? 'draft' : 'ttd',
            "pengajuan{$this->id}",
        ], now(), $file->getClientOriginalExtension()));

        $path = $file->storeAs($directory, $filename, ['disk' => 'local', 'visibility' => 'private']);
        if ($path === false) {
            throw new \RuntimeException('File gagal disimpan.');
        }

        return $path;
    }

    private function recordEvent(string $status, ?User $user, ?string $note = null): void
    {
        $now = now();
        $this->history = [...$this->history, ['status' => $status, 'user' => $user?->name ?? $this->applicantName(), 'user_id' => $user?->id, 'at' => $now->toIso8601String(), 'note' => $note]];
        $this->status_changed_at = $now;
    }

    /** Catat kegiatan tanpa perubahan status (mis. `draft_replaced`, `signed_replaced`, `late_reason`); `status_changed_at` tidak berubah. */
    private function recordActivity(string $event, ?User $user, ?string $note = null): void
    {
        $this->history = [...$this->history, ['event' => $event, 'status' => $this->status, 'user' => $user?->name ?? $this->applicantName(), 'user_id' => $user?->id, 'at' => now()->toIso8601String(), 'note' => $note]];
    }
}
