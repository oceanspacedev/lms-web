<?php

namespace App\Models;

use Database\Factories\ReminderTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

#[Fillable(['name', 'is_active', 'body', 'schedule_mode', 'start_before_days', 'interval_days', 'scheduled_days', 'overdue_days', 'overdue_body', 'request_templates', 'request_reminder_enabled', 'request_reminder_after_days', 'request_reminder_interval_days', 'request_reminder_max', 'request_reminder_body'])]
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

    /** @return list<int> */
    public static function globalOverdueDays(): array
    {
        $setting = self::globalSetting();

        return $setting?->is_active ? $setting->overdueOffsets() : [];
    }

    /** @return array{enabled: bool, after_days: int, interval_days: int, max: int} */
    public static function requestReminderSettings(): array
    {
        $setting = self::globalSetting();

        return $setting === null ? self::REQUEST_REMINDER_DEFAULTS : [
            'enabled' => $setting->request_reminder_enabled,
            'after_days' => max(1, $setting->request_reminder_after_days),
            'interval_days' => max(1, $setting->request_reminder_interval_days),
            'max' => max(1, $setting->request_reminder_max),
        ];
    }

    public function requestReminderBody(): string
    {
        return filled($this->request_reminder_body) ? $this->request_reminder_body : self::DEFAULT_REQUEST_REMINDER_BODY;
    }

    public function overdueBody(): string
    {
        return filled($this->overdue_body) ? $this->overdue_body : self::DEFAULT_OVERDUE_BODY;
    }

    /** @return list<int> Hari setelah tanggal berakhir, urut naik. */
    public function overdueOffsets(): array
    {
        return collect($this->overdue_days)->map(fn (int|string $day): int => (int) $day)->filter(fn (int $day): bool => $day > 0)->unique()->sort()->values()->all();
    }

    protected $attributes = ['schedule_mode' => 'inherit', 'start_before_days' => 30, 'interval_days' => 7, 'scheduled_days' => '[]', 'overdue_days' => '[]', 'request_reminder_enabled' => true, 'request_reminder_after_days' => 2, 'request_reminder_interval_days' => 2, 'request_reminder_max' => 3];

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

    public const DEFAULT_OVERDUE_BODY = "Dokumen sudah kedaluwarsa\nDokumen: {dokumen}\nPerusahaan: {perusahaan}\nBerakhir: {tanggal_berakhir}\nTerlambat: {hari_terlambat} hari\nPIC: {pic}";

    public const DEFAULT_REQUEST_REMINDER_BODY = "Pengajuan menunggu tindakan\nPengajuan #{nomor_pengajuan}: {judul}\nTahap: {status}\nMenunggu {hari_menunggu} hari\n{tautan}";

    public const REQUEST_REMINDER_DEFAULTS = ['enabled' => true, 'after_days' => 2, 'interval_days' => 2, 'max' => 3];

    public const REQUEST_REMINDER_VARIABLE_LABELS = [
        'nomor_pengajuan' => 'Nomor pengajuan', 'judul' => 'Judul pengajuan', 'pengaju' => 'Nama pengaju', 'perusahaan' => 'Nama perusahaan',
        'jenis_dokumen' => 'Jenis dokumen', 'status' => 'Tahap pengajuan', 'hari_menunggu' => 'Hari menunggu', 'tautan' => 'Tautan pengajuan',
    ];

    public const REQUEST_REMINDER_EXAMPLE_VALUES = [
        'nomor_pengajuan' => '123', 'judul' => 'Pengajuan Kontrak Dummy', 'pengaju' => 'Budi', 'perusahaan' => 'PT Dummy',
        'jenis_dokumen' => 'Kontrak', 'status' => 'Diperiksa', 'hari_menunggu' => '3', 'tautan' => 'https://contoh.test/admin/document-requests/123/edit',
    ];

    public const OVERDUE_VARIABLE_LABELS = ['dokumen' => 'Nama Dokumen', 'nomor' => 'Nomor Dokumen', 'perusahaan' => 'Nama Perusahaan', 'jenis_dokumen' => 'Jenis Dokumen', 'tanggal_berakhir' => 'Tanggal Berakhir', 'hari_terlambat' => 'Hari Terlambat', 'pic' => 'Nama PIC'];

    public const OVERDUE_EXAMPLE_VALUES = ['dokumen' => 'Kontrak Dummy', 'nomor' => 'DUMMY-001', 'perusahaan' => 'PT Dummy', 'jenis_dokumen' => 'Kontrak', 'tanggal_berakhir' => '2030-01-31', 'hari_terlambat' => '3', 'pic' => 'PIC Dummy'];

    public const EXAMPLE_VALUES = ['dokumen' => 'Kontrak Dummy', 'nomor' => 'DUMMY-001', 'perusahaan' => 'PT Dummy', 'jenis_dokumen' => 'Kontrak', 'tanggal_berakhir' => '2030-01-31', 'sisa_hari' => '30', 'pic' => 'PIC Dummy'];

    public const REQUEST_TEMPLATE_LABELS = [
        'submitted' => 'Pengajuan diterima / diperiksa',
        'review' => 'Menunggu persetujuan',
        'approved' => 'Disetujui / menunggu tanda tangan',
        'revision' => 'Perlu revisi',
        'rejected' => 'Ditolak',
        'archived' => 'Selesai / diarsipkan',
        'pic' => 'Pengajuan baru untuk PIC',
    ];

    public const DEFAULT_REQUEST_TEMPLATES = [
        'submitted' => "Pengajuan #{nomor_pengajuan}\n{judul}\nPengajuan diterima dan sedang diperiksa.{baris_catatan}\n{tautan}",
        'review' => "Pengajuan #{nomor_pengajuan}\n{judul}\nPengajuan sedang diproses untuk persetujuan.{baris_catatan}\n{tautan}",
        'approved' => "Pengajuan #{nomor_pengajuan}\n{judul}\nPengajuan disetujui. Menunggu dokumen final.{baris_catatan}\n{tautan}",
        'revision' => "Pengajuan #{nomor_pengajuan}\n{judul}\nPengajuan perlu direvisi.{baris_catatan}\n{tautan}",
        'rejected' => "Pengajuan #{nomor_pengajuan}\n{judul}\nPengajuan ditolak.{baris_catatan}\n{tautan}",
        'archived' => "Pengajuan #{nomor_pengajuan}\n{judul}\nPengajuan selesai. Dokumen final telah diarsipkan.{baris_catatan}\n{tautan}",
        'pic' => "Pengajuan baru #{nomor_pengajuan}\n{judul}\nPengaju: {pengaju}\n{tautan}",
    ];

    public const REQUEST_VARIABLE_LABELS = [
        'nomor_pengajuan' => 'Nomor pengajuan', 'judul' => 'Judul pengajuan', 'pengaju' => 'Nama pengaju',
        'perusahaan' => 'Nama perusahaan', 'jenis_dokumen' => 'Jenis dokumen', 'pic' => 'Nama PIC',
        'status' => 'Status pengajuan', 'catatan' => 'Catatan pemeriksa',
        'baris_catatan' => 'Baris catatan (hanya jika ada)', 'tautan' => 'Tautan pengajuan',
    ];

    public const REQUEST_EXAMPLE_VALUES = [
        'nomor_pengajuan' => '123', 'judul' => 'Pengajuan Kontrak Dummy', 'pengaju' => 'Budi',
        'perusahaan' => 'PT Dummy', 'jenis_dokumen' => 'Kontrak', 'pic' => 'PIC Dummy',
        'status' => 'Perlu Revisi', 'catatan' => 'Mohon lengkapi lampiran.',
        'baris_catatan' => "\nCatatan: Mohon lengkapi lampiran.", 'tautan' => 'https://contoh.test/pengajuan/123',
    ];

    public function requestTemplate(string $event): ?string
    {
        return $this->request_templates[$event] ?? self::DEFAULT_REQUEST_TEMPLATES[$event] ?? null;
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'start_before_days' => 'integer', 'interval_days' => 'integer', 'scheduled_days' => 'array', 'overdue_days' => 'array', 'request_reminder_enabled' => 'boolean', 'request_reminder_after_days' => 'integer', 'request_reminder_interval_days' => 'integer', 'request_reminder_max' => 'integer', 'request_templates' => 'array'];
    }

    /**
     * @param  array<string, string>  $values
     * @return list<string>
     */
    public static function unknownVariables(string $body, array $values = self::EXAMPLE_VALUES): array
    {
        preg_match_all('/\{([^{}]+)\}/', $body, $matches);

        return array_values(array_diff(array_unique($matches[1]), array_keys($values)));
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
                'overdue_days' => ['nullable', 'array', 'max:20'],
                'overdue_days.*' => ['integer', 'min:1', 'max:3650', 'distinct'],
                'request_templates' => ['nullable', 'array:'.implode(',', array_keys(self::REQUEST_TEMPLATE_LABELS))],
                'request_templates.*' => ['required', 'string', 'max:4000'],
            ])->validate();
            foreach ($template->request_templates ?? [] as $event => $body) {
                if (blank($body) || self::unknownVariables($body, self::REQUEST_EXAMPLE_VALUES) !== []) {
                    throw ValidationException::withMessages(['request_templates.'.$event => 'Isi pesan wajib diisi dan memakai variabel yang tersedia.']);
                }
            }
            Validator::make($template->attributesToArray(), [
                'request_reminder_after_days' => ['required', 'integer', 'min:1', 'max:365'],
                'request_reminder_interval_days' => ['required', 'integer', 'min:1', 'max:365'],
                'request_reminder_max' => ['required', 'integer', 'min:1', 'max:10'],
            ])->validate();
            if (filled($template->request_reminder_body) && (mb_strlen($template->request_reminder_body) > 4000 || self::unknownVariables($template->request_reminder_body, self::REQUEST_REMINDER_EXAMPLE_VALUES) !== [])) {
                throw ValidationException::withMessages(['request_reminder_body' => 'Isi pesan maksimal 4000 karakter dan memakai variabel yang tersedia.']);
            }
            if (filled($template->overdue_body) && (mb_strlen($template->overdue_body) > 4000 || self::unknownVariables($template->overdue_body, self::OVERDUE_EXAMPLE_VALUES) !== [])) {
                throw ValidationException::withMessages(['overdue_body' => 'Isi pesan maksimal 4000 karakter dan memakai variabel yang tersedia.']);
            }
            if (blank($template->body) || mb_strlen($template->body) > 4000 || self::unknownVariables($template->body) !== []) {
                throw ValidationException::withMessages(['body' => 'Isi pesan wajib diisi, maksimal 4000 karakter, dan memakai variabel yang tersedia.']);
            }
        });
    }
}
