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

    public const VARIABLE_LABELS = ['dokumen' => 'Nama Dokumen', 'nomor' => 'Nomor Dokumen', 'perusahaan' => 'Nama Badan Usaha', 'jenis_dokumen' => 'Jenis Dokumen', 'tanggal_berakhir' => 'Tanggal Berakhir', 'sisa_hari' => 'Sisa Hari', 'pic' => 'Nama PIC'];

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
        'nomor_pengajuan' => 'Nomor pengajuan', 'judul' => 'Judul pengajuan', 'pengaju' => 'Nama pengaju', 'perusahaan' => 'Nama badan usaha',
        'jenis_dokumen' => 'Jenis dokumen', 'status' => 'Tahap pengajuan', 'hari_menunggu' => 'Hari menunggu', 'tautan' => 'Tautan pengajuan',
    ];

    public const REQUEST_REMINDER_EXAMPLE_VALUES = [
        'nomor_pengajuan' => '123', 'judul' => 'PKS Sewa Gudang Cikarang', 'pengaju' => 'Budi', 'perusahaan' => 'PT Bahari Logistik Nusantara',
        'jenis_dokumen' => 'Kontrak', 'status' => 'Diperiksa', 'hari_menunggu' => '3', 'tautan' => 'https://contoh.test/admin/document-requests/123/edit',
    ];

    public const OVERDUE_VARIABLE_LABELS = ['dokumen' => 'Nama Dokumen', 'nomor' => 'Nomor Dokumen', 'perusahaan' => 'Nama Badan Usaha', 'jenis_dokumen' => 'Jenis Dokumen', 'tanggal_berakhir' => 'Tanggal Berakhir', 'hari_terlambat' => 'Hari Terlambat', 'pic' => 'Nama PIC'];

    public const OVERDUE_EXAMPLE_VALUES = ['dokumen' => 'PKS Sewa Gudang Cikarang', 'nomor' => 'PKS/001/2026', 'perusahaan' => 'PT Bahari Logistik Nusantara', 'jenis_dokumen' => 'Kontrak', 'tanggal_berakhir' => '2030-01-31', 'hari_terlambat' => '3', 'pic' => 'Sari Kusuma'];

    public const EXAMPLE_VALUES = ['dokumen' => 'PKS Sewa Gudang Cikarang', 'nomor' => 'PKS/001/2026', 'perusahaan' => 'PT Bahari Logistik Nusantara', 'jenis_dokumen' => 'Kontrak', 'tanggal_berakhir' => '2030-01-31', 'sisa_hari' => '30', 'pic' => 'Sari Kusuma'];

    /** Jenis pesan pengajuan, diurutkan mengikuti jalannya pengajuan. Label dibuat pendek agar muat di daftar. */
    public const REQUEST_TEMPLATE_LABELS = [
        'pic' => 'Pengajuan baru',
        'submitted' => 'Pengajuan diterima',
        'revision' => 'Perlu revisi',
        'rejected' => 'Ditolak',
        'drafting' => 'Proses drafting',
        'signing' => 'Draft siap',
        'draft_replaced' => 'Draft diganti',
        'scan_reminder' => 'Ingatkan unggah soft file',
        'handover' => 'Soft file diterima',
        'handover_staff' => 'Soft file masuk',
        'late_7' => 'Lewat batas',
        'late_14' => 'Terlambat 1 minggu',
        'late_21' => 'Terlambat 2 minggu',
        'late_staff' => 'Terlambat (ke tim legal)',
        'archived' => 'Selesai',
        'cancelled' => 'Dibatalkan',
    ];

    /** Penjelasan singkat tiap jenis pesan pengajuan: siapa penerimanya dan kapan dikirim. */
    public const REQUEST_TEMPLATE_HINTS = [
        'pic' => 'Ke PIC pengajuan (diatur di Jenis Dokumen), saat ada pengajuan baru masuk.',
        'submitted' => 'Ke pengaju, saat pengajuannya diterima dan mulai diperiksa.',
        'revision' => 'Ke pengaju, saat tim legal meminta perbaikan. Catatan revisi ikut dikirim.',
        'rejected' => 'Ke pengaju, saat pengajuan ditolak. Alasan penolakan ikut dikirim.',
        'drafting' => 'Ke pengaju, saat tim legal mulai memproses pengajuan dan menyusun draft.',
        'signing' => 'Ke pengaju, saat draft dokumen siap ditandatangani.',
        'draft_replaced' => 'Ke pengaju, saat tim legal mengganti draft yang sudah dikirim.',
        'scan_reminder' => 'Ke pengaju, sekali, bila 14 hari setelah draft keluar soft file bertanda tangan belum diunggah.',
        'handover' => 'Ke pengaju, saat soft file bertanda tangan masuk. Memuat batas penyerahan dokumen fisik.',
        'handover_staff' => 'Ke tim legal, saat soft file bertanda tangan masuk dan dokumen fisik mulai ditunggu.',
        'late_7' => 'Ke pengaju, sehari setelah batas penyerahan lewat (hari ke-8 setelah soft file masuk) bila dokumen fisik belum diterima.',
        'late_14' => 'Ke pengaju, bila sampai hari ke-15 setelah soft file masuk dokumen fisik belum diterima (terlambat sekitar 1 minggu).',
        'late_21' => 'Ke pengaju, bila sampai hari ke-22 setelah soft file masuk dokumen fisik belum diterima (terlambat sekitar 2 minggu).',
        'late_staff' => 'Ke tim legal bersama pesan "Terlambat 1 minggu", dan juga ke koordinator bersama pesan "Terlambat 2 minggu".',
        'archived' => 'Ke pengaju, saat tim legal mengunggah scan dokumen fisik dan pengajuan selesai. Scan bisa diunduh lewat tautan.',
        'cancelled' => 'Ke pengaju, saat pengajuan dibatalkan. Alasan pembatalan ikut dikirim.',
    ];

    /** Status pengajuan pada contoh pesan untuk template yang namanya bukan nama status. */
    public const REQUEST_TEMPLATE_EXAMPLE_STATUSES = [
        'draft_replaced' => 'signing', 'handover_staff' => 'handover', 'scan_reminder' => 'signing',
        'late_7' => 'handover', 'late_14' => 'handover', 'late_21' => 'handover', 'late_staff' => 'handover', 'pic' => 'submitted',
    ];

    public const DEFAULT_REQUEST_TEMPLATES = [
        'pic' => "Pengajuan baru #{nomor_pengajuan}\n{judul}\nPengaju: {pengaju}\n{tautan}",
        'submitted' => "Pengajuan #{nomor_pengajuan}\n{judul}\nPengajuan diterima dan sedang diperiksa.{baris_catatan}\n{tautan}",
        'revision' => "Pengajuan #{nomor_pengajuan}\n{judul}\nPengajuan perlu direvisi.{baris_catatan}\n{tautan}",
        'rejected' => "Pengajuan #{nomor_pengajuan}\n{judul}\nPengajuan ditolak.{baris_catatan}\n{tautan}",
        'drafting' => "Pengajuan #{nomor_pengajuan}\n{judul}\nPengajuan sedang diproses. Tim legal sedang menyusun draft dokumen.\n{tautan}",
        'signing' => "Pengajuan #{nomor_pengajuan}\n{judul}\nDraft dokumen sudah dikeluarkan. Silakan unduh dan cetak, tanda tangani (dan cap bila perlu), lalu unggah soft file (scan/foto) bertanda tangan lewat tautan ini. Setelah itu serahkan dokumen asli ke tim legal.{baris_catatan}\n{tautan}",
        'draft_replaced' => "Pengajuan #{nomor_pengajuan}\n{judul}\nDraft dokumen diperbarui. Gunakan draft terbaru untuk ditandatangani.{baris_catatan}\n{tautan}",
        'scan_reminder' => "Pengajuan #{nomor_pengajuan}\n{judul}\nSoft file dokumen bertanda tangan belum diunggah. Mohon segera unggah lewat tautan ini.\n{tautan}",
        'handover' => "Pengajuan #{nomor_pengajuan}\n{judul}\nSoft file bertanda tangan sudah diterima. Serahkan dokumen asli ke tim legal paling lambat {batas_waktu}.\n{tautan}",
        'handover_staff' => "Soft file bertanda tangan masuk\nPengajuan #{nomor_pengajuan}\n{judul}\nPengaju: {pengaju}\nDokumen fisik harus diterima paling lambat {batas_waktu}.\n{tautan}",
        'late_7' => "Pengajuan #{nomor_pengajuan}\n{judul}\nDokumen fisik belum diserahkan. Batas {batas_waktu} sudah lewat {hari_terlambat} hari.\nMohon segera serahkan dokumen asli ke tim legal dan tulis alasan keterlambatan lewat tautan ini:\n{tautan}",
        'late_14' => "Pengajuan #{nomor_pengajuan}\n{judul}\nDokumen fisik masih belum diserahkan. Batas {batas_waktu} sudah lewat {hari_terlambat} hari.\nMohon segera serahkan dokumen asli ke tim legal dan tulis alasan keterlambatan lewat tautan ini:\n{tautan}",
        'late_21' => "Pengajuan #{nomor_pengajuan}\n{judul}\nDokumen fisik belum juga diserahkan. Batas {batas_waktu} sudah lewat {hari_terlambat} hari.\nMohon segera serahkan dokumen asli ke tim legal dan tulis alasan keterlambatan lewat tautan ini:\n{tautan}",
        'late_staff' => "Dokumen fisik terlambat\nPengajuan #{nomor_pengajuan}\n{judul}\nPengaju: {pengaju}\nBatas {batas_waktu} sudah lewat {hari_terlambat} hari.\nAlasan terakhir: {alasan_terlambat}\n{tautan}",
        'archived' => "Pengajuan #{nomor_pengajuan}\n{judul}\nDokumen fisik sudah discan tim legal. Pengajuan selesai; scan dokumen bertanda tangan bisa diunduh lewat tautan ini.{baris_catatan}\n{tautan}",
        'cancelled' => "Pengajuan #{nomor_pengajuan}\n{judul}\nPengajuan dibatalkan.{baris_catatan}\n{tautan}",
    ];

    public const REQUEST_VARIABLE_LABELS = [
        'nomor_pengajuan' => 'Nomor pengajuan', 'judul' => 'Judul pengajuan', 'pengaju' => 'Nama pengaju',
        'perusahaan' => 'Nama badan usaha', 'jenis_dokumen' => 'Jenis dokumen', 'pic' => 'Nama PIC',
        'status' => 'Status pengajuan', 'catatan' => 'Catatan / alasan dari tim legal',
        'baris_catatan' => 'Baris catatan (hanya jika ada)', 'tautan' => 'Tautan pengajuan',
        'batas_waktu' => 'Batas penyerahan dokumen fisik', 'hari_terlambat' => 'Hari terlambat',
        'alasan_terlambat' => 'Alasan keterlambatan terakhir',
    ];

    public const REQUEST_EXAMPLE_VALUES = [
        'nomor_pengajuan' => '123', 'judul' => 'PKS Sewa Gudang Cikarang', 'pengaju' => 'Budi',
        'perusahaan' => 'PT Bahari Logistik Nusantara', 'jenis_dokumen' => 'Kontrak', 'pic' => 'Sari Kusuma',
        'status' => 'Perlu Revisi', 'catatan' => 'Mohon lengkapi lampiran.',
        'baris_catatan' => "\nCatatan: Mohon lengkapi lampiran.", 'tautan' => 'https://contoh.test/pengajuan/123',
        'batas_waktu' => '11 Nov 2030', 'hari_terlambat' => '3',
        'alasan_terlambat' => 'Direktur sedang dinas luar kota.',
    ];

    /**
     * Nilai contoh untuk pratinjau dan pesan uji template pengajuan; `status` mengikuti tahap template tersebut.
     *
     * @return array<string, string>
     */
    public static function requestExampleValues(string $event): array
    {
        $status = self::REQUEST_TEMPLATE_EXAMPLE_STATUSES[$event] ?? $event;

        return [...self::REQUEST_EXAMPLE_VALUES, 'status' => DocumentRequest::STATUSES[$status] ?? 'Diperiksa'];
    }

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
