<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Companies\CompanyResource;
use App\Filament\Resources\DocumentRequests\DocumentRequestResource;
use App\Filament\Resources\Documents\DocumentResource;
use App\Filament\Resources\Documents\Pages\ImportDocuments;
use App\Filament\Resources\DocumentTypes\DocumentTypeResource;
use App\Filament\Resources\ReminderLogs\ReminderLogResource;
use App\Filament\Resources\ReminderTemplates\ReminderTemplateResource;
use App\Filament\Resources\TrashLogs\TrashLogResource;
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
     *     video: string|null,
     *     poster: string|null,
     *     steps: list<array{text: string, at: float|null, description: string}>,
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
        $trashLogs = TrashLogResource::canViewAny() ? TrashLogResource::getUrl() : null;

        $guides = [
            $this->guide('alur-pengajuan', '10', 'Alur lengkap pengajuan',
                summary: 'Gambaran utuh satu pengajuan: dari form pengajuan, draft dari legal, scan bertanda tangan, sampai dokumen fisik diterima dan masuk arsip.',
                audience: 'Semua (pengaju dan tim legal)',
                steps: [
                    ['Pengaju mengisi form pengajuan', 0, 'Lewat form publik tanpa login, atau dari menu Pengajuan Dokumen bagi yang punya akun.'],
                    ['Legal memeriksa, lalu Keluarkan Draft', 8.9, 'Draft keluar berarti pengajuan disetujui. Pengaju dikabari lewat WhatsApp.'],
                    ['Pengaju unduh draft dan tanda tangan', 16.9, 'Draft dicetak, ditandatangani, dan dicap di luar aplikasi.'],
                    ['Pengaju unggah scan bertanda tangan', 21.8, 'Dari tautan status di WhatsApp. Tim legal juga bisa mengunggahkan scan atas nama pengaju.'],
                    ['Batas penyerahan fisik 7 hari', 27, 'Dihitung sejak scan pertama diunggah. Mengganti scan tidak mengulang hitungan.'],
                    ['Terlambat? Pengaju tulis alasan', 31.8, 'Pengingat WhatsApp dikirim sehari setelah batas lewat, lalu 14 dan 28 hari setelah scan masuk. Alasan hanya dicatat, tidak memperpanjang batas.'],
                    ['Legal pantau lewat filter Tahap', 39.6, 'Yang terlambat tampil merah lengkap dengan alasan terakhirnya.'],
                    ['Dokumen asli sampai, klik Fisik Diterima', 47.3, 'Bisa dari daftar atau halaman detail. Cukup konfirmasi; scan bertanda tangan menjadi file arsip. Scan belum masuk? Unggah sekalian di jendela yang sama.'],
                    ['Selesai, dokumen masuk arsip', 54.6, 'Keterlambatan, bila ada, ikut tercatat dan pengaju dikabari.'],
                ],
                impacts: [
                    ['area' => 'Pengajuan Dokumen', 'text' => 'Status berjalan: Diperiksa, Menunggu Tanda Tangan, Menunggu Dokumen Fisik, lalu Selesai.', 'url' => $requests],
                    ['area' => 'WhatsApp', 'text' => 'Pengaju dan tim legal dikabari di setiap tahap, termasuk pengingat keterlambatan.', 'url' => null],
                    ['area' => 'Daftar Dokumen', 'text' => 'Dokumen yang fisiknya diterima otomatis masuk arsip.', 'url' => $documents],
                ],
                action: $requests === null
                    ? ['label' => 'Buka form pengajuan', 'url' => route('requests.public.create'), 'newTab' => true]
                    : ['label' => 'Buka Pengajuan Dokumen', 'url' => $requests],
            ),
            $this->guide('tambah-dokumen', '01', 'Tambah dokumen',
                summary: 'Mengarsipkan dokumen legal baru beserta filenya. Tersimpan sebagai versi 1 dan langsung dipantau masa berlakunya.',
                audience: 'Tim legal',
                steps: [
                    ['Buka Dokumen, klik Tambah Dokumen', 0, 'Tombolnya ada di kanan atas halaman Dokumen.'],
                    ['Pilih badan usaha dan jenis dokumen', 4, 'Ketik beberapa huruf untuk mencari. Jenis dokumen menentukan apakah dokumen punya masa berlaku.'],
                    ['Isi judul, nomor, dan pihak lawan', 10.4, 'Nomor dan pihak lawan boleh dikosongkan bila memang tidak ada.'],
                    ['Pilih PIC dan tanggal berakhir', 14.8, 'PIC adalah penerima pengingat WhatsApp. Tanggal berakhir wajib untuk dokumen yang punya masa berlaku.'],
                    ['Unggah file, lalu klik Buat', 21.6, 'Format PDF, DOC, DOCX, JPG, atau PNG, maksimal 20 MB.'],
                    ['Dokumen tersimpan sebagai versi 1', 26, 'Nama file dibuat otomatis agar mudah dilacak. Pengingat dihitung dari tanggal berakhir.'],
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
                    ['Klik Filter, lalu pilih status', 7, 'Bisa juga menyaring badan usaha, jenis, PIC, pihak lawan, dan rentang tanggal berakhir. Filter bisa digabung.'],
                    ['Klik Ekspor Excel, isinya sesuai filter', 16.2, 'Yang diekspor hanya dokumen yang sedang tampil, lengkap dengan status dan sisa hari.'],
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
                    ['Periksa tanggal baru yang sudah terisi', 4.4, 'Tanggal terbit dan berakhir baru diambil dari versi aktif. Ubah bila perlu.'],
                    ['Unggah file, klik Simpan Versi Baru', 8.8, 'Catatan perubahan ikut tersimpan di riwayat versi.'],
                    ['Versi baru aktif, versi lama jadi arsip', 13.2, 'Semua versi lama tetap bisa diunduh di tab Riwayat Versi.'],
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
                    ['Atur mulai, jarak, dan jam pengingat', 4.4, 'Contoh: mulai 30 hari sebelum berakhir, ulangi tiap 7 hari, pukul 08.00 WIB.'],
                    ['Ubah isi pesan bila perlu', 9.2, 'Klik tombol data seperti Nama Dokumen atau Nama PIC untuk menyisipkannya. Coba dulu lewat Kirim pesan uji.'],
                    ['Pantau di Log Pengingat, kirim ulang yang gagal', 13.6, 'Status Gagal? Klik Kirim ulang. Pesan yang sudah sampai tidak akan terkirim dua kali.'],
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
                    ['Pilih badan usaha dan jenis, isi detailnya', 4.4, 'Isi judul, pihak lawan bila ada, dan keperluannya.'],
                    ['Lampirkan dokumen, klik Kirim', 9.2, 'PDF, Word, atau gambar, maksimal 20 MB per file.'],
                    ['Simpan tautan status yang muncul', 13.6, 'Tautan yang sama dikirim ke WhatsApp pengaju. Halaman status menunjukkan tahap saat ini.'],
                    ['Tautan hilang? Cek status pakai nomor pengajuan', 18.8, 'Cukup isi nomor pengajuan dan nomor WhatsApp yang dipakai saat mengajukan.'],
                ],
                impacts: [
                    ['area' => 'Pengajuan Dokumen', 'text' => 'Masuk dengan status Diperiksa, pemeriksa dikabari WhatsApp.', 'url' => $requests],
                    ['area' => 'WhatsApp pengaju', 'text' => 'Menerima tautan status dan kabar tiap perubahan.', 'url' => null],
                    ['area' => 'Dashboard', 'text' => 'Ikut dihitung di "Pengajuan Menunggu Tindakan".', 'url' => $dashboard],
                ],
                action: ['label' => 'Buka form pengajuan', 'url' => route('requests.public.create'), 'newTab' => true],
            ),
            $this->guide('proses-pengajuan', '06', 'Proses pengajuan',
                summary: 'Memeriksa pengajuan, mengeluarkan draft, lalu memantau dokumen bertanda tangan sampai fisiknya diterima dan masuk arsip.',
                audience: 'Tim legal',
                steps: [
                    ['Buka tab Perlu tindakan saya, klik pengajuannya', 0, 'Pengajuan yang perlu diperiksa dan dokumen fisik yang masih ditunggu tampil di tab ini.'],
                    ['Periksa, lalu klik Keluarkan Draft', 5.2, 'Unggah file draft. Kurang lengkap? Pilih Revisi atau Tolak beserta catatannya. Draft masih bisa diganti sebelum scan masuk.'],
                    ['Pengaju tanda tangan, scan masuk', 10.8, 'Status menjadi Menunggu Dokumen Fisik. Dokumen asli wajib diserahkan ke legal dalam 7 hari sejak scan diunggah.'],
                    ['Pantau lewat filter Tahap', 16.6, 'Pilih Menunggu fisik atau Terlambat. Kolom Keterangan menunjukkan sisa hari, atau hari keterlambatan beserta alasan dari pengaju.'],
                    ['Dokumen asli sampai, klik Fisik Diterima', 24, 'Tombolnya ada di baris daftar dan di halaman detail. Cukup konfirmasi, scan otomatis menjadi file arsip; scan belum masuk bisa diunggah sekalian. Nomor dokumen dilengkapi nanti di menu Dokumen.'],
                    ['Selesai, dokumen masuk arsip', 31, 'Keterlambatan, bila ada, ikut tercatat. Pengajuan batal, saat revisi atau setelah draft keluar? Klik Batalkan beserta alasannya.'],
                ],
                impacts: [
                    ['area' => 'Pengaju', 'text' => 'Dikabari WhatsApp saat draft keluar, saat scan diterima (berisi batas penyerahan), saat terlambat, dan saat selesai atau dibatalkan.', 'url' => null],
                    ['area' => 'Daftar Dokumen', 'text' => 'Scan bertanda tangan otomatis masuk arsip sebagai versi 1.', 'url' => $documents],
                    ['area' => 'Dashboard', 'text' => 'Kartu Menunggu Fisik dan Terlambat menghitung dokumen yang belum diserahkan.', 'url' => $dashboard],
                ],
                action: $requests === null ? null : ['label' => 'Buka Pengajuan Dokumen', 'url' => $requests],
            ),
            $this->guide('impor-arsip-lama', '07', 'Impor arsip lama',
                summary: 'Memasukkan banyak dokumen lama sekaligus dari satu Excel dan ZIP. Data diperiksa dulu sebelum disimpan.',
                audience: 'Tim legal',
                steps: [
                    ['Klik Impor di halaman Dokumen', 0, 'Tombolnya ada di sebelah Tambah Dokumen.'],
                    ['Unduh template Excel, lalu isi datanya', 3.8, 'Sheet Petunjuk memuat daftar badan usaha, jenis dokumen, dan email PIC yang sah.'],
                    ['Unggah Excel dan ZIP, klik Periksa file', 7.8, 'Nama file di Excel harus sama dengan nama file di dalam ZIP.'],
                    ['Cek hasil pemeriksaan tiap baris', 12.2, 'Tiap baris diberi status siap, dilewati karena sudah ada, atau perlu diperbaiki beserta alasannya. Belum ada yang disimpan.'],
                    ['Klik Impor, dokumen langsung masuk', 19.8, 'Diproses bertahap. Baris yang gagal bisa diunduh, diperbaiki, lalu diimpor ulang.'],
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
                    ['Lihat tugas yang akan dialihkan', 4, 'Dokumen, pengajuan, dan jenis dokumen yang dipegang user ini dihitung otomatis.'],
                    ['Pilih pengganti, klik Ya, nonaktifkan', 8, 'Pengganti wajib dipilih untuk setiap jenis tugas.'],
                    ['User keluar, PIC dokumen pindah ke pengganti', 14.8, 'Peran dan izinnya tetap disimpan, jadi user bisa diaktifkan kembali kapan saja.'],
                ],
                impacts: [
                    ['area' => 'Login', 'text' => 'User langsung keluar dan tidak bisa masuk lagi. Bisa diaktifkan kembali.', 'url' => $users],
                    ['area' => 'Dokumen & Pengajuan', 'text' => 'PIC dan pemeriksa pindah ke pengganti.', 'url' => $documents],
                    ['area' => 'Jenis Dokumen', 'text' => 'Pengaturan PIC per jenis dokumen ikut dipindah.', 'url' => $documentTypes],
                ],
                action: $users === null ? null : ['label' => 'Buka User', 'url' => $users],
            );
        }

        // Mengatur jenis dokumen (PIC, pemeriksa, isian, lampiran) hanya untuk yang boleh menambah atau mengubah jenis dokumen.
        $user = auth()->user();
        if ($user?->isSuperAdmin() || $user?->can('Create:DocumentType') || $user?->can('Update:DocumentType')) {
            $guides[] = $this->guide('atur-jenis-dokumen', '12', 'Atur jenis dokumen & form pengajuan',
                summary: 'Menentukan masa berlaku, siapa PIC dan pemeriksa pengajuan, serta isian dan lampiran yang diminta di form pengajuan untuk tiap jenis dokumen.',
                audience: 'Admin pengelola Jenis Dokumen',
                steps: [
                    ['Buka Jenis Dokumen, klik jenisnya', 0, 'Ada di grup Data Master. Untuk jenis baru, klik tombol tambah di kanan atas.'],
                    ['Atur nama dan masa berlaku', 3, 'Aktifkan Memiliki Masa Berlaku bila dokumen jenis ini punya tanggal berakhir. Dokumennya lalu wajib punya tanggal berakhir dan mendapat pengingat WhatsApp.'],
                    ['Buka bagian Form Pengajuan', 8, 'Bagian ini tertutup saat halaman dibuka. Semua pengaturan di dalamnya hanya berlaku untuk pengajuan baru; pengajuan yang sudah masuk tidak berubah.'],
                    ['Pilih PIC pengajuan', 10.6, 'PIC dikabari WhatsApp setiap ada pengajuan baru jenis ini, jadi pilih staf legal yang punya nomor WhatsApp. Dikosongkan: memakai Pemeriksa, lalu pemeriksa aktif pertama yang punya nomor WhatsApp.'],
                    ['Tambah isian tambahan', 14, 'Pertanyaan khusus jenis ini di form pengajuan. Atur tipenya (Teks, Paragraf, Tanggal, atau Angka) dan apakah wajib. Klik Gunakan contoh Kerja Sama untuk isian dan lampiran siap pakai.'],
                    ['Tambah lampiran', 19, 'Berkas yang harus diunggah pengaju. Pilih kapan diminta: Selalu, Ada biaya, atau Perpanjangan / adendum. Dua pilihan terakhir hanya berlaku di form internal.'],
                    ['Pilih Pemeriksa, lalu Simpan', 22.4, 'Pemeriksa yang bisa Revisi, Tolak, dan Keluarkan Draft. Dikosongkan: pengajuan dari form publik diperiksa PIC pengajuan, pengajuan internal bisa diperiksa staf legal mana pun.'],
                    ['Form pengajuan ikut berubah', 29.2, 'Isian dan lampiran baru langsung tampil di form publik dan form internal begitu jenis ini dipilih.'],
                ],
                impacts: [
                    ['area' => 'Form pengajuan', 'text' => 'Isian dan lampiran tampil di form publik dan form Tambah Pengajuan.', 'url' => $requests],
                    ['area' => 'Pengajuan baru', 'text' => 'Masuk ke PIC dan pemeriksa yang dipilih. PIC dikabari WhatsApp.', 'url' => $requests],
                    ['area' => 'Dokumen', 'text' => 'Masa berlaku menentukan status dan pengingat WhatsApp dokumen jenis ini.', 'url' => $documents],
                ],
                action: ['label' => 'Buka Jenis Dokumen', 'url' => DocumentTypeResource::getUrl()],
            );
        }

        // Menghapus data dikerjakan super admin dan pemegang izin hapus, jadi panduan teks ini tidak ditampilkan ke peran lain.
        $deleteAction = $this->deleteAction();
        if ($deleteAction !== null) {
            $guides[] = $this->guide('hapus-tempat-sampah', '11', 'Hapus & tempat sampah',
                summary: 'Data yang dihapus masuk tempat sampah dulu, bukan hilang. Super admin bisa memulihkannya atau menghapusnya permanen.',
                audience: 'Super admin dan pemegang izin hapus',
                steps: [
                    ['Hapus dari daftar atau halaman detail', null, 'Klik ikon Hapus di baris tabel, atau tombol Hapus di halaman detail. Setiap penghapusan selalu meminta konfirmasi.'],
                    ['Hapus beberapa sekaligus', null, 'Centang baris di tabel, lalu klik Hapus terpilih. Baris yang tidak bisa dihapus dilewati dan disebut di notifikasi hasilnya.'],
                    ['Menghapus user', null, 'User dinonaktifkan dulu dan tugasnya dialihkan ke pengganti yang Anda pilih. Pada Hapus terpilih, user yang punya tugas dilewati; hapus satu per satu. Untuk staf yang keluar atau pindah, pakai Nonaktifkan, bukan Hapus.'],
                    ['Data masuk tempat sampah', null, 'Data hilang dari daftar, tetapi belum terhapus. Dokumen yang dihapus menghentikan pengingat masa berlakunya.'],
                    ['Super admin buka filter Data dihapus', null, 'Ada di tabel tiap menu. Pilih "Hanya data dihapus" untuk melihat isi tempat sampah, atau klik kartu menu di bagian Tempat sampah pada dasbor.'],
                    ['Pulihkan atau Hapus permanen', null, 'Pulihkan mengembalikan data seperti semula. User yang dipulihkan kembali nonaktif; klik tombol Aktifkan kembali di halaman user. Hapus permanen tidak bisa dibatalkan dan ikut menghapus filenya. Keduanya hanya bisa dilakukan super admin.'],
                    ['Hapus permanen ditolak bila masih dipakai', null, 'Modal hapus permanen langsung menyebut data yang masih memakainya, dan tombolnya tidak bisa diklik. Hapus dulu data itu (pindahkan ke tempat sampah), lalu coba lagi. Data pemakai yang sudah di tempat sampah ikut terhapus permanen beserta semua versi dan filenya. Kecuali user: hapus permanen user ditolak selama namanya masih tercatat di data mana pun, termasuk yang di tempat sampah. User seperti itu cukup dibiarkan di tempat sampah; ia tetap tidak bisa masuk dan tidak menerima WhatsApp.'],
                    ['Cek Riwayat Penghapusan', null, 'Setiap hapus, pulihkan, dan hapus permanen tercatat: jenis data, nama, tindakan, siapa, dan kapan. Hanya terlihat oleh super admin.'],
                ],
                impacts: [
                    ['area' => 'Daftar tiap menu', 'text' => 'Data yang dihapus hilang dari daftar, tetapi namanya tetap tampil dengan tanda (dihapus) di data yang memakainya.', 'url' => $documents],
                    ['area' => 'Dashboard', 'text' => 'Super admin melihat kartu Tempat sampah selama masih ada data yang menunggu keputusan.', 'url' => $dashboard],
                    ['area' => 'Riwayat Penghapusan', 'text' => 'Semua tindakan tercatat, termasuk data yang sudah dihapus permanen.', 'url' => $trashLogs],
                ],
                action: $deleteAction,
                video: false,
            );
        }

        $guides[] = $this->guide('serahkan-dokumen', '09', 'Tanda tangan & serahkan dokumen',
            summary: 'Pengaju mengunduh draft, menandatanganinya, mengunggah scan, lalu menyerahkan dokumen asli ke tim legal dalam 7 hari.',
            audience: 'Pengaju (lewat tautan status dari WhatsApp)',
            steps: [
                ['Buka tautan status dari WhatsApp', 0, 'Pesan "dokumen sudah jadi" berisi tautan ke halaman status pengajuan. Tanpa login.'],
                ['Unduh draft, tanda tangani, lalu scan', 4.2, 'Cetak draft, tanda tangani dan beri cap di luar aplikasi.'],
                ['Unggah scan, klik Unggah scan', 9, 'PDF, Word, atau gambar, maksimal 20 MB. Salah unggah? Scan bisa diganti selama dokumen belum diterima legal.'],
                ['Serahkan dokumen asli ke tim legal', 14, 'Batas penyerahan 7 hari sejak scan pertama diunggah, ditampilkan beserta sisa harinya.'],
                ['Terlambat? Tulis alasannya', 18.6, 'Lewat batas, halaman meminta alasan keterlambatan. Alasan boleh ditulis lebih dari sekali dan tidak memperpanjang batas.'],
                ['Selesai saat legal menerima dokumen', 26, 'Status menjadi Selesai dan pengaju dikabari lewat WhatsApp.'],
            ],
            impacts: [
                ['area' => 'WhatsApp pengaju', 'text' => 'Pengingat dikirim sehari setelah batas lewat, lalu 14 dan 28 hari setelah scan masuk, selama dokumen asli belum diterima.', 'url' => null],
                ['area' => 'Tim legal', 'text' => 'Dikabari saat scan masuk dan bila dokumen asli belum diterima 14 hari setelah scan. Alasan keterlambatan tampil di daftar pengajuan.', 'url' => $requests],
                ['area' => 'Koordinator legal', 'text' => 'Ikut dikabari bila dokumen fisik belum diterima 28 hari setelah scan masuk.', 'url' => null],
            ],
            action: ['label' => 'Cek status pengajuan', 'url' => route('requests.public.lookup'), 'newTab' => true],
        );

        // Fitur yang bisa dipakai user tampil lebih dulu; sisanya dikelompokkan sebagai "Butuh akses lain". Urutan di dalam kelompok tetap.
        usort($guides, fn (array $a, array $b): int => $b['canUse'] <=> $a['canUse']);

        return $guides;
    }

    /**
     * Tombol panduan "Hapus & tempat sampah": Riwayat Penghapusan untuk super admin, atau menu pertama yang boleh dihapus datanya oleh
     * pemegang izin hapus. Null bila user bukan keduanya, sehingga panduan itu tidak ditampilkan.
     *
     * @return array{label: string, url: string}|null
     */
    private function deleteAction(): ?array
    {
        $user = auth()->user();
        if ($user?->isSuperAdmin()) {
            return ['label' => 'Buka Riwayat Penghapusan', 'url' => TrashLogResource::getUrl()];
        }

        $menus = ['Document' => DocumentResource::class, 'Company' => CompanyResource::class, 'DocumentType' => DocumentTypeResource::class, 'User' => UserResource::class, 'DocumentRequest' => DocumentRequestResource::class];
        foreach ($menus as $subject => $resource) {
            if ($user?->can("Delete:{$subject}") || $user?->can("DeleteAny:{$subject}")) {
                return $resource::canViewAny() ? ['label' => 'Buka '.$resource::getPluralModelLabel(), 'url' => $resource::getUrl()] : null;
            }
        }

        return null;
    }

    /**
     * Panduan tanpa video (`video: false`) hanya memuat langkah bertulis: `video` dan `poster` bernilai null dan langkahnya tanpa detik.
     *
     * @param  list<array{0: string, 1: float|int|null, 2: string}>  $steps
     * @param  list<array{area: string, text: string, url: string|null}>  $impacts
     * @param  array{label: string, url: string, newTab?: bool}|null  $action
     * @return array{id: string, number: string, title: string, summary: string, audience: string, video: string|null, poster: string|null, steps: list<array{text: string, at: float|null, description: string}>, impacts: list<array{area: string, text: string, url: string|null}>, action: array{label: string, url: string, newTab: bool}|null, canUse: bool}
     */
    private function guide(string $id, string $number, string $title, string $summary, string $audience, array $steps, array $impacts, ?array $action, bool $video = true): array
    {
        $file = "{$number}-{$id}";

        return [
            'id' => $id,
            'number' => $number,
            'title' => $title,
            'summary' => $summary,
            'audience' => $audience,
            'video' => $video ? route('panduan.video', ['file' => "{$file}.mp4"]) : null,
            'poster' => $video ? route('panduan.video', ['file' => "{$file}.jpg"]) : null,
            'steps' => array_map(fn (array $step): array => ['text' => $step[0], 'at' => $step[1] === null ? null : (float) $step[1], 'description' => $step[2]], $steps),
            'impacts' => $impacts,
            'action' => $action === null ? null : $action + ['newTab' => false],
            'canUse' => $action !== null,
        ];
    }
}
