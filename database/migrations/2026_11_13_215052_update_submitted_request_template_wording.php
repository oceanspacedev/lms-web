<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const OLD = "Pengajuan #{nomor_pengajuan}\n{judul}\nPengajuan diterima dan sedang diperiksa.{baris_catatan}\n{tautan}";

    private const NEW = "Pengajuan #{nomor_pengajuan}\n{judul}\nPengajuan diterima dan menunggu diperiksa tim legal.{baris_catatan}\n{tautan}";

    /**
     * Pengajuan baru kini berstatus "Menunggu Diperiksa"; "sedang diperiksa" baru berlaku setelah tim legal mengklik Periksa.
     * Pesan "Pengajuan diterima" yang tersimpan masih berisi teks bawaan lama diganti ke teks bawaan baru;
     * pesan yang sudah disesuaikan admin tidak disentuh. Aman dijalankan ulang.
     */
    public function up(): void
    {
        $this->replaceSubmittedTemplate(self::OLD, self::NEW);
    }

    public function down(): void
    {
        $this->replaceSubmittedTemplate(self::NEW, self::OLD);
    }

    private function replaceSubmittedTemplate(string $from, string $to): void
    {
        DB::table('reminder_templates')->whereNotNull('request_templates')->get(['id', 'request_templates'])->each(function (object $row) use ($from, $to): void {
            $templates = json_decode($row->request_templates, true);
            if (! is_array($templates) || ($templates['submitted'] ?? null) !== $from) {
                return;
            }
            $templates['submitted'] = $to;
            DB::table('reminder_templates')->where('id', $row->id)->update(['request_templates' => json_encode($templates)]);
        });
    }
};
