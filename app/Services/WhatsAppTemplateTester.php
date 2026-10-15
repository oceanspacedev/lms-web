<?php

namespace App\Services;

use App\Models\DocumentRequest;
use App\Models\ReminderTemplate;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class WhatsAppTemplateTester
{
    public function __construct(private WaghubService $waghub) {}

    /** @return array{status: string, error: ?string} */
    public function send(User $user, string $event, string $phone): array
    {
        $setting = ReminderTemplate::globalSetting();
        abort_unless($setting ? $user->can('update', $setting) : $user->can('create', ReminderTemplate::class), 403);
        abort_unless(in_array($event, ['reminder', 'overdue', 'request_reminder'], true) || array_key_exists($event, ReminderTemplate::REQUEST_TEMPLATE_LABELS), 404);
        $number = $this->waghub->normalizePhone($phone);
        if ($number === null) {
            throw ValidationException::withMessages(['phone' => 'Masukkan nomor WhatsApp Indonesia yang valid.']);
        }
        $limitKey = 'whatsapp-template-test:'.$user->id;
        $lock = Cache::lock($limitKey.':lock', 60);
        if (! $lock->get()) {
            throw ValidationException::withMessages(['phone' => 'Pesan uji masih diproses. Tunggu sebentar.']);
        }
        try {
            if (RateLimiter::tooManyAttempts($limitKey, 5)) {
                throw ValidationException::withMessages(['phone' => 'Maksimal 5 pesan uji per menit. Coba lagi sebentar.']);
            }
            RateLimiter::hit($limitKey, 60);
            $body = match ($event) {
                'reminder' => $setting?->body ?? ReminderTemplate::DEFAULT_BODY,
                'overdue' => $setting?->overdueBody() ?? ReminderTemplate::DEFAULT_OVERDUE_BODY,
                'request_reminder' => $setting?->requestReminderBody() ?? ReminderTemplate::DEFAULT_REQUEST_REMINDER_BODY,
                default => $setting?->requestTemplate($event) ?? ReminderTemplate::DEFAULT_REQUEST_TEMPLATES[$event],
            };
            $values = match ($event) {
                'reminder' => ReminderTemplate::EXAMPLE_VALUES,
                'overdue' => ReminderTemplate::OVERDUE_EXAMPLE_VALUES,
                'request_reminder' => ReminderTemplate::REQUEST_REMINDER_EXAMPLE_VALUES,
                default => [...ReminderTemplate::REQUEST_EXAMPLE_VALUES, 'status' => DocumentRequest::STATUSES[$event] ?? 'Diperiksa'],
            };
            $message = "[PESAN UJI]\n".ReminderTemplate::renderBody($body, $values);
            $key = (string) Str::uuid();
            $id = DB::table('whatsapp_test_messages')->insertGetId([
                'event_key' => $key, 'user_id' => $user->id, 'template_event' => $event, 'recipient_phone' => $number,
                'message' => $message, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $error = null;
            try {
                $response = $this->waghub->send([
                    'recipient' => ['type' => 'phone', 'value' => $number], 'message' => ['type' => 'text', 'text' => $message],
                    'purpose' => config('services.waghub.purpose'), 'mode' => config('services.waghub.mode'),
                    'route_key' => config('services.waghub.route_key'), 'expires_at' => now()->addHour()->toIso8601String(),
                    'client_reference' => 'lms-template-test-'.$key,
                ], 'lms-template-test-'.$key);
                if (! $response->successful()) {
                    $error = 'WagHub menolak pesan uji (HTTP '.$response->status().').';
                }
            } catch (ConnectionException) {
                $error = 'Koneksi WagHub gagal. Periksa riwayat sebelum mencoba lagi.';
            } catch (RuntimeException) {
                $error = 'Konfigurasi WagHub belum lengkap. Hubungi administrator.';
            }
            $status = $error === null ? 'accepted' : 'failed';
            DB::table('whatsapp_test_messages')->where('id', $id)->update([
                'status' => $status, 'error_message' => $error, 'accepted_at' => $error === null ? now() : null, 'updated_at' => now(),
            ]);

            return ['status' => $status, 'error' => $error];
        } finally {
            $lock->release();
        }
    }
}
