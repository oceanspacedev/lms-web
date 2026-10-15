<?php

namespace App\Filament\Pages;

use App\Filament\Resources\DocumentRequests\DocumentRequestResource;
use App\Filament\Resources\Documents\DocumentResource;
use App\Filament\Resources\Documents\Pages\ImportDocuments;
use App\Filament\Resources\DocumentTypes\DocumentTypeResource;
use App\Filament\Resources\ReminderLogs\ReminderLogResource;
use App\Filament\Resources\ReminderTemplates\ReminderTemplateResource;
use App\Filament\Resources\Users\UserResource;
use BackedEnum;
use Filament\Pages\Dashboard;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Panduan fitur untuk semua user panel: satu fitur per tampilan, berisi video singkat, langkah yang tersinkron
 * dengan video, dan bagian aplikasi yang ikut berubah. Tautan ke halaman lain hanya muncul bila user boleh membukanya.
 * Video ada di resources/videos/panduan (dikirim PanduanVideoController). Detik "at" tiap langkah = detik mulai langkah itu di videonya.
 */
class Panduan extends Page
{
    protected string $view = 'filament.pages.panduan';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPlayCircle;

    protected static ?string $navigationLabel = 'Panduan';

    protected static ?int $navigationSort = 9;

    protected static ?string $title = 'Panduan Fitur';

    protected static ?string $slug = 'panduan';

    public function getSubheading(): ?string
    {
        return 'Pilih fitur, tonton videonya, lalu lihat bagian mana saja yang ikut berubah.';
    }

    /**
     * @return list<array{
     *     id: string,
     *     number: string,
     *     title: string,
     *     summary: string,
     *     audience: string,
     *     video: string,
     *     poster: string,
     *     steps: list<array{text: string, at: float, description: string}>,
     *     impacts: list<array{area: string, text: string, url: string|null}>,
     *     action: array{label: string, url: string, newTab: bool}|null,
     *     canUse: bool,
     * }>
     */
    public function guides(): array
    {
        $dashboard = Dashboard::getUrl();
        $documents = DocumentResource::canViewAny() ? DocumentResource::getUrl() : null;
        $requests = DocumentRequestResource::canViewAny() ? DocumentRequestResource::getUrl() : null;
        $settings = ReminderTemplateResource::canViewAny() ? ReminderTemplateResource::getUrl() : null;
        $logs = ReminderLogResource::canViewAny() ? ReminderLogResource::getUrl() : null;
        $users = UserResource::canViewAny() ? UserResource::getUrl() : null;
        $documentTypes = DocumentTypeResource::canViewAny() ? DocumentTypeResource::getUrl() : null;

        $guides = [
            $this->guide('tambah-dokumen', '01', 'Tambah dokumen',
                summary: 'Mengarsipkan dokumen legal baru beserta filenya. Tersimpan sebagai versi 1 dan langsung dipantau masa berlakunya.',
                audience: 'Tim legal',
                steps: [
                    ['Buka Dokumen, klik Tambah Dokumen', 0, 'Tombolnya ada di kanan atas halaman Dokumen.'],
                    ['Pilih badan usaha dan jenis dokumen', 2.0, 'Ketik beberapa huruf untuk mencari. Jenis dokumen menentukan apakah dokumen punya masa berlaku.'],
                    ['Isi judul, nomor, dan pihak lawan', 5.2, 'Nomor dan pihak lawan boleh dikosongkan bila memang tidak ada.'],
                    ['Pilih PIC dan tanggal berakhir', 7.4, 'PIC adalah penerima pengingat WhatsApp. Tanggal berakhir wajib untuk dokumen yang punya masa berlaku.'],
                    ['Unggah file, lalu klik Buat', 10.8, 'Format PDF, DOC, DOCX, JPG, atau PNG, maksimal 20 MB.'],
                    ['Dokumen tersimpan sebagai versi 1', 13.0, 'Nama file dibuat otomatis agar mudah dilacak. Pengingat dihitung dari tanggal berakhir.'],
                ],
                impacts: [
                    ['area' => 'Daftar Dokumen', 'text' => 'Langsung muncul, bisa dicari dan diekspor.', 'url' => $documents],
                    ['area' => 'Dashboard', 'text' => 'Ikut dihitung di status dan daftar yang akan berakhir.', 'url' => $dashboard],
                    ['area' => 'Pengingat WhatsApp', 'text' => 'Dikirim ke PIC sesuai jadwal sebelum tanggal berakhir.', 'url' => $settings],
                ],
                action: DocumentResource::canCreate() ? ['label' => 'Tambah dokumen', 'url' => DocumentResource::getUrl('create')] : null,
            ),
            $this->guide('cari-dan-ekspor', '02', 'Cari & ekspor dokumen',
                summary: 'Menemukan dokumen lewat pencarian dan filter, lalu membawa daftarnya ke Excel.',
                audience: 'Semua yang boleh melihat dokumen',
                steps: [
                    ['Ketik judul, nomor, atau pihak lawan di kotak cari', 0, 'Daftar langsung tersaring saat Anda mengetik.'],
                    ['Klik Filter, lalu pilih status', 3.5, 'Bisa juga menyaring badan usaha, jenis, PIC, pihak lawan, dan rentang tanggal berakhir. Filter bisa digabung.'],
                    ['Klik Ekspor Excel, isinya sesuai filter', 8.1, 'Yang diekspor hanya dokumen yang sedang tampil, lengkap dengan status dan sisa hari.'],
                ],
                impacts: [
                    ['area' => 'Data dokumen', 'text' => 'Tidak berubah. Pencarian dan ekspor hanya membaca data.', 'url' => null],
                    ['area' => 'File Excel', 'text' => 'Hanya berisi dokumen yang boleh Anda lihat.', 'url' => $documents],
                ],
                action: $documents === null ? null : ['label' => 'Buka Dokumen', 'url' => $documents],
            ),
            $this->guide('perpanjang-dokumen', '03', 'Perpanjang dokumen',
                summary: 'Memperbarui masa berlaku dengan mengunggah versi baru. Tanggal baru terisi otomatis.',
                audience: 'Tim legal',
                steps: [
                    ['Buka dokumen, klik Perpanjang', 0, 'Badge Segera Berakhir atau Kedaluwarsa menandai dokumen yang perlu diperpanjang.'],
                    ['Periksa tanggal baru yang sudah terisi', 2.2, 'Tanggal terbit dan berakhir baru diambil dari versi aktif. Ubah bila perlu.'],
                    ['Unggah file, klik Simpan Versi Baru', 4.4, 'Catatan perubahan ikut tersimpan di riwayat versi.'],
                    ['Versi baru aktif, versi lama jadi arsip', 6.6, 'Semua versi lama tetap bisa diunduh di tab Riwayat Versi.'],
                ],
                impacts: [
                    ['area' => 'Riwayat Versi', 'text' => 'Versi lama tetap bisa diunduh, tetapi tidak bisa diubah.', 'url' => $documents],
                    ['area' => 'Status & Dashboard', 'text' => 'Dihitung ulang dari tanggal berakhir baru.', 'url' => $dashboard],
                    ['area' => 'Pengingat WhatsApp', 'text' => 'Jadwal lama dibatalkan, jadwal baru mulai dari awal.', 'url' => $logs],
                ],
                action: $documents === null ? null : ['label' => 'Buka Dokumen', 'url' => $documents],
            ),
            $this->guide('pengingat-whatsapp', '04', 'Atur pengingat WhatsApp',
                summary: 'Mengatur kapan pengingat dikirim dan isi pesannya, lalu memantau pengirimannya.',
                audience: 'Admin',
                steps: [
                    ['Buka Pengaturan WhatsApp, klik Atur jadwal', 0, 'Jadwal yang aktif dan contoh pesan langsung terlihat di halaman ini.'],
                    ['Atur mulai, jarak, dan jam pengingat', 2.2, 'Contoh: mulai 30 hari sebelum berakhir, ulangi tiap 7 hari, pukul 08.00 WIB.'],
                    ['Ubah isi pesan bila perlu', 4.6, 'Klik tombol data seperti Nama Dokumen atau Nama PIC untuk menyisipkannya. Coba dulu lewat Kirim pesan uji.'],
                    ['Pantau di Log Pengingat, kirim ulang yang gagal', 6.8, 'Status Gagal? Klik Kirim ulang. Pesan yang sudah sampai tidak akan terkirim dua kali.'],
                ],
                impacts: [
                    ['area' => 'Semua dokumen bermasa berlaku', 'text' => 'Jadwal ini berlaku untuk semuanya sekaligus.', 'url' => $settings],
                    ['area' => 'Log Pengingat', 'text' => 'Setiap pesan tercatat. Kirim ulang tidak membuat pesan ganda.', 'url' => $logs],
                    ['area' => 'Dashboard', 'text' => 'Muncul peringatan bila jadwal kosong, PIC tanpa nomor, atau ada pesan gagal.', 'url' => $dashboard],
                ],
                action: $settings === null ? null : ['label' => 'Buka Pengaturan WhatsApp', 'url' => $settings],
            ),
            $this->guide('ajukan-dokumen', '05', 'Ajukan dokumen',
                summary: 'Divisi lain mengajukan dokumen lewat form publik tanpa login. Statusnya dikabari lewat WhatsApp.',
                audience: 'Divisi lain (bagikan tautan form-nya)',
                steps: [
                    ['Isi nama, nomor WhatsApp, dan divisi', 0, 'Tanpa akun. Nomor WhatsApp dipakai untuk mengabari status pengajuan.'],
                    ['Pilih badan usaha dan jenis, isi detailnya', 2.2, 'Isi judul, pihak lawan bila ada, dan keperluannya.'],
                    ['Lampirkan dokumen, klik Kirim', 4.6, 'PDF, Word, atau gambar, maksimal 20 MB per file.'],
                    ['Simpan tautan status yang muncul', 6.8, 'Tautan yang sama dikirim ke WhatsApp pengaju. Halaman status menunjukkan tahap saat ini.'],
                    ['Tautan hilang? Cek status pakai nomor pengajuan', 9.4, 'Cukup isi nomor pengajuan dan nomor WhatsApp yang dipakai saat mengajukan.'],
                ],
                impacts: [
                    ['area' => 'Pengajuan Dokumen', 'text' => 'Masuk dengan status Diperiksa, pemeriksa dikabari WhatsApp.', 'url' => $requests],
                    ['area' => 'WhatsApp pengaju', 'text' => 'Menerima tautan status dan kabar tiap perubahan.', 'url' => null],
                    ['area' => 'Dashboard', 'text' => 'Ikut dihitung di "Pengajuan Menunggu Tindakan".', 'url' => $dashboard],
                ],
                action: ['label' => 'Buka form pengajuan', 'url' => route('requests.public.create'), 'newTab' => true],
            ),
            $this->guide('proses-pengajuan', '06', 'Proses pengajuan',
                summary: 'Memeriksa dan menyetujui pengajuan, lalu mengunggah dokumen final ke arsip.',
                audience: 'Pemeriksa dan penyetuju',
                steps: [
                    ['Buka tab Perlu tindakan saya, klik pengajuannya', 0, 'Pengajuan yang sedang menunggu Anda tampil di tab ini.'],
                    ['Pemeriksa klik Lanjutkan', 2.2, 'Kurang lengkap? Pilih Revisi atau Tolak beserta catatannya.'],
                    ['Penyetuju klik Setujui', 6.4, 'Pengaju dikabari lewat WhatsApp di setiap perubahan status.'],
                    ['Klik Unggah Dokumen Final, isi nomor dan file', 10.6, 'Unggah file yang sudah ditandatangani beserta nomor dokumennya.'],
                    ['Selesai, dokumen masuk arsip', 13.9, 'Dokumen baru otomatis dibuat di arsip, lengkap dengan pengingatnya.'],
                ],
                impacts: [
                    ['area' => 'Pengaju', 'text' => 'Dikabari WhatsApp di tiap tahap, termasuk catatan revisi atau alasan penolakan.', 'url' => null],
                    ['area' => 'Daftar Dokumen', 'text' => 'Dokumen final otomatis masuk arsip sebagai versi 1.', 'url' => $documents],
                    ['area' => 'Pengingat WhatsApp', 'text' => 'Pengingat masa berlaku dokumen baru langsung berjalan.', 'url' => $logs],
                ],
                action: $requests === null ? null : ['label' => 'Buka Pengajuan Dokumen', 'url' => $requests],
            ),
            $this->guide('impor-arsip-lama', '07', 'Impor arsip lama',
                summary: 'Memasukkan banyak dokumen lama sekaligus dari satu Excel dan ZIP. Data diperiksa dulu sebelum disimpan.',
                audience: 'Tim legal',
                steps: [
                    ['Klik Impor di halaman Dokumen', 0, 'Tombolnya ada di sebelah Tambah Dokumen.'],
                    ['Unduh template Excel, lalu isi datanya', 1.9, 'Sheet Petunjuk memuat daftar badan usaha, jenis dokumen, dan email PIC yang sah.'],
                    ['Unggah Excel dan ZIP, klik Periksa file', 3.9, 'Nama file di Excel harus sama dengan nama file di dalam ZIP.'],
                    ['Cek hasil pemeriksaan tiap baris', 6.1, 'Tiap baris diberi status siap, dilewati karena sudah ada, atau perlu diperbaiki beserta alasannya. Belum ada yang disimpan.'],
                    ['Klik Impor, dokumen langsung masuk', 9.9, 'Diproses bertahap. Baris yang gagal bisa diunduh, diperbaiki, lalu diimpor ulang.'],
                ],
                impacts: [
                    ['area' => 'Daftar Dokumen', 'text' => 'Baris yang lolos jadi dokumen baru. Yang sudah ada dilewati.', 'url' => $documents],
                    ['area' => 'Pengingat WhatsApp', 'text' => 'Jadwal yang sudah lewat bisa dilewati agar tidak membanjiri WhatsApp.', 'url' => $logs],
                    ['area' => 'Tahap Periksa', 'text' => 'Belum menyimpan apa pun sampai Anda klik Impor.', 'url' => null],
                ],
                action: ImportDocuments::canAccess() ? ['label' => 'Buka halaman impor', 'url' => DocumentResource::getUrl('import')] : null,
            ),
        ];

        // Menonaktifkan user hanya dikerjakan super admin, jadi panduannya tidak ditampilkan ke peran lain.
        if (auth()->user()?->isSuperAdmin()) {
            $guides[] = $this->guide('nonaktifkan-user', '08', 'Nonaktifkan user',
                summary: 'Mematikan akun staf yang keluar tanpa menghapus data. Tugasnya pindah ke pengganti.',
                audience: 'Super admin',
                steps: [
                    ['Buka User, klik ikon nonaktifkan', 0, 'Ikonnya ada di ujung kanan baris user.'],
                    ['Lihat tugas yang akan dialihkan', 2.0, 'Dokumen, pengajuan, dan jenis dokumen yang dipegang user ini dihitung otomatis.'],
                    ['Pilih pengganti, klik Ya, nonaktifkan', 4.0, 'Pengganti wajib dipilih untuk setiap jenis tugas.'],
                    ['User keluar, PIC dokumen pindah ke pengganti', 7.4, 'Peran dan izinnya tetap disimpan, jadi user bisa diaktifkan kembali kapan saja.'],
                ],
                impacts: [
                    ['area' => 'Login', 'text' => 'User langsung keluar dan tidak bisa masuk lagi. Bisa diaktifkan kembali.', 'url' => $users],
                    ['area' => 'Dokumen & Pengajuan', 'text' => 'PIC, pemeriksa, dan penyetuju pindah ke pengganti.', 'url' => $documents],
                    ['area' => 'Jenis Dokumen', 'text' => 'Pengaturan PIC per jenis dokumen ikut dipindah.', 'url' => $documentTypes],
                ],
                action: $users === null ? null : ['label' => 'Buka User', 'url' => $users],
            );
        }

        // Fitur yang bisa dipakai user tampil lebih dulu; sisanya dikelompokkan sebagai "Butuh akses lain". Urutan di dalam kelompok tetap.
        usort($guides, fn (array $a, array $b): int => $b['canUse'] <=> $a['canUse']);

        return $guides;
    }

    /**
     * @param  list<array{0: string, 1: float|int, 2: string}>  $steps
     * @param  list<array{area: string, text: string, url: string|null}>  $impacts
     * @param  array{label: string, url: string, newTab?: bool}|null  $action
     * @return array{id: string, number: string, title: string, summary: string, audience: string, video: string, poster: string, steps: list<array{text: string, at: float, description: string}>, impacts: list<array{area: string, text: string, url: string|null}>, action: array{label: string, url: string, newTab: bool}|null, canUse: bool}
     */
    private function guide(string $id, string $number, string $title, string $summary, string $audience, array $steps, array $impacts, ?array $action): array
    {
        $file = "{$number}-{$id}";

        return [
            'id' => $id,
            'number' => $number,
            'title' => $title,
            'summary' => $summary,
            'audience' => $audience,
            'video' => route('panduan.video', ['file' => "{$file}.mp4"]),
            'poster' => route('panduan.video', ['file' => "{$file}.jpg"]),
            'steps' => array_map(fn (array $step): array => ['text' => $step[0], 'at' => (float) $step[1], 'description' => $step[2]], $steps),
            'impacts' => $impacts,
            'action' => $action === null ? null : $action + ['newTab' => false],
            'canUse' => $action !== null,
        ];
    }
}
