<?php

namespace App\Models;

use App\Models\Concerns\Trashable;
use App\Services\DocumentRequestNotifier;
use App\Services\StorageFileName;
use App\Services\WaghubService;
use App\Support\ActiveReference;
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

    /** Nomor dan judul, misalnya "#14 · PKS Sewa Gudang": judul pengajuan sering mirip, nomornya yang membedakan. */
    public function trashLabel(): string
    {
        return '#'.$this->getKey().' · '.$this->title;
    }

    /**
     * Alur: Menunggu Diperiksa → (legal klik Periksa) Sedang Diperiksa → (legal klik Proses Drafting) Proses Drafting → (draft keluar) Menunggu Tanda Tangan → (soft file bertanda tangan masuk) Menunggu Dokumen Fisik
     * → (fisik diterima legal) Scanning → (scan dokumen fisik diunggah legal) Selesai.
     */
    public const STATUSES = ['draft' => 'Draf', 'submitted' => 'Menunggu Diperiksa', 'reviewing' => 'Sedang Diperiksa', 'drafting' => 'Proses Drafting', 'revision' => 'Perlu Revisi', 'signing' => 'Menunggu Tanda Tangan', 'handover' => 'Menunggu Dokumen Fisik', 'scanning' => 'Scanning', 'rejected' => 'Ditolak', 'archived' => 'Selesai', 'cancelled' => 'Dibatalkan'];

    /** Label status tahap persetujuan lama; hanya untuk membaca data lama, tidak lagi menjadi status yang sah. */
    private const LEGACY_STATUS_LABELS = ['review' => 'Menunggu Persetujuan', 'approved' => 'Menunggu Tanda Tangan'];

    /** Status setelah draf keluar yang boleh dilihat semua Staff Legal. */
    public const LEGAL_VISIBLE_STATUSES = ['signing', 'handover', 'scanning', 'archived', 'cancelled'];

    /** Batas hari menyerahkan dokumen fisik setelah soft file bertanda tangan pertama; pengingat lanjutan di hari ke-7, 14, 21. */
    public const HANDOVER_DAYS = 7;

    /** Tahap pengingat (hari setelah soft file masuk): lewat batas, terlambat 1 minggu, terlambat 2 minggu (ikut ke koordinator). */
    public const LATE_REMINDER_DAYS = [7, 14, 21];

    /** Mulai tahap ini koordinator ikut dikabari: tahap terakhir pengingat (terlambat 2 minggu). */
    public const ESCALATION_DAYS = 21;

    /** Batas hari mengunggah soft file bertanda tangan sejak draft pertama keluar; lewat batas, pengaju diingatkan sekali. */
    public const SCAN_REMINDER_DAYS = 14;

    public const PURPOSES = ['new' => 'Baru', 'renewal' => 'Perpanjangan', 'amendment' => 'Adendum'];

    /** Status saat pengajuan sedang diperiksa atau disusun drafnya oleh pemeriksa yang ditunjuk; di sini pemeriksa boleh merevisi, menolak, atau mengeluarkan draf. */
    public const REVIEW_STATUSES = ['submitted', 'reviewing', 'drafting'];

    /** Status yang boleh dibatalkan tim legal: revisi yang ditinggal pengaju, saat drafting, atau setelah draft keluar sampai fisik diterima. */
    public const CANCELLABLE_STATUSES = ['drafting', 'revision', 'signing', 'handover'];

    /** Badge langkah yang belum beres, tampil di samping status (lihat pendingBadges()). */
    public const BADGE_NO_SCAN = 'Belum unggah soft file';

    public const BADGE_NOT_RECEIVED = 'Fisik belum diterima';

    public const BADGE_NO_PHYSICAL_SCAN = 'Scan belum diunggah';

    /** Status saat pengajuan menunggu tindakan staf. */
    public const WAITING_STATUSES = ['submitted'];

    /**
     * Tahap di halaman status pengaju, diringkas menjadi tiga: pengajuan diserahkan, diproses tim legal (dari Periksa sampai Scanning), selesai.
     * Rincian tiap langkah ada di riwayat (lihat TIMELINE_LABELS).
     */
    public const PROGRESS_STEPS = ['submitted' => 'Diserahkan', 'processing' => 'Diproses', 'archived' => 'Selesai'];

    /** Status yang termasuk tahap "Diproses" di halaman status pengaju; `approved` hanya ada di data lama. */
    public const PROCESSING_STATUSES = ['reviewing', 'drafting', 'approved', 'signing', 'handover', 'scanning'];

    /** Kalimat riwayat tiap perubahan status, lengkap dengan pelakunya; `review` dan `approved` hanya ada di data lama. */
    public const TIMELINE_LABELS = [
        'submitted' => 'Pengaju melakukan pengajuan pembuatan dokumen',
        'reviewing' => 'Tim Legal memeriksa pengajuan dokumen',
        'drafting' => 'Tim Legal sedang melakukan pembuatan draft',
        'revision' => 'Tim Legal meminta revisi pengajuan',
        'rejected' => 'Tim Legal menolak pengajuan',
        'signing' => 'Tim Legal menyerahkan draft dokumen, menunggu tanda tangan pengaju',
        'handover' => 'Pengaju mengunggah soft file bertanda tangan, menunggu dokumen fisik',
        'scanning' => 'Tim Legal menerima dokumen fisik dan sedang melakukan scan',
        'archived' => 'Tim Legal mengarsipkan dokumen, pengajuan selesai',
        'cancelled' => 'Tim Legal membatalkan pengajuan',
        'review' => 'Pemeriksa meneruskan pengajuan untuk persetujuan',
        'approved' => 'Penyetuju menyetujui pengajuan, menunggu tanda tangan',
    ];

    public const TIMELINE_EVENT_LABELS = ['draft_replaced' => 'Tim Legal mengganti draft dokumen', 'signed_replaced' => 'Pengaju mengganti soft file bertanda tangan', 'late_reason' => 'Pengaju menyampaikan alasan keterlambatan'];

    protected $attributes = ['details' => '{}', 'attachments' => '{}', 'requirements' => '{}', 'history' => '[]', 'status' => 'draft'];

    protected function casts(): array
    {
        return ['details' => 'array', 'attachments' => 'array', 'requirements' => 'array', 'history' => 'array', 'status_changed_at' => 'datetime', 'draft_issued_at' => 'datetime', 'signed_uploaded_at' => 'datetime', 'physical_received_at' => 'datetime', 'has_cost' => 'boolean', 'start_date' => 'date', 'expiry_date' => 'date', 'target_date' => 'date', 'amount' => 'decimal:2'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class)->withTrashed();
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class)->withTrashed();
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id')->withTrashed();
    }

    public function pic(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pic_user_id')->withTrashed();
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id')->withTrashed();
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id')->withTrashed();
    }

    /**
     * Staf legal penerima notifikasi: pemeriksa pengajuan bila masih aktif (tidak di tempat sampah), punya izin Review, dan nomornya valid;
     * kalau tidak, semua user aktif pemegang `Review:DocumentRequest` dengan nomor valid.
     *
     * @return Collection<int, User>
     */
    public function legalStaffRecipients(): Collection
    {
        $reviewer = $this->reviewer;
        if ($reviewer !== null && $reviewer->isActive() && ! $reviewer->trashed() && $this->hasValidPhone($reviewer) && $this->canReview($reviewer)) {
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

    /** Tenggat unggah soft file bertanda tangan: 14 hari kalender (WIB) setelah draft pertama keluar, atau null bila draft belum keluar. */
    public function signedUploadDueDate(): ?CarbonImmutable
    {
        return $this->jakartaDate($this->draft_issued_at)?->addDays(self::SCAN_REMINDER_DAYS);
    }

    /** Tenggat penyerahan dokumen fisik: 7 hari kalender (WIB) setelah soft file bertanda tangan pertama diunggah, atau null bila belum ada. */
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

    /** Pengajuan menunggu soft file bertanda tangan, draf terbit lebih dari 14 hari lalu. */
    public function scopeScanOverdue(Builder $query): Builder
    {
        return $query->where('status', 'signing')->whereNull('signed_uploaded_at')->where('draft_issued_at', '<', self::lateBoundary(self::SCAN_REMINDER_DAYS));
    }

    /** Pengajuan terlambat yang belum punya alasan keterlambatan. */
    public function scopeLateWithoutReason(Builder $query): Builder
    {
        return $query->handoverLate()->whereDoesntHave('lateReasons');
    }

    /**
     * Batas awal hari WIB (dalam UTC) `$days` hari lalu; waktu sebelum batas ini sudah melewati tenggat.
     * Perbandingan SQL mengasumsikan timestamp tersimpan dalam UTC (`config('app.timezone') === 'UTC'`).
     */
    private static function lateBoundary(int $days = self::HANDOVER_DAYS): string
    {
        return CarbonImmutable::now(config('lms.reminder_timezone'))->startOfDay()->subDays($days)->utc()->toDateTimeString();
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

    /** Draf atau revisi milik pengaju sendiri; pengajuan di tempat sampah tidak bisa diubah sebelum dipulihkan. */
    public function editableBy(User $user): bool
    {
        return ! $this->trashed() && $this->requester_id === $user->id && in_array($this->status, ['draft', 'revision'], true);
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
        $stage = match (true) {
            in_array($this->status, ['cancelled', 'rejected'], true) => $this->lastStageBeforeCancellation(),
            in_array($this->status, self::PROCESSING_STATUSES, true) => 'processing',
            $this->status === 'archived' => 'archived',
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

    /** Tahap terakhir yang dicapai sebelum pembatalan atau penolakan, dibaca dari riwayat status: "Diproses" bila tim legal sudah mulai memprosesnya. */
    private function lastStageBeforeCancellation(): string
    {
        foreach ($this->history as $event) {
            if (! isset($event['event']) && in_array($event['status'] ?? null, self::PROCESSING_STATUSES, true)) {
                return 'processing';
            }
        }

        return 'submitted';
    }

    /**
     * Riwayat untuk pengaju, terbaru di atas. Kalimatnya menyebut peran pelaku (Pengaju, Tim Legal), tidak pernah nama staf.
     *
     * @return list<array{label: string, actor: string, at: ?CarbonImmutable, note: ?string, tone: 'normal'|'warning'|'danger'}>
     */
    public function publicTimeline(): array
    {
        $entries = [];
        $previous = null;
        foreach ($this->history as $event) {
            $kind = $event['event'] ?? null;
            $status = $event['status'] ?? null;
            $label = $kind !== null ? $this->timelineEventLabel($event) : ($status === 'draft' ? null : $this->timelineStatusLabel($event, $previous));
            if ($label !== null) {
                $entries[] = [
                    'label' => $label,
                    'actor' => $this->timelineActor($event, $previous),
                    'at' => isset($event['at']) ? CarbonImmutable::parse($event['at'])->timezone(config('lms.reminder_timezone')) : null,
                    'note' => filled($event['note'] ?? null) ? (string) $event['note'] : null,
                    'tone' => match (true) {
                        $kind === 'late_reason' => 'warning',
                        $kind !== null => 'normal',
                        $status === 'revision' => 'warning',
                        in_array($status, ['rejected', 'cancelled'], true) => 'danger',
                        default => 'normal',
                    },
                ];
            }
            if ($kind === null) {
                $previous = $status;
            }
        }

        return array_reverse($entries);
    }

    /**
     * Kalimat riwayat untuk satu perubahan status, atau null bila statusnya tidak dikenal. `$previous` adalah status sebelumnya:
     * pengajuan ulang setelah revisi, soft file yang diunggah tim legal, dan keputusan penyetuju di data lama punya kalimat sendiri.
     *
     * @param  array<string, mixed>  $event
     */
    public function timelineStatusLabel(array $event, ?string $previous = null): ?string
    {
        $status = $event['status'] ?? null;
        if (! is_string($status) || ! isset(self::TIMELINE_LABELS[$status])) {
            return null;
        }

        return match (true) {
            $status === 'submitted' && $previous === 'revision' => 'Pengaju mengirim revisi pengajuan',
            $status === 'handover' && ! $this->isApplicantUpload($event) => 'Tim Legal mengunggah soft file bertanda tangan, menunggu dokumen fisik',
            in_array($status, ['revision', 'rejected'], true) && $previous === 'review' => str_replace('Tim Legal', 'Penyetuju', self::TIMELINE_LABELS[$status]),
            default => self::TIMELINE_LABELS[$status],
        };
    }

    /**
     * Peran pelaku satu entri riwayat (bukan nama orang).
     *
     * @param  array<string, mixed>  $event
     */
    private function timelineActor(array $event, ?string $previous): string
    {
        $kind = $event['event'] ?? null;
        if ($kind !== null) {
            return in_array($kind, ['signed_replaced', 'late_reason'], true) && $this->isApplicantUpload($event) ? 'Pengaju' : 'Tim Legal';
        }

        return match ($event['status'] ?? null) {
            'submitted' => 'Pengaju',
            'review' => 'Pemeriksa',
            'approved' => 'Penyetuju',
            'revision', 'rejected' => $previous === 'review' ? 'Penyetuju' : 'Tim Legal',
            'handover' => $this->isApplicantUpload($event) ? 'Pengaju' : 'Tim Legal',
            default => 'Tim Legal',
        };
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
     * Kalimat kegiatan tanpa perubahan status (ganti draft/soft file, alasan terlambat), atau null bila bukan kegiatan yang ditampilkan.
     * Soft file yang diganti tim legal dan alasan terlambat yang dicatat tim legal atas nama pengaju punya kalimat tersendiri.
     *
     * @param  array<string, mixed>  $event
     */
    public function timelineEventLabel(array $event): ?string
    {
        $kind = $event['event'] ?? null;
        if (! is_string($kind) || ! isset(self::TIMELINE_EVENT_LABELS[$kind])) {
            return null;
        }

        return match (true) {
            $kind === 'signed_replaced' && ! $this->isApplicantUpload($event) => 'Tim Legal mengganti soft file bertanda tangan',
            $kind === 'late_reason' && ! $this->isApplicantUpload($event) => 'Tim Legal mencatat alasan keterlambatan pengaju',
            default => self::TIMELINE_EVENT_LABELS[$kind],
        };
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(DocumentRequestNotification::class);
    }

    public function applicantName(): string
    {
        return $this->requester_name ?? $this->requester?->name ?? 'Pengaju';
    }

    /** Nama pengaju untuk tampilan admin: pengaju internal di tempat sampah diberi tanda "(dihapus)". Pesan dan riwayat memakai applicantName(). */
    public function applicantDisplayName(): string
    {
        return $this->requester_name ?? $this->requester?->displayName() ?? 'Pengaju';
    }

    /** Pengaju internal di tempat sampah tidak menerima WhatsApp; pengaju publik memakai nomor yang tersimpan di pengajuan. */
    public function applicantReceivesWhatsApp(): bool
    {
        return $this->requester_phone !== null || ! $this->requester?->trashed();
    }

    /** Nomor WhatsApp pengaju: nomor pengajuan publik, atau nomor user pengaju (null bila user itu di tempat sampah). */
    public function applicantPhone(): ?string
    {
        return $this->requester_phone ?? $this->requester?->whatsAppRecipientPhone();
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
                Validator::make($request->attributesToArray(), ['company_id' => ['nullable', ActiveReference::rule('companies', $existing?->company_id, ['is_active' => true])], 'other_business_name' => [Rule::requiredIf($request->company_id === null), 'nullable', 'string', 'max:255']])->validate();
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
                    'company_id' => ['required', ActiveReference::rule('companies', $existing?->company_id, ['is_active' => true])],
                    'document_type_id' => ['required', ActiveReference::rule('document_types', $existing?->document_type_id)],
                    'pic_user_id' => ['required', ActiveReference::rule('users', $existing?->pic_user_id, ['deactivated_at' => null])], 'title' => ['required', 'string', 'max:255'],
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
        $type = DocumentType::withTrashed()->whereKey($this->document_type_id)->value('name');
        $directory = 'lms/requests/'.StorageFileName::folder($type);
        $filename = StorageFileName::available($disk, $directory, StorageFileName::build([
            StorageFileName::companyName(Company::withTrashed()->find($this->company_id)) ?? $this->other_business_name,
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
     * Pindahkan status: kirim pengajuan (`submitted`), mulai memeriksa (`reviewing`), mulai menyusun draft (`drafting`), revisi atau tolak saat diperiksa, atau batalkan saat revisi atau setelah draft keluar (`cancelled`).
     * Pembatalan tidak menghapus draf maupun scan; keduanya tetap ada sebagai catatan.
     */
    public function transition(string $next, User $user, ?string $note = null): void
    {
        DB::transaction(function () use ($next, $user, $note): void {
            $request = self::whereKey($this->id)->lockForUpdate()->firstOrFail();
            $allowed = match ($next) {
                'submitted' => $request->editableBy($user),
                'reviewing' => $request->status === 'submitted' && $request->requester_id !== $user->id,
                'drafting' => in_array($request->status, ['submitted', 'reviewing'], true) && $request->requester_id !== $user->id,
                'revision', 'rejected' => in_array($request->status, self::REVIEW_STATUSES, true) && $request->requester_id !== $user->id,
                'cancelled' => in_array($request->status, self::CANCELLABLE_STATUSES, true),
                default => false,
            };
            if (! $allowed) {
                throw ValidationException::withMessages(['status' => 'Tahap pengajuan tidak sesuai.']);
            }
            $ability = match ($next) {
                'submitted' => 'submit', 'reviewing' => 'startReview', 'drafting' => 'startDrafting', 'revision', 'rejected' => 'revise', 'cancelled' => 'cancel',
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
     * Keluarkan draf: dari Menunggu Diperiksa, Sedang Diperiksa, atau Proses Drafting ke Menunggu Tanda Tangan. Selama soft file bertanda tangan belum diunggah,
     * draf boleh diganti; penggantian tidak mengubah `draft_issued_at` sehingga batas 14 hari tidak mengulang.
     */
    public function issueDraft(UploadedFile $file, User $user): void
    {
        $this->saveFlowFile($file, 'draft', function (self $request) use ($user): void {
            $allowed = (in_array($request->status, self::REVIEW_STATUSES, true) && $request->requester_id !== $user->id)
                || ($request->status === 'signing' && $request->signed_path === null);
            if (! $allowed) {
                throw ValidationException::withMessages(['status' => 'Tahap pengajuan tidak sesuai.']);
            }
            Gate::forUser($user)->authorize('issueDraft', $request);
        }, function (self $request) use ($user): void {
            $request->draft_issued_at ??= now();
            if (in_array($request->status, self::REVIEW_STATUSES, true)) {
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
     * Fisik diterima: dokumen asli sudah kembali ke tim legal, pengajuan masuk tahap Scanning. Pengingat keterlambatan berhenti.
     * Soft file bertanda tangan dari pengaju tidak wajib; fisik boleh diterima walau pengaju belum mengunggahnya.
     * Pengaju tidak dikabari di tahap ini; pesan menyusul saat scan diunggah dan pengajuan selesai.
     */
    public function markReceived(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $request = self::whereKey($this->id)->lockForUpdate()->firstOrFail();
            if (! in_array($request->status, ['signing', 'handover'], true)) {
                throw ValidationException::withMessages(['status' => 'Tahap pengajuan tidak sesuai.']);
            }
            Gate::forUser($user)->authorize('markReceived', $request);
            $request->physical_received_at = now();
            $request->status = 'scanning';
            $daysLate = $request->daysLateAtReceipt();
            $request->recordEvent('scanning', $user, $daysLate > 0 ? "Diterima terlambat {$daysLate} hari" : null);
            $request->save();
            app(DocumentRequestNotifier::class)->enqueue($request);
        });
        $this->refresh();
    }

    /**
     * Unggah scan dokumen fisik dan selesaikan: scan disimpan di pengajuan (bisa diunduh pengaju), diarsipkan sebagai dokumen,
     * lalu pengajuan ditandai Selesai dan pengaju dikabari. Nomor dokumen boleh kosong (dilengkapi nanti di menu Dokumen);
     * tanggal terbit dan berakhir diambil dari pengajuan bila tidak diberikan.
     */
    public function completeWithScan(UploadedFile $scan, User $user, ?string $expiryDate = null, ?int $companyId = null, ?string $number = null, ?string $issuedDate = null): Document
    {
        /** @var list<array{0: string, 1: string}> $storedFiles */
        $storedFiles = [];

        try {
            return DB::transaction(function () use ($scan, $user, $expiryDate, $companyId, $number, $issuedDate, &$storedFiles): Document {
                $request = self::whereKey($this->id)->lockForUpdate()->firstOrFail();
                if ($request->status !== 'scanning') {
                    throw ValidationException::withMessages(['status' => 'Tahap pengajuan tidak sesuai.']);
                }
                Gate::forUser($user)->authorize('completeWithScan', $request);
                Validator::make(['scan' => $scan], ['scan' => ['required', File::types(config('lms.allowed_extensions'))->max(config('lms.max_upload_size_kb')), 'extensions:'.implode(',', config('lms.allowed_extensions'))]], [], ['scan' => 'Scan dokumen fisik'])->validate();
                $number = filled($number) ? $number : null;
                Validator::make(['number' => $number], ['number' => ['nullable', 'string', 'max:255']])->validate();
                $issuedDate ??= $request->start_date?->toDateString();
                $expiryDate ??= $request->expiry_date?->toDateString();
                $ownCompanyId = $request->company_id;
                $companyId = $ownCompanyId ?? $companyId;
                Validator::make(['company_id' => $companyId], ['company_id' => ['required', ActiveReference::rule('companies', $ownCompanyId, ['is_active' => true])]])->validate();
                $request->company_id = $companyId;

                $path = $request->storeFlowFile($scan, 'scan');
                $storedFiles[] = ['local', $path];
                $request->scan_path = $path;
                $request->scan_name = basename(str_replace('\\', '/', $scan->getClientOriginalName()));
                $file = new UploadedFile(Storage::disk('local')->path($path), $request->scan_name, null, null, true);

                // Badan usaha dan jenis dokumen yang sudah terpasang pada pengajuan tetap sah walau kini nonaktif atau di tempat sampah.
                $document = Document::archive(['company_id' => $request->company_id, 'document_type_id' => $request->document_type_id, 'pic_user_id' => $request->pic_user_id ?? $request->reviewer_id ?? $user->id, 'title' => $request->title, 'document_number' => $number, 'counterparty' => $request->counterpartyName(), 'issued_date' => $issuedDate, 'expiry_date' => $expiryDate, 'notes' => 'Pengajuan #'.$request->id], $file, $user, ['company_id' => $ownCompanyId, 'document_type_id' => $request->document_type_id]);
                $storedFiles[] = [$document->currentVersion->disk, $document->currentVersion->file_path];
                $request->document_id = $document->id;
                $request->status = 'archived';
                $request->recordEvent('archived', $user);
                $request->save();
                app(DocumentRequestNotifier::class)->enqueue($request);
                $this->refresh();

                return $document;
            });
        } catch (Throwable $exception) {
            // File sudah tersimpan di disk sebelum DB di-rollback; hapus agar tidak tertinggal.
            foreach ($storedFiles as [$disk, $path]) {
                try {
                    Storage::disk($disk)->delete($path);
                } catch (Throwable $cleanupException) {
                    report($cleanupException);
                }
            }

            throw $exception;
        }
    }

    /**
     * Badge tambahan di samping status agar langkah yang belum beres terlihat dari daftar: "Belum unggah soft file" saat menunggu
     * tanda tangan, "Fisik belum diterima" saat menunggu dokumen fisik, "Scan belum diunggah" saat Scanning.
     * Kosong untuk status lain dan untuk pengajuan di tempat sampah.
     *
     * @return list<string>
     */
    public function pendingBadges(): array
    {
        if ($this->trashed()) {
            return [];
        }

        return match ($this->status) {
            'signing' => blank($this->signed_path) ? [self::BADGE_NO_SCAN] : [],
            'handover' => [self::BADGE_NOT_RECEIVED],
            'scanning' => [self::BADGE_NO_PHYSICAL_SCAN],
            default => [],
        };
    }

    /**
     * Status ditambah badge langkah yang belum beres, untuk kolom Status di daftar dan Ringkasan di halaman detail.
     *
     * @return list<string>
     */
    public function statusBadges(): array
    {
        return [self::statusLabel($this->status), ...$this->pendingBadges()];
    }

    /** Warna satu badge dari statusBadges(): status mengikuti warnanya, badge langkah kuning, dan merah bila fisik sudah terlambat. */
    public function statusBadgeColor(string $badge): string
    {
        return match ($badge) {
            self::BADGE_NO_SCAN, self::BADGE_NO_PHYSICAL_SCAN => 'warning',
            self::BADGE_NOT_RECEIVED => $this->isHandoverLate() ? 'danger' : 'warning',
            default => match ($this->status) {
                'archived' => 'success', 'rejected', 'cancelled' => 'danger', 'revision' => 'warning', 'draft' => 'gray', default => 'info',
            },
        };
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
     * Simpan draf (`draft`), soft file bertanda tangan dari pengaju (`signed`), atau scan dokumen fisik dari legal (`scan`)
     * di `lms/requests/{jenis-dokumen}/`.
     *
     * @param  'draft'|'signed'|'scan'  $kind
     */
    private function storeFlowFile(UploadedFile $file, string $kind): string
    {
        $disk = Storage::disk('local');
        $type = DocumentType::withTrashed()->whereKey($this->document_type_id)->value('name');
        $directory = 'lms/requests/'.StorageFileName::folder($type);
        $filename = StorageFileName::available($disk, $directory, StorageFileName::build([
            StorageFileName::companyName(Company::withTrashed()->find($this->company_id)) ?? $this->other_business_name,
            $type,
            match ($kind) {
                'draft' => 'draft', 'scan' => 'scan fisik', default => 'ttd',
            },
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
