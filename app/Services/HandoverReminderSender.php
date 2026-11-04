<?php

namespace App\Services;

use App\Models\DocumentRequest;
use App\Models\DocumentRequestNotification;
use App\Models\ReminderTemplate;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class HandoverReminderSender
{
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
        $relations = ['company', 'documentType', 'pic', 'requester', 'reviewer'];
        DocumentRequest::with($relations)->handoverLate()->each(function (DocumentRequest $request) use ($today, $setting, &$created): void {
            $stage = $this->highestReachedStage($request->daysSinceSigned($today) ?? 0);
            if ($stage === null) {
                return;
            }
            $targets = [['kind' => 'applicant', 'suffix' => 'applicant', 'phone' => $this->applicantPhone($request), 'link' => $this->applicantLink($request)]];
            $adminLink = route('filament.admin.resources.document-requests.edit', ['record' => $request->id]);
            if ($stage >= 14) {
                $targets = [...$targets, ...$this->userTargets($request->legalStaffRecipients(), 'legal', $adminLink)];
            }
            if ($stage >= 28) {
                $targets = [...$targets, ...$this->userTargets($request->coordinatorRecipients(), 'coordinator', $adminLink)];
            }
            $created += $this->queue($request, "late{$stage}", "late_{$stage}", $targets, $setting);
        });
        DocumentRequest::with($relations)->scanOverdue()->each(function (DocumentRequest $request) use ($setting, &$created): void {
            $targets = [['kind' => 'applicant', 'suffix' => 'applicant', 'phone' => $this->applicantPhone($request), 'link' => $this->applicantLink($request)]];
            $created += $this->queue($request, 'scan'.DocumentRequest::SCAN_REMINDER_DAYS, 'scan_reminder', $targets, $setting);
        });

        return $created;
    }

    /** Tahap tertinggi yang sudah terlewati: angka terbesar di LATE_REMINDER_DAYS yang lebih kecil dari jumlah hari. */
    private function highestReachedStage(int $daysSinceSigned): ?int
    {
        return collect(DocumentRequest::LATE_REMINDER_DAYS)->filter(fn (int $days): bool => $days < $daysSinceSigned)->max();
    }

    /**
     * @param  array<int, array{kind: string, suffix: string, phone: ?string, link: string}>  $targets
     */
    private function queue(DocumentRequest $request, string $keyPart, string $template, array $targets, ?ReminderTemplate $setting): int
    {
        $created = 0;
        $stageStart = $this->stageStart($request);
        foreach ($targets as $target) {
            $key = "lms-request-{$request->id}-{$stageStart}-{$keyPart}-{$target['suffix']}";
            $phone = $this->waghub->normalizePhone($target['phone']);
            $notification = DocumentRequestNotification::firstOrCreate(['event_key' => $key], [
                'document_request_id' => $request->id, 'recipient_kind' => $target['kind'], 'recipient_phone' => $phone,
                'payload' => [
                    'recipient' => ['type' => 'phone', 'value' => $phone],
                    'message' => ['type' => 'text', 'text' => DocumentRequestNotifier::renderTemplate($request, $template, $target['link'], null, $setting)],
                    'purpose' => config('services.waghub.purpose'), 'mode' => config('services.waghub.mode'), 'route_key' => config('services.waghub.route_key'),
                    'expires_at' => now()->addDay()->toIso8601String(), 'client_reference' => $key,
                ],
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

    private function applicantPhone(DocumentRequest $request): ?string
    {
        return $request->requester_phone ?? $request->requester?->phone;
    }

    private function applicantLink(DocumentRequest $request): string
    {
        return $request->public_token
            ? route('requests.public.status', ['token' => $request->public_token])
            : route('filament.admin.resources.document-requests.edit', ['record' => $request->id]);
    }

    /**
     * @param  Collection<int, User>  $users
     * @return list<array{kind: string, suffix: string, phone: ?string, link: string}>
     */
    private function userTargets(Collection $users, string $kind, string $link): array
    {
        return $users->map(fn ($user): array => ['kind' => $kind, 'suffix' => "u{$user->id}", 'phone' => $user->phone, 'link' => $link])->all();
    }
}
