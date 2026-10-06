<?php

namespace App\Models;

use Database\Factories\ReminderTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

#[Fillable(['name', 'is_active', 'body', 'schedule_mode', 'start_before_days', 'interval_days', 'scheduled_days'])]
class ReminderTemplate extends Model
{
    public static function globalSetting(): ?self
    {
        return self::where('global_key', 'global')->first();
    }

    /** @return list<int> */
    public static function globalReminderDays(): array
    {
        $setting = self::globalSetting();

        return $setting?->is_active ? ($setting->reminderOffsets() ?? []) : [];
    }

    protected $attributes = ['schedule_mode' => 'inherit', 'start_before_days' => 30, 'interval_days' => 7, 'scheduled_days' => '[]'];

    public const VARIABLE_LABELS = ['dokumen' => 'Nama Dokumen', 'nomor' => 'Nomor Dokumen', 'perusahaan' => 'Nama Perusahaan', 'jenis_dokumen' => 'Jenis Dokumen', 'tanggal_berakhir' => 'Tanggal Berakhir', 'sisa_hari' => 'Sisa Hari', 'pic' => 'Nama PIC'];

    /** @return list<int>|null */
    public function reminderOffsets(): ?array
    {
        if ($this->schedule_mode === 'inherit') {
            return null;
        }
        if ($this->schedule_mode === 'interval') {
            $offsets = [];
            if ($this->interval_days < 1 || $this->start_before_days < 0 || $this->start_before_days > 3650) {
                return $offsets;
            }
            for ($day = $this->start_before_days; $day >= 0; $day -= $this->interval_days) {
                $offsets[] = $day;
            }

            return $offsets;
        }

        return collect($this->scheduled_days)->map(fn (int|string $day): int => (int) $day)->unique()->sortDesc()->values()->all();
    }

    public function scheduleDescription(): string
    {
        return match ($this->schedule_mode) {
            'interval' => "Setiap {$this->interval_days} hari, mulai H-{$this->start_before_days}",
            'specific_days' => implode(', ', array_map(fn (int $day): string => $day === 0 ? 'Hari H' : "H-{$day}", $this->reminderOffsets())),
            default => 'Mengikuti jadwal jenis dokumen',
        };
    }

    /** @use HasFactory<ReminderTemplateFactory> */
    use HasFactory;

    public const DEFAULT_BODY = "Pengingat masa berlaku dokumen\nDokumen: {dokumen}\nPerusahaan: {perusahaan}\nBerakhir: {tanggal_berakhir}\nSisa waktu: {sisa_hari} hari\nPIC: {pic}";

    public const EXAMPLE_VALUES = ['dokumen' => 'Kontrak Dummy', 'nomor' => 'DUMMY-001', 'perusahaan' => 'PT Dummy', 'jenis_dokumen' => 'Kontrak', 'tanggal_berakhir' => '2030-01-31', 'sisa_hari' => '30', 'pic' => 'PIC Dummy'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'start_before_days' => 'integer', 'interval_days' => 'integer', 'scheduled_days' => 'array'];
    }

    /** @return list<string> */
    public static function unknownVariables(string $body): array
    {
        preg_match_all('/\{([^{}]+)\}/', $body, $matches);

        return array_values(array_diff(array_unique($matches[1]), array_keys(self::EXAMPLE_VALUES)));
    }

    /** @param array<string, string> $values */
    public static function renderBody(string $body, array $values): string
    {
        $replacements = [];
        foreach ($values as $name => $value) {
            $replacements['{'.$name.'}'] = $value;
        }

        return strtr($body, $replacements);
    }

    protected static function booted(): void
    {
        static::creating(function (self $template): void {
            $template->global_key = 'global';
        });

        static::saving(function (self $template): void {
            Validator::make($template->attributesToArray(), [
                'schedule_mode' => ['required', Rule::in(['inherit', 'interval', 'specific_days'])],
                'start_before_days' => [Rule::requiredIf($template->schedule_mode === 'interval'), 'nullable', 'integer', 'min:1', 'max:3650'],
                'interval_days' => [Rule::requiredIf($template->schedule_mode === 'interval'), 'nullable', 'integer', 'min:1', 'max:3650'],
                'scheduled_days' => [Rule::requiredIf($template->schedule_mode === 'specific_days'), 'array'],
                'scheduled_days.*' => ['integer', 'min:0', 'max:3650', 'distinct'],
            ])->validate();
            if (blank($template->body) || mb_strlen($template->body) > 4000 || self::unknownVariables($template->body) !== []) {
                throw ValidationException::withMessages(['body' => 'Isi pesan wajib diisi, maksimal 4000 karakter, dan memakai variabel yang tersedia.']);
            }
        });
    }
}
