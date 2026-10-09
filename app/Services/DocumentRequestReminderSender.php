<?php

namespace App\Services;

use App\Models\DocumentRequest;
use App\Models\DocumentRequestNotification;
use App\Models\ReminderTemplate;
use App\Models\User;
use Illuminate\Support\Collection;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

class DocumentRequestReminderSender
{
    public function __construct(private WaghubService $waghub) {}

    /**
     * Jadwalkan pengingat untuk pengajuan yang menunggu tindakan terlalu lama.
     * Pesan masuk antrean notifikasi pengajuan dan dikirim oleh lms:send-request-notifications.
     *
     * @return int Jumlah notifikasi baru yang dibuat.
     */
    public function run(): int
    {
        $settings = ReminderTemplate::requestReminderSettings();
        if (! $settings['enabled']) {
            return 0;
        }
        $body = ReminderTemplate::globalSetting()?->requestReminderBody() ?? ReminderTemplate::DEFAULT_REQUEST_REMINDER_BODY;
        $created = 0;
        DocumentRequest::with(['company', 'documentType', 'pic', 'requester', 'reviewer', 'approver'])
            ->whereIn('status', DocumentRequest::WAITING_STATUSES)
            ->each(function (DocumentRequest $request) use ($settings, $body, &$created): void {
                $waitingDays = (int) $request->waitingSince()->diffInDays(now());
                if ($waitingDays < $settings['after_days']) {
                    return;
                }
                $sequence = min($settings['max'], intdiv($waitingDays - $settings['after_days'], $settings['interval_days']) + 1);
                $kind = $request->status === 'submitted' ? 'reviewer' : 'approver';
                foreach ($this->recipients($request) as $user) {
                    $key = 'lms-request-'.$request->id.'-'.count($request->history)."-stale{$sequence}-u{$user->id}";
                    $phone = $this->waghub->normalizePhone($user->phone);
                    $notification = DocumentRequestNotification::firstOrCreate(['event_key' => $key], [
                        'document_request_id' => $request->id, 'recipient_kind' => $kind, 'recipient_phone' => $phone,
                        'payload' => [
                            'recipient' => ['type' => 'phone', 'value' => $phone],
                            'message' => ['type' => 'text', 'text' => $this->message($request, $body, $waitingDays)],
                            'purpose' => config('services.waghub.purpose'), 'mode' => config('services.waghub.mode'), 'route_key' => config('services.waghub.route_key'),
                            'expires_at' => now()->addDay()->toIso8601String(), 'client_reference' => $key,
                        ],
                    ]);
                    if ($notification->wasRecentlyCreated) {
                        $created++;
                    }
                }
            });

        return $created;
    }

    /** @return Collection<int, User> */
    private function recipients(DocumentRequest $request): Collection
    {
        if ($request->status === 'submitted') {
            return collect([$request->reviewer ?? $request->pic])->filter();
        }
        if ($request->approver !== null) {
            return collect([$request->approver]);
        }
        try {
            return User::permission('Approve:DocumentRequest')->orderBy('id')->get();
        } catch (PermissionDoesNotExist) {
            return collect();
        }
    }

    private function message(DocumentRequest $request, string $body, int $waitingDays): string
    {
        return ReminderTemplate::renderBody($body, [
            'nomor_pengajuan' => (string) $request->id, 'judul' => $request->title, 'pengaju' => $request->applicantName(),
            'perusahaan' => $request->company?->name ?? $request->other_business_name ?? '-', 'jenis_dokumen' => $request->documentType?->name ?? '-',
            'status' => DocumentRequest::STATUSES[$request->status], 'hari_menunggu' => (string) $waitingDays,
            'tautan' => route('filament.admin.resources.document-requests.edit', ['record' => $request->id]),
        ]);
    }
}
