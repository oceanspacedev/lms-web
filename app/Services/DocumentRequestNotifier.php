<?php

namespace App\Services;

use App\Models\DocumentRequest;
use App\Models\DocumentRequestNotification;
use App\Models\ReminderTemplate;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

use function Illuminate\Support\defer;

class DocumentRequestNotifier
{
    public function __construct(private WaghubService $waghub) {}

    public function enqueue(DocumentRequest $request): void
    {
        $event = count($request->history);
        $request->notifications()->whereIn('status', ['pending', 'failed'])->where('event_key', 'not like', "lms-request-{$request->id}-{$event}-%")->update(['status' => 'cancelled']);
        $recipients = ['applicant' => $request->requester_phone ?? $request->requester?->phone];
        $setting = ReminderTemplate::globalSetting();
        if ($request->status === 'submitted') {
            $recipients['pic'] = $request->pic?->phone;
        }
        foreach ($recipients as $kind => $number) {
            $key = "lms-request-{$request->id}-{$event}-{$kind}";
            $phone = $this->waghub->normalizePhone($number);
            $link = $kind === 'pic'
                ? route('filament.admin.resources.document-requests.edit', ['record' => $request->id])
                : ($request->public_token ? route('requests.public.status', ['token' => $request->public_token]) : route('filament.admin.resources.document-requests.edit', ['record' => $request->id]));
            $note = collect($request->history)->last()['note'] ?? null;
            $templateEvent = $kind === 'pic' ? 'pic' : $request->status;
            $body = $setting?->requestTemplate($templateEvent) ?? ReminderTemplate::DEFAULT_REQUEST_TEMPLATES[$templateEvent] ?? "Pengajuan #{nomor_pengajuan}\n{judul}\n{status}{baris_catatan}\n{tautan}";
            $text = ReminderTemplate::renderBody($body, [
                'nomor_pengajuan' => (string) $request->id, 'judul' => $request->title,
                'pengaju' => $request->applicantName(), 'perusahaan' => $request->company?->name ?? $request->other_business_name ?? '-',
                'jenis_dokumen' => $request->documentType?->name ?? '-', 'pic' => $request->pic?->name ?? '-',
                'status' => DocumentRequest::STATUSES[$request->status], 'catatan' => (string) $note,
                'baris_catatan' => filled($note) ? "\nCatatan: {$note}" : '', 'tautan' => $link,
            ]);
            DocumentRequestNotification::firstOrCreate(['event_key' => $key], [
                'document_request_id' => $request->id, 'recipient_kind' => $kind, 'recipient_phone' => $phone,
                'payload' => ['recipient' => ['type' => 'phone', 'value' => $phone], 'message' => ['type' => 'text', 'text' => $text],
                    'purpose' => config('services.waghub.purpose'), 'mode' => config('services.waghub.mode'), 'route_key' => config('services.waghub.route_key'),
                    'expires_at' => now()->addDay()->toIso8601String(), 'client_reference' => $key],
            ]);
        }
        DB::afterCommit(function () use ($request): void {
            defer(function () use ($request): void {
                try {
                    $this->run($request->id);
                } catch (Throwable $exception) {
                    report($exception);
                }
            });
        });
    }

    /**
     * Kirim ulang notifikasi yang gagal atas permintaan user. Percobaan direset dan kunci idempotensi tetap sama.
     * Nomor penerima dihitung ulang dari data saat ini, sehingga nomor yang sudah diperbaiki ikut terpakai.
     * Notifikasi untuk tahap yang sudah terlewati dibatalkan, bukan dikirim.
     *
     * @return 'accepted'|'failed'|'cancelled'|null Null bila notifikasi bukan berstatus gagal.
     */
    public function resend(DocumentRequestNotification $notification, User $user): ?string
    {
        $this->waghub->assertConfigured();
        if ($notification->status !== 'failed') {
            return null;
        }
        $phone = $this->currentPhone($notification) ?? $notification->recipient_phone;
        $payload = $notification->payload;
        $payload['recipient'] = ['type' => 'phone', 'value' => $phone];
        $notification->update([
            'status' => 'pending', 'attempts' => 0, 'last_attempt_at' => null, 'error_message' => null,
            'recipient_phone' => $phone, 'payload' => $payload, 'resent_by' => $user->id, 'resent_at' => now(),
        ]);
        $this->run($notification->document_request_id, $notification->id);

        return match ($notification->fresh()->status) {
            'accepted' => 'accepted', 'cancelled' => 'cancelled', default => 'failed',
        };
    }

    private function currentPhone(DocumentRequestNotification $notification): ?string
    {
        $request = DocumentRequest::find($notification->document_request_id);
        $number = match ($notification->recipient_kind) {
            'applicant' => $request?->requester_phone ?? $request?->requester?->phone,
            'pic' => $request?->pic?->phone,
            default => preg_match('/-u(\d+)$/', $notification->event_key, $matches) === 1 ? User::find((int) $matches[1])?->phone : null,
        };

        return $this->waghub->normalizePhone($number);
    }

    public function run(?int $requestId = null, ?int $notificationId = null): int
    {
        $accepted = 0;
        DocumentRequestNotification::whereIn('status', ['pending', 'failed'])->where('attempts', '<', 5)
            ->when($requestId, fn (Builder $query): Builder => $query->where('document_request_id', $requestId))
            ->when($notificationId, fn (Builder $query): Builder => $query->whereKey($notificationId))
            ->where(fn (Builder $query): Builder => $query->whereNull('last_attempt_at')->orWhere('last_attempt_at', '<=', now()->subMinutes(5)))
            ->orderBy('id')->limit(50)->get()->each(function (DocumentRequestNotification $notification) use (&$accepted): void {
                $request = DocumentRequest::find($notification->document_request_id);
                if (! $request || ! str_starts_with($notification->event_key, 'lms-request-'.$request->id.'-'.count($request->history).'-')) {
                    $notification->update(['status' => 'cancelled']);

                    return;
                }
                $claimed = DocumentRequestNotification::whereKey($notification->id)->whereIn('status', ['pending', 'failed'])->where('attempts', '<', 5)
                    ->where(fn (Builder $query): Builder => $query->whereNull('last_attempt_at')->orWhere('last_attempt_at', '<=', now()->subMinutes(5)))
                    ->update(['last_attempt_at' => now(), 'attempts' => DB::raw('attempts + 1')]);
                if (! $claimed) {
                    return;
                }
                try {
                    if (! $notification->recipient_phone) {
                        throw new \RuntimeException('Nomor WhatsApp belum tersedia atau tidak valid.');
                    }
                    $payload = $notification->payload;
                    if (blank($payload['purpose'] ?? null)) {
                        $payload['purpose'] = config('services.waghub.purpose');
                        $notification->update(['payload' => $payload]);
                    }
                    $response = $this->waghub->send($payload, $notification->event_key);
                    if (! $response->successful()) {
                        throw new \RuntimeException('WagHub menolak permintaan (HTTP '.$response->status().').');
                    }
                    $notification->update(['status' => 'accepted', 'accepted_at' => now(), 'error_message' => null]);
                    $accepted++;
                } catch (Throwable $exception) {
                    $notification->update(['status' => 'failed', 'error_message' => 'Pengiriman WhatsApp gagal. Akan dicoba kembali.']);
                }
            });

        return $accepted;
    }
}
