<?php

namespace App\Services;

use App\Models\Document;
use App\Models\ReminderLog;
use App\Models\ReminderTemplate;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class ReminderHealthChecker
{
    public function __construct(private WaghubService $waghub) {}

    /** @return list<array{key: string, level: 'danger'|'warning', message: string}> */
    public function alerts(): array
    {
        return array_values(array_filter([
            $this->templateAlert(),
            $this->recipientAlert(),
            $this->failedAlert(),
        ]));
    }

    /** @return array{key: string, level: 'danger'|'warning', message: string}|null */
    private function templateAlert(): ?array
    {
        $setting = ReminderTemplate::globalSetting();
        if ($setting === null || ! $setting->is_active) {
            return ['key' => 'template', 'level' => 'danger', 'message' => 'Pengingat WhatsApp belum aktif. Template belum dibuat atau dinonaktifkan, sehingga tidak ada pengingat masa berlaku yang dikirim.'];
        }
        if (ReminderTemplate::globalReminderDays() === []) {
            return ['key' => 'template', 'level' => 'danger', 'message' => 'Jadwal pengingat belum diatur. Atur jadwal agar pengingat masa berlaku dikirim.'];
        }

        return null;
    }

    /** @return array{key: string, level: 'danger'|'warning', message: string}|null */
    private function recipientAlert(): ?array
    {
        $count = 0;
        Document::with('pic')
            ->whereHas('documentType', fn (Builder $query): Builder => $query->where('has_expiry', true))
            ->whereHas('currentVersion', fn (Builder $query): Builder => $query->where('is_current', true)->whereDate('expiry_date', '>=', today(config('lms.reminder_timezone'))->toDateString()))
            ->lazy()
            ->each(function (Document $document) use (&$count): void {
                if ($this->waghub->normalizePhone($document->pic?->phone) === null) {
                    $count++;
                }
            });
        if ($count === 0) {
            return null;
        }
        if ($this->hasFallbackRecipient()) {
            return ['key' => 'recipient', 'level' => 'warning', 'message' => "{$count} dokumen aktif punya PIC tanpa nomor WhatsApp valid. Pengingatnya dikirim ke penerima cadangan."];
        }

        return ['key' => 'recipient', 'level' => 'danger', 'message' => "{$count} dokumen aktif punya PIC tanpa nomor WhatsApp valid dan belum ada penerima cadangan. Pengingatnya tidak akan terkirim."];
    }

    /** @return array{key: string, level: 'danger'|'warning', message: string}|null */
    private function failedAlert(): ?array
    {
        $count = ReminderLog::where('status', 'failed')->count();

        return $count === 0 ? null : ['key' => 'failed', 'level' => 'danger', 'message' => "{$count} pengingat gagal terkirim ke WagHub. Periksa Log Pengingat."];
    }

    private function hasFallbackRecipient(): bool
    {
        foreach (User::permission('receive_reminder')->cursor() as $user) {
            if ($this->waghub->normalizePhone($user->phone) !== null) {
                return true;
            }
        }

        return false;
    }
}
