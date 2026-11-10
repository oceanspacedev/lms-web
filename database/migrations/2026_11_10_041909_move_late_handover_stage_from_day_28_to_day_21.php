<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Tahap pengingat terakhir dokumen fisik pindah dari hari ke-28 ("Terlambat 3 minggu") ke hari ke-21 ("Terlambat 2 minggu").
     * Isi pesan yang sudah disesuaikan admin ikut pindah ke kunci baru, dan pengingat yang sudah tercatat ikut berganti kunci
     * supaya pengajuan yang sudah menerima pesan tahap lama tidak dikirimi lagi. Aman dijalankan ulang.
     */
    public function up(): void
    {
        $this->renameTemplateKey('late_28', 'late_21');
        $this->renameEventKeys('-late28-', '-late21-');
    }

    public function down(): void
    {
        $this->renameTemplateKey('late_21', 'late_28');
        $this->renameEventKeys('-late21-', '-late28-');
    }

    private function renameTemplateKey(string $from, string $to): void
    {
        DB::table('reminder_templates')->whereNotNull('request_templates')->get(['id', 'request_templates'])->each(function (object $row) use ($from, $to): void {
            $templates = json_decode($row->request_templates, true);
            if (! is_array($templates) || ! array_key_exists($from, $templates)) {
                return;
            }
            $templates[$to] ??= $templates[$from];
            unset($templates[$from]);
            DB::table('reminder_templates')->where('id', $row->id)->update(['request_templates' => json_encode($templates)]);
        });
    }

    private function renameEventKeys(string $from, string $to): void
    {
        DB::table('document_request_notifications')->where('event_key', 'like', '%'.$from.'%')->orderBy('id')
            ->each(function (object $row) use ($from, $to): void {
                $key = str_replace($from, $to, $row->event_key);
                if (! DB::table('document_request_notifications')->where('event_key', $key)->exists()) {
                    DB::table('document_request_notifications')->where('id', $row->id)->update(['event_key' => $key]);
                }
            });
    }
};
