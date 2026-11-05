<?php

namespace App\Services;

use App\Models\DocumentRequest;
use App\Models\DocumentRequestNotification;
use App\Models\ReminderTemplate;
use App\Models\User;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class HandoverReminderSender
{
    private const RELATIONS = ['company', 'documentType', 'pic', 'requester', 'reviewer'];

    public function __construct(private WaghubService $waghub) {}

    /**
     * Jadwalkan pengingat keterlambatan dokumen fisik dan pengingat unggah scan.
     * Angka hari tetap dan tidak ikut sakelar pengingat pengajuan. Pesan masuk antrean notifikasi pengajuan
     * dan dikirim oleh lms:send-request-notifications.
     *
     * @return int Jumlah notifikasi baru yang dibuat.
     */
    public function run(?CarbonImmutable $today = null): int
    {
        $created = 0;
        $setting = ReminderTemplate::globalSetting();
        foreach ($this->dueReminders(DocumentRequest::query(), $today) as $reminder) {
            $created += $this->queue($reminder, $setting);
        }

        return $created;
    }

    /**
     * Dipanggil saat pengajuan pulih dari tempat sampah: tahap yang sudah jatuh tempo dicatat sebagai notifikasi `cancelled`
     * dengan kunci yang sama seperti buatan run(), supaya run() berikutnya tidak mengirimnya. Tahap berikutnya tetap terkirim.
     *
     * @return int Jumlah notifikasi yang dicatat terlewat.
     */
    public function skipStagesAlreadyReached(DocumentRequest $request): int
    {
        $skipped = 0;
        $setting = ReminderTemplate::globalSetting();
        foreach ($this->dueReminders(DocumentRequest::query()->whereKey($request->getKey()), null) as $reminder) {
            $skipped += $this->queue($reminder, $setting, ['status' => 'cancelled', 'error_message' => DocumentRequestNotification::SKIPPED_WHILE_TRASHED_MESSAGE]);
        }

        return $skipped;
    }

    /**
     * Pengingat yang jatuh tempo untuk pengajuan di `$query`: tahap keterlambatan tertinggi yang sudah terlewati, dan pengingat unggah scan.
     *
     * @param  Builder<DocumentRequest>  $query
     * @return Generator<int, array{request: DocumentRequest, keyPart: string, template: string, targets: list<array{kind: string, suffix: string, phone: ?string, link: string, template?: string}>}>
     */
    private function dueReminders(Builder $query, ?CarbonImmutable $today): Generator
    {
        foreach ((clone $query)->with(self::RELATIONS)->handoverLate()->lazyById() as $request) {
            $stage = $this->highestReachedStage($request->daysSinceSigned($today) ?? 0);
            if ($stage === null) {
                continue;
            }
            $targets = $this->applicantTargets($request);
            $adminLink = route('filament.admin.resources.document-requests.edit', ['record' => $request->id]);
            if ($stage >= 14) {
                $targets = [...$targets, ...$this->userTargets($request->legalStaffRecipients(), 'legal', $adminLink, 'late_staff')];
            }
            if ($stage >= 28) {
                $targets = [...$targets, ...$this->userTargets($request->coordinatorRecipients(), 'coordinator', $adminLink, 'late_staff')];
            }

            yield ['request' => $request, 'keyPart' => "late{$stage}", 'template' => "late_{$stage}", 'targets' => $targets];
        }
        foreach ((clone $query)->with(self::RELATIONS)->scanOverdue()->lazyById() as $request) {
            yield ['request' => $request, 'keyPart' => 'scan'.DocumentRequest::SCAN_REMINDER_DAYS, 'template' => 'scan_reminder', 'targets' => $this->applicantTargets($request)];
        }
    }

    /** Tahap tertinggi yang sudah terlewati: angka terbesar di LATE_REMINDER_DAYS yang lebih kecil dari jumlah hari. */
    private function highestReachedStage(int $daysSinceSigned): ?int
    {
        return collect(DocumentRequest::LATE_REMINDER_DAYS)->filter(fn (int $days): bool => $days < $daysSinceSigned)->max();
    }

    /**
     * Masukkan pengingat ke antrean, sekali per kunci. `$attributes` menimpa isian baris baru, misalnya status `cancelled`.
     *
     * @param  array{request: DocumentRequest, keyPart: string, template: string, targets: list<array{kind: string, suffix: string, phone: ?string, link: string, template?: string}>}  $reminder  `template` pada target menggantikan template pengingat untuk penerima itu.
     * @param  array<string, mixed>  $attributes
     */
    private function queue(array $reminder, ?ReminderTemplate $setting, array $attributes = []): int
    {
        $created = 0;
        $request = $reminder['request'];
        $stageStart = $this->stageStart($request);
        foreach ($reminder['targets'] as $target) {
            $key = "lms-request-{$request->id}-{$stageStart}-{$reminder['keyPart']}-{$target['suffix']}";
            $phone = $this->waghub->normalizePhone($target['phone']);
            $notification = DocumentRequestNotification::firstOrCreate(['event_key' => $key], [
                'document_request_id' => $request->id, 'recipient_kind' => $target['kind'], 'recipient_phone' => $phone,
                'payload' => [
                    'recipient' => ['type' => 'phone', 'value' => $phone],
                    'message' => ['type' => 'text', 'text' => DocumentRequestNotifier::renderTemplate($request, $target['template'] ?? $reminder['template'], $target['link'], null, $setting)],
                    'purpose' => config('services.waghub.purpose'), 'mode' => config('services.waghub.mode'), 'route_key' => config('services.waghub.route_key'),
                    'expires_at' => now()->addDay()->toIso8601String(), 'client_reference' => $key,
                ],
                ...$attributes,
            ]);
            if ($notification->wasRecentlyCreated) {
                $created++;
            }
        }

        return $created;
    }

    /**
     * Nomor riwayat tempat tahap saat ini dimulai, sama dengan yang dipakai DocumentRequestNotifier untuk menilai
     * notifikasi usang. Peristiwa tanpa perubahan status (ganti scan, alasan terlambat) tidak menggeser angka ini.
     */
    private function stageStart(DocumentRequest $request): int
    {
        $stageStart = 0;
        foreach (array_values($request->history ?? []) as $index => $entry) {
            if (! isset($entry['event'])) {
                $stageStart = $index + 1;
            }
        }

        return $stageStart;
    }

    /**
     * Pengaju sebagai penerima; kosong bila pengaju internal ada di tempat sampah.
     *
     * @return list<array{kind: string, suffix: string, phone: ?string, link: string}>
     */
    private function applicantTargets(DocumentRequest $request): array
    {
        if (! $request->applicantReceivesWhatsApp()) {
            return [];
        }

        return [['kind' => 'applicant', 'suffix' => 'applicant', 'phone' => $request->applicantPhone(), 'link' => $this->applicantLink($request)]];
    }

    private function applicantLink(DocumentRequest $request): string
    {
        return $request->public_token
            ? route('requests.public.status', ['token' => $request->public_token])
            : route('filament.admin.resources.document-requests.edit', ['record' => $request->id]);
    }

    /**
     * User di tempat sampah tidak dijadikan penerima.
     *
     * @param  Collection<int, User>  $users
     * @return list<array{kind: string, suffix: string, phone: ?string, link: string, template: string}>
     */
    private function userTargets(Collection $users, string $kind, string $link, string $template): array
    {
        return $users->reject(fn (User $user): bool => $user->trashed())
            ->map(fn (User $user): array => ['kind' => $kind, 'suffix' => "u{$user->id}", 'phone' => $user->phone, 'link' => $link, 'template' => $template])->values()->all();
    }
}
