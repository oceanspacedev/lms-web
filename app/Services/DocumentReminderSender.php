<?php

namespace App\Services;

use App\Models\Document;
use App\Models\ReminderLog;
use App\Models\ReminderTemplate;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class DocumentReminderSender
{
    public function __construct(private WaghubService $waghub) {}

    /** @return array{due: int, accepted: int, failed: int, cancelled: int} */
    public function run(bool $dryRun = false): array
    {
        $result = ['due' => 0, 'accepted' => 0, 'failed' => 0, 'cancelled' => 0];
        $today = CarbonImmutable::today(config('lms.reminder_timezone'));
        if (! $dryRun) {
            $this->waghub->assertConfigured();
        }
        Document::with(['company', 'documentType', 'pic', 'currentVersion'])
            ->whereHas('documentType', fn (Builder $query): Builder => $query->where('has_expiry', true))
            ->whereHas('currentVersion', fn (Builder $query): Builder => $query->where('is_current', true)->whereNotNull('expiry_date'))
            ->each(function (Document $document) use ($today, $dryRun, &$result): void {
                $offset = (int) $today->diffInDays(CarbonImmutable::parse($document->currentVersion->expiry_date->toDateString(), $today->timezone), false);
                if (! in_array($offset, $document->documentType->effectiveReminderDays(), true)) {
                    return;
                }
                $result['due']++;
                if (! $dryRun) {
                    ReminderLog::firstOrCreate(['document_version_id' => $document->current_version_id, 'offset_days' => $offset]);
                }
            });
        if ($dryRun) {
            return $result;
        }
        ReminderLog::whereIn('status', ['pending', 'failed'])->each(function (ReminderLog $log) use ($today, &$result): void {
            $key = "lms-reminder-{$log->document_version_id}-{$log->offset_days}";
            $lock = Cache::lock($key, max(60, (int) config('services.waghub.timeout') + 30));
            if (! $lock->get()) {
                return;
            }
            try {
                $log->refresh()->load('documentVersion.document.company', 'documentVersion.document.documentType', 'documentVersion.document.pic');
                $version = $log->documentVersion;
                $document = $version?->document;
                if (! $document || ! $version->is_current || $document->current_version_id !== $version->id
                    || ! $document->documentType->has_expiry || ! $version->expiry_date
                    || $version->expiry_date->toDateString() < $today->toDateString()
                    || ! in_array($log->offset_days, $document->documentType->effectiveReminderDays(), true)) {
                    $log->update(['status' => 'cancelled', 'error_message' => 'Versi atau jadwal pengingat sudah tidak berlaku.']);
                    $result['cancelled']++;

                    return;
                }
                $claimed = ReminderLog::whereKey($log->id)->whereIn('status', ['pending', 'failed'])
                    ->where('attempts', '<', config('lms.reminder_max_attempts'))
                    ->where(function (Builder $query) use ($today): void {
                        $query->whereNull('last_attempt_at')->orWhere('last_attempt_at', '<', $today->utc());
                    })->update(['status' => 'pending', 'attempts' => DB::raw('attempts + 1'), 'last_attempt_at' => now()]);
                if (! $claimed) {
                    return;
                }
                try {
                    $payload = $log->request_payload ?? $this->payload($document, $log, $today);
                    $log->update(['request_payload' => $payload, 'recipient_phone' => $payload['recipient']['value']]);
                    $response = $this->waghub->send($payload, $key);
                    $log->update(['provider_response' => $response->json() ?? ['http_status' => $response->status()]]);
                    if (! $response->successful()) {
                        throw new RuntimeException('WagHub menolak permintaan (HTTP '.$response->status().').');
                    }
                    $log->update(['status' => 'accepted', 'sent_at' => null, 'error_message' => null]);
                    $result['accepted']++;
                } catch (Throwable $exception) {
                    $log->update(['status' => 'failed', 'error_message' => $exception instanceof RuntimeException && ! $exception instanceof ConnectionException
                        ? $exception->getMessage() : 'Koneksi WagHub gagal; pengiriman akan dicoba kembali.']);
                    $result['failed']++;
                }
            } finally {
                $lock->release();
            }
        });

        return $result;
    }

    /** @return array<string, mixed> */
    private function payload(Document $document, ReminderLog $log, CarbonImmutable $today): array
    {
        $phone = $this->waghub->normalizePhone($document->pic?->phone);
        if ($phone === null) {
            foreach (User::permission('receive_reminder')->orderBy('id')->cursor() as $user) {
                $phone = $this->waghub->normalizePhone($user->phone);
                if ($phone !== null) {
                    break;
                }
            }
        }
        if ($phone === null) {
            throw new RuntimeException('Nomor WhatsApp PIC atau penerima cadangan belum tersedia.');
        }
        $expiry = $document->currentVersion->expiry_date->toDateString();
        $remaining = (int) $today->diffInDays(CarbonImmutable::parse($expiry, $today->timezone), false);
        $template = ReminderTemplate::globalSetting();
        $text = ReminderTemplate::renderBody($template?->is_active ? $template->body : ReminderTemplate::DEFAULT_BODY, [
            'dokumen' => $document->title, 'nomor' => $document->document_number ?? '-',
            'perusahaan' => $document->company->name, 'jenis_dokumen' => $document->documentType->name,
            'tanggal_berakhir' => $expiry,
            'sisa_hari' => (string) $remaining, 'pic' => $document->pic?->name ?? '-',
        ]);

        return [
            'recipient' => ['type' => 'phone', 'value' => $phone],
            'message' => ['type' => 'text', 'text' => $text],
            'purpose' => config('services.waghub.purpose'), 'mode' => config('services.waghub.mode'),
            'route_key' => config('services.waghub.route_key'),
            'expires_at' => CarbonImmutable::parse($expiry, $today->timezone)->endOfDay()->toIso8601String(),
            'client_reference' => "lms-reminder-{$log->document_version_id}-{$log->offset_days}",
        ];
    }
}
