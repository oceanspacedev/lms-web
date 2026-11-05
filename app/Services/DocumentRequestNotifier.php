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
    private int $failedInRun = 0;

    public function __construct(private WaghubService $waghub) {}

    /** Jumlah notifikasi yang gagal terkirim pada pemanggilan run() terakhir. */
    public function failedInLastRun(): int
    {
        return $this->failedInRun;
    }

    public function enqueue(DocumentRequest $request): void
    {
        $last = collect($request->history)->last();
        // Ganti scan dan alasan terlambat hanya dicatat; tidak ada pesan, dan pesan yang masih antre tidak ikut dibatalkan.
        if (in_array($last['event'] ?? null, ['signed_replaced', 'late_reason'], true)) {
            return;
        }
        $event = count($request->history);
        $request->notifications()->whereIn('status', ['pending', 'failed'])->where('event_key', 'not like', "lms-request-{$request->id}-{$event}-%")->update(['status' => 'cancelled']);
        $adminLink = route('filament.admin.resources.document-requests.edit', ['record' => $request->id]);
        $applicantLink = $request->public_token ? route('requests.public.status', ['token' => $request->public_token]) : $adminLink;
        $setting = ReminderTemplate::globalSetting();
        $applicantEvent = ($last['event'] ?? null) === 'draft_replaced' ? 'draft_replaced' : $request->status;
        // User di tempat sampah (pengaju internal, PIC, staf legal) tidak pernah dijadikan penerima.
        $targets = [];
        if ($request->applicantReceivesWhatsApp()) {
            $targets[] = ['kind' => 'applicant', 'suffix' => 'applicant', 'phone' => $request->applicantPhone(), 'template' => $request->status === 'handover' ? 'handover' : $applicantEvent, 'link' => $applicantLink];
        }
        if ($request->status === 'handover') {
            foreach ($request->legalStaffRecipients() as $user) {
                $targets[] = ['kind' => 'legal', 'suffix' => "u{$user->id}", 'phone' => $user->whatsAppRecipientPhone(), 'template' => 'handover_staff', 'link' => $adminLink];
            }
        }
        if ($request->status === 'submitted' && ! $request->pic?->trashed()) {
            $targets[] = ['kind' => 'pic', 'suffix' => 'pic', 'phone' => $request->pic?->phone, 'template' => 'pic', 'link' => $adminLink];
        }
        $note = $last['note'] ?? null;
        foreach ($targets as $target) {
            $key = "lms-request-{$request->id}-{$event}-{$target['suffix']}";
            $phone = $this->waghub->normalizePhone($target['phone']);
            $text = self::renderTemplate($request, $target['template'], $target['link'], $note, $setting);
            DocumentRequestNotification::firstOrCreate(['event_key' => $key], [
                'document_request_id' => $request->id, 'recipient_kind' => $target['kind'], 'recipient_phone' => $phone,
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

    /** Isi pesan dari templat pengajuan (pengaturan global atau bawaan) dengan variabel pengajuan. */
    public static function renderTemplate(DocumentRequest $request, string $templateKey, string $link, ?string $note = null, ?ReminderTemplate $setting = null): string
    {
        $setting ??= ReminderTemplate::globalSetting();
        $body = $setting?->requestTemplate($templateKey) ?? ReminderTemplate::DEFAULT_REQUEST_TEMPLATES[$templateKey] ?? "Pengajuan #{nomor_pengajuan}\n{judul}\n{status}{baris_catatan}\n{tautan}";

        return ReminderTemplate::renderBody($body, [
            'nomor_pengajuan' => (string) $request->id, 'judul' => $request->title,
            'pengaju' => $request->applicantName(), 'perusahaan' => $request->company?->name ?? $request->other_business_name ?? '-',
            'jenis_dokumen' => $request->documentType?->name ?? '-', 'pic' => $request->pic?->name ?? '-',
            'status' => DocumentRequest::statusLabel($request->status), 'catatan' => (string) $note,
            'baris_catatan' => filled($note) ? "\nCatatan: {$note}" : '', 'tautan' => $link,
            'batas_waktu' => $request->handoverDueDate()?->translatedFormat('d M Y') ?? '-', 'hari_terlambat' => (string) $request->daysLate(),
            'alasan_terlambat' => str_contains($body, '{alasan_terlambat}')
                ? ($request->lateReasons()->reorder()->latest('id')->value('reason') ?? 'belum ada') : '',
        ]);
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
            'applicant' => $request?->applicantPhone(),
            'pic' => $request?->pic?->whatsAppRecipientPhone(),
            default => preg_match('/-u(\d+)$/', $notification->event_key, $matches) === 1 ? User::find((int) $matches[1])?->phone : null,
        };

        return $this->waghub->normalizePhone($number);
    }

    /**
     * Notifikasi sudah usang bila dibuat sebelum perubahan status terakhir. Kegiatan tanpa perubahan status
     * (ganti scan, alasan terlambat) menambah riwayat tetapi tidak membuat notifikasi yang antre menjadi usang.
     */
    private function isSuperseded(DocumentRequestNotification $notification, DocumentRequest $request): bool
    {
        if (preg_match('/^lms-request-'.$request->id.'-(\d+)-/', $notification->event_key, $matches) !== 1) {
            return true;
        }
        $history = array_values($request->history);
        $stageStart = 0;
        foreach ($history as $index => $entry) {
            if (! isset($entry['event'])) {
                $stageStart = $index + 1;
            }
        }

        return (int) $matches[1] < $stageStart || (int) $matches[1] > count($history);
    }

    public function run(?int $requestId = null, ?int $notificationId = null): int
    {
        $accepted = 0;
        $this->failedInRun = 0;
        DocumentRequestNotification::whereIn('status', ['pending', 'failed'])->where('attempts', '<', 5)
            ->when($requestId, fn (Builder $query): Builder => $query->where('document_request_id', $requestId))
            ->when($notificationId, fn (Builder $query): Builder => $query->whereKey($notificationId))
            ->where(fn (Builder $query): Builder => $query->whereNull('last_attempt_at')->orWhere('last_attempt_at', '<=', now()->subMinutes(5)))
            ->orderBy('id')->limit(50)->get()->each(function (DocumentRequestNotification $notification) use (&$accepted): void {
                $request = DocumentRequest::find($notification->document_request_id);
                if (! $request || $this->isSuperseded($notification, $request)) {
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
                    report($exception);
                    $this->failedInRun++;
                    $notification->update(['status' => 'failed', 'error_message' => 'Pengiriman WhatsApp gagal. Akan dicoba kembali.']);
                }
            });

        return $accepted;
    }
}
