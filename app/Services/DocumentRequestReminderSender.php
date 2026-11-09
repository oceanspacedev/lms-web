<?php

namespace App\Services;

use App\Models\DocumentRequest;
use App\Models\DocumentRequestNotification;
use App\Models\ReminderTemplate;
use App\Models\User;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

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
        $created = 0;
        $body = ReminderTemplate::globalSetting()?->requestReminderBody() ?? ReminderTemplate::DEFAULT_REQUEST_REMINDER_BODY;
        foreach ($this->dueReminders(DocumentRequest::query()) as $reminder) {
            $created += $this->queue($reminder, $body);
        }

        return $created;
    }

    /**
     * Dipanggil saat pengajuan pulih dari tempat sampah: pengingat yang sudah jatuh tempo dicatat sebagai notifikasi `cancelled`
     * dengan kunci yang sama seperti buatan run(), supaya tidak langsung menyusul. Pengingat berikutnya mengikuti jadwal.
     *
     * @return int Jumlah notifikasi yang dicatat terlewat.
     */
    public function skipDueReminders(DocumentRequest $request): int
    {
        $skipped = 0;
        $body = ReminderTemplate::globalSetting()?->requestReminderBody() ?? ReminderTemplate::DEFAULT_REQUEST_REMINDER_BODY;
        foreach ($this->dueReminders(DocumentRequest::query()->whereKey($request->getKey())) as $reminder) {
            $skipped += $this->queue($reminder, $body, ['status' => 'cancelled', 'error_message' => DocumentRequestNotification::SKIPPED_WHILE_TRASHED_MESSAGE]);
        }

        return $skipped;
    }

    /**
     * Pengingat ke-`sequence` yang jatuh tempo untuk pengajuan di `$query`; kosong bila pengingat pengajuan dimatikan.
     *
     * @param  Builder<DocumentRequest>  $query
     * @return Generator<int, array{request: DocumentRequest, sequence: int, waitingDays: int}>
     */
    private function dueReminders(Builder $query): Generator
    {
        $settings = ReminderTemplate::requestReminderSettings();
        if (! $settings['enabled']) {
            return;
        }
        $requests = $query->with(['company', 'documentType', 'pic', 'requester', 'reviewer', 'approver'])->whereIn('status', DocumentRequest::WAITING_STATUSES);
        foreach ($requests->lazyById() as $request) {
            $waitingDays = (int) $request->waitingSince()->diffInDays(now());
            if ($waitingDays < $settings['after_days']) {
                continue;
            }

            yield ['request' => $request, 'sequence' => min($settings['max'], intdiv($waitingDays - $settings['after_days'], $settings['interval_days']) + 1), 'waitingDays' => $waitingDays];
        }
    }

    /**
     * Masukkan pengingat ke antrean, sekali per kunci. `$attributes` menimpa isian baris baru, misalnya status `cancelled`.
     *
     * @param  array{request: DocumentRequest, sequence: int, waitingDays: int}  $reminder
     * @param  array<string, mixed>  $attributes
     */
    private function queue(array $reminder, string $body, array $attributes = []): int
    {
        $created = 0;
        $request = $reminder['request'];
        foreach ($this->recipients($request) as $user) {
            $key = 'lms-request-'.$request->id.'-'.count($request->history)."-stale{$reminder['sequence']}-u{$user->id}";
            $phone = $this->waghub->normalizePhone($user->phone);
            $notification = DocumentRequestNotification::firstOrCreate(['event_key' => $key], [
                'document_request_id' => $request->id, 'recipient_kind' => 'reviewer', 'recipient_phone' => $phone,
                'payload' => [
                    'recipient' => ['type' => 'phone', 'value' => $phone],
                    'message' => ['type' => 'text', 'text' => $this->message($request, $body, $reminder['waitingDays'])],
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
     * Pemeriksa, atau PIC bila belum ada pemeriksa. User di tempat sampah tidak dijadikan penerima.
     *
     * @return Collection<int, User>
     */
    private function recipients(DocumentRequest $request): Collection
    {
        return collect([$request->reviewer ?? $request->pic])->filter(fn (?User $user): bool => $user !== null && ! $user->trashed());
    }

    private function message(DocumentRequest $request, string $body, int $waitingDays): string
    {
        return ReminderTemplate::renderBody($body, [
            'nomor_pengajuan' => (string) $request->id, 'judul' => $request->title, 'pengaju' => $request->applicantName(),
            'perusahaan' => $request->company?->name ?? $request->other_business_name ?? '-', 'jenis_dokumen' => $request->documentType?->name ?? '-',
            'status' => DocumentRequest::statusLabel($request->status), 'hari_menunggu' => (string) $waitingDays,
            'tautan' => route('filament.admin.resources.document-requests.edit', ['record' => $request->id]),
        ]);
    }
}
