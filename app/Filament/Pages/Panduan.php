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

    protected static string|\UnitEnum|null $navigationGroup = 'Bantuan';

    protected static ?int $navigationSort = 1;

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
        $user = auth()->user();
        $dashboard = Dashboard::getUrl();
        $profile = filament()->getProfileUrl();
        $documents = DocumentResource::canViewAny() ? DocumentResource::getUrl() : null;
        $requests = DocumentRequestResource::canViewAny() ? DocumentRequestResource::getUrl() : null;
        $settings = ReminderTemplateResource::canViewAny() ? ReminderTemplateResource::getUrl() : null;
        $logs = ReminderLogResource::canViewAny() ? ReminderLogResource::getUrl() : null;
        $users = UserResource::canViewAny() ? UserResource::getUrl() : null;
        $companies = CompanyResource::canViewAny() ? CompanyResource::getUrl() : null;
        $documentTypes = DocumentTypeResource::canViewAny() ? DocumentTypeResource::getUrl() : null;
        $trashLogs = TrashLogResource::canViewAny() ? TrashLogResource::getUrl() : null;

        // Urutan mengikuti perjalanan pengguna: masuk, dasbor, alur pengajuan, dokumen, lalu pengaturan dan administrasi.
        $guides = [
            $this->guide('masuk-aplikasi', '01', 'Masuk ke aplikasi',
                summary: 'Masuk dengan email dan kata sandi atau dengan kode WhatsApp, mengatur nomor WhatsApp di Profil, lalu keluar.',
                audience: 'Semua user',
                steps: [
                    ['Buka halaman masuk, isi email dan kata sandi', 0, 'Alamat halaman masuk sama untuk semua peran. Akun dibuatkan oleh admin.'],
                    ['Klik Masuk', 4.6, 'Salah kata sandi berkali-kali? Tunggu sebentar, lalu coba lagi, atau masuk dengan kode WhatsApp.'],
                    ['Dasbor terbuka, menu ada di kiri', 7.2, 'Menu yang tampil mengikuti peran Anda. Menu yang tidak berhak Anda buka tidak muncul.'],
                    ['Atau masuk dengan kode WhatsApp', 12.4, 'Isi nomor WhatsApp yang terdaftar di akun Anda, lalu klik Kirim OTP WhatsApp.'],
                    ['Ketik kode dari WhatsApp, klik Verifikasi', 19.2, 'Kode berisi 6 digit dan berlaku 5 menit. Jangan bagikan kode kepada siapa pun.'],
                    ['Atur nomor WhatsApp di Profil', 23.8, 'Nomor ini dipakai untuk masuk dengan kode WhatsApp dan untuk menerima pengingat dokumen.'],
                    ['Keluar lewat menu foto profil', 29, 'Klik foto profil di kanan atas untuk membuka Profil, mengganti tema terang/gelap, atau keluar.'],
                ],
                impacts: [
                    ['area' => 'Profil', 'text' => 'Nomor WhatsApp dipakai untuk masuk dengan kode dan menerima pengingat dokumen.', 'url' => $profile],
                    ['area' => 'Menu', 'text' => 'Menu dan tombol yang tampil mengikuti peran Anda.', 'url' => $dashboard],
                ],
                action: $profile === null ? ['label' => 'Buka Dasbor', 'url' => $dashboard] : ['label' => 'Buka Profil', 'url' => $profile],
            ),
            $this->guide('kenali-dasbor', '02', 'Kenali dasbor',
                summary: 'Membaca ringkasan masa berlaku dokumen, dokumen yang segera berakhir, rekap per badan usaha, dan peringatan yang perlu ditindak.',
                audience: 'Semua user',
                steps: [
                    ['Perlu Perhatian: hal yang harus ditindak', 0, 'Muncul bila ada yang perlu ditindak, misalnya pengingat WhatsApp gagal terkirim. Klik Periksa untuk langsung ke halamannya.'],
                    ['Kartu masa berlaku dokumen', 5.2, 'Jumlah dokumen Aktif, Segera Berakhir, Kedaluwarsa, dan Tanpa Masa Berlaku dihitung dari hari ini.'],
                    ['Klik kartu, daftar langsung tersaring', 10.4, 'Halaman Dokumen terbuka dengan filter yang sesuai kartu. Hapus filter untuk melihat semua dokumen.'],
                    ['Berakhir dalam 30 hari, bisa diekspor', 16.2, 'Daftar dokumen yang berakhir hari ini sampai 30 hari ke depan. Ekspor Excel mengunduh daftar ini.'],
                    ['Rekap per badan usaha, klik angkanya', 21.2, 'Sisa masa berlaku dikelompokkan per badan usaha: 0–30, 31–60, 61–90 hari, dan kedaluwarsa.'],
                    ['Muncul dokumennya saja', 26.2, 'Daftar Dokumen terbuka dengan filter badan usaha dan status yang Anda klik.'],
                ],
                impacts: [
                    ['area' => 'Daftar Dokumen', 'text' => 'Setiap kartu dan angka membuka daftar Dokumen yang sudah tersaring.', 'url' => $documents],
                    ['area' => 'Pengingat WhatsApp', 'text' => 'Peringatan muncul bila jadwal kosong, PIC tanpa nomor, atau ada pesan gagal.', 'url' => $logs],
                ],
                action: ['label' => 'Buka Dasbor', 'url' => $dashboard],
            ),
            $this->guide('alur-pengajuan', '03', 'Alur lengkap pengajuan',
                summary: 'Gambaran utuh satu pengajuan: dari form pengajuan, draft dari legal, scan bertanda tangan, sampai dokumen fisik diterima dan masuk arsip.',
                audience: 'Semua (pengaju dan tim legal)',
                steps: [
                    ['Pengaju mengisi form pengajuan', 0, 'Lewat form publik tanpa login, atau dari menu Pengajuan Dokumen bagi yang punya akun.'],
                    ['Legal memeriksa, lalu Keluarkan Draft', 8.2, 'Draft keluar berarti pengajuan disetujui. Pengaju dikabari lewat WhatsApp.'],
                    ['Pengaju unduh draft dan tanda tangan', 16, 'Draft dicetak, ditandatangani, dan dicap di luar aplikasi.'],
                    ['Pengaju unggah scan bertanda tangan', 20.9, 'Dari tautan status di WhatsApp. Tim legal juga bisa mengunggahkan scan atas nama pengaju.'],
                    ['Batas penyerahan fisik 7 hari', 26.1, 'Dihitung sejak scan pertama diunggah. Mengganti scan tidak mengulang hitungan.'],
                    ['Terlambat? Pengaju tulis alasan', 30.5, 'Pengingat WhatsApp dikirim sehari setelah batas lewat, lalu 14 dan 28 hari setelah scan masuk. Alasan hanya dicatat, tidak memperpanjang batas.'],
                    ['Legal pantau lewat filter Tahap', 35.1, 'Yang terlambat tampil merah lengkap dengan alasan terakhirnya.'],
                    ['Dokumen asli sampai, klik Fisik Diterima', 40.5, 'Bisa dari ikon di daftar atau dari halaman detail. Cukup konfirmasi; scan bertanda tangan menjadi file arsip. Scan belum masuk? Unggah sekalian di jendela yang sama.'],
                    ['Selesai, dokumen masuk arsip', 45.6, 'Keterlambatan, bila ada, ikut tercatat dan pengaju dikabari.'],
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
            $this->guide('ajukan-dokumen', '04', 'Ajukan dokumen',
                summary: 'Divisi lain mengajukan dokumen lewat form publik tanpa login. Statusnya dikabari lewat WhatsApp.',
                audience: 'Divisi lain (bagikan tautan form-nya)',
                steps: [
                    ['Buka form pengajuan dari tautan yang dibagikan', 0, 'Tanpa akun. Tautan form dibagikan oleh tim legal. Sudah pernah mengajukan? Klik Cek status pengajuan.'],
                    ['Isi nama, nomor WhatsApp, dan divisi', 3, 'Nomor WhatsApp dipakai untuk mengabari status pengajuan.'],
                    ['Pilih badan usaha dan jenis dokumen', 7.4, 'Jenis dokumen menentukan isian dan lampiran tambahan yang diminta.'],
                    ['Isi judul, pihak lawan, dan keperluan', 12, 'Pihak lawan boleh dikosongkan bila dokumen tidak punya pihak lawan, misalnya akta pendirian.'],
                    ['Pilih file, pastikan namanya muncul, klik Kirim', 16.8, 'PDF, Word, atau gambar, maksimal 20 MB. Setelah dipilih, nama dan ukuran file tampil di kotak hijau; tombolnya berubah jadi Ganti file.'],
                    ['Simpan tautan status pengajuan', 22, 'Tautan yang sama dikirim ke WhatsApp. Halaman status menunjukkan tahap saat ini dan tempat mengunduh draft.'],
                    ['Tautan hilang? Cek status pakai nomor pengajuan', 27.6, 'Cukup isi nomor pengajuan dan nomor WhatsApp yang dipakai saat mengajukan. Halaman ini hanya menampilkan status.'],
                ],
                impacts: [
                    ['area' => 'Pengajuan Dokumen', 'text' => 'Masuk dengan status Diperiksa, pemeriksa dikabari WhatsApp.', 'url' => $requests],
                    ['area' => 'WhatsApp pengaju', 'text' => 'Menerima tautan status dan kabar tiap perubahan.', 'url' => null],
                    ['area' => 'Dashboard', 'text' => 'Ikut dihitung di "Pengajuan Menunggu Tindakan".', 'url' => $dashboard],
                ],
                action: ['label' => 'Buka form pengajuan', 'url' => route('requests.public.create'), 'newTab' => true],
            ),
            $this->guide('proses-pengajuan', '05', 'Proses pengajuan',
                summary: 'Memeriksa pengajuan, meminta revisi atau mengeluarkan draft, lalu memantau dokumen bertanda tangan sampai fisiknya diterima dan masuk arsip.',
                audience: 'Tim legal',
                steps: [
                    ['Buka tab Perlu tindakan saya, klik pengajuannya', 0, 'Pengajuan yang perlu diperiksa dan dokumen fisik yang masih ditunggu tampil di tab ini.'],
                    ['Periksa isi dan lampiran pengajuan', 5.2, 'Ringkasan, rincian, dan lampiran ada di satu halaman. Lampiran bisa dipratinjau atau diunduh.'],
                    ['Kurang lengkap? Klik Revisi dan tulis catatan', 8.4, 'Pengaju dikabari lewat WhatsApp dan bisa memperbaiki pengajuannya. Bila tidak bisa dilanjutkan, pilih Tolak beserta alasannya.'],
                    ['Lengkap? Klik Keluarkan Draft, unggah filenya', 11.6, 'Draft keluar berarti pengajuan disetujui. Draft masih bisa diganti sebelum scan masuk.'],
                    ['Pengaju tanda tangan, scan masuk', 17, 'Status menjadi Menunggu Dokumen Fisik. Dokumen asli wajib diserahkan ke legal dalam 7 hari sejak scan diunggah.'],
                    ['Pantau lewat filter Tahap', 22.6, 'Pilih Menunggu fisik atau Terlambat. Kolom Keterangan menunjukkan sisa hari, atau hari keterlambatan beserta alasan dari pengaju.'],
                    ['Dokumen asli sampai, klik ikon Fisik Diterima', 30, 'Ikon hijau ada di baris daftar dan tombolnya ada di halaman detail. Cukup konfirmasi, scan otomatis menjadi file arsip; scan belum masuk bisa diunggah sekalian. Nomor dokumen dilengkapi nanti di menu Dokumen.'],
                    ['Selesai, dokumen masuk arsip', 37.2, 'Keterlambatan, bila ada, ikut tercatat. Pengajuan batal saat revisi atau setelah draft keluar? Buka menu Lainnya, klik Batalkan, lalu tulis alasannya.'],
                ],
                impacts: [
                    ['area' => 'Pengaju', 'text' => 'Dikabari WhatsApp saat draft keluar, saat scan diterima (berisi batas penyerahan), saat terlambat, dan saat selesai atau dibatalkan.', 'url' => null],
                    ['area' => 'Daftar Dokumen', 'text' => 'Scan bertanda tangan otomatis masuk arsip sebagai versi 1.', 'url' => $documents],
                    ['area' => 'Dashboard', 'text' => 'Kartu Menunggu Fisik dan Terlambat menghitung dokumen yang belum diserahkan.', 'url' => $dashboard],
                ],
                action: $requests === null ? null : ['label' => 'Buka Pengajuan Dokumen', 'url' => $requests],
            ),
            $this->guide('serahkan-dokumen', '06', 'Tanda tangan & serahkan dokumen',
                summary: 'Pengaju mengunduh draft, menandatanganinya, mengunggah scan, lalu menyerahkan dokumen asli ke tim legal dalam 7 hari.',
                audience: 'Pengaju (lewat tautan status dari WhatsApp)',
                steps: [
                    ['Buka tautan status dari WhatsApp', 0, 'Pesan "dokumen sudah jadi" berisi tautan ke halaman status pengajuan. Tanpa login.'],
                    ['Unduh draft, tanda tangani, lalu scan', 4, 'Klik Unduh draft dokumen; file langsung terunduh dan siap dicetak. Tanda tangani dan beri cap di luar aplikasi.'],
                    ['Pilih file scan, klik Unggah scan', 8.8, 'PDF, Word, atau gambar, maksimal 20 MB. Salah unggah? Scan bisa diganti selama dokumen belum diterima legal.'],
                    ['Serahkan dokumen asli ke tim legal', 13.8, 'Batas penyerahan 7 hari sejak scan pertama diunggah, ditampilkan beserta sisa harinya.'],
                    ['Terlambat? Tulis alasannya', 18.2, 'Lewat batas, halaman meminta alasan keterlambatan. Alasan boleh ditulis lebih dari sekali dan tidak memperpanjang batas.'],
                    ['Selesai saat legal menerima dokumen', 25.5, 'Status menjadi Selesai dan pengaju dikabari lewat WhatsApp.'],
                ],
                impacts: [
                    ['area' => 'WhatsApp pengaju', 'text' => 'Pengingat dikirim sehari setelah batas lewat, lalu 14 dan 28 hari setelah scan masuk, selama dokumen asli belum diterima.', 'url' => null],
                    ['area' => 'Tim legal', 'text' => 'Dikabari saat scan masuk dan bila dokumen asli belum diterima 14 hari setelah scan. Alasan keterlambatan tampil di daftar pengajuan.', 'url' => $requests],
                    ['area' => 'Koordinator legal', 'text' => 'Ikut dikabari bila dokumen fisik belum diterima 28 hari setelah scan masuk.', 'url' => null],
                ],
                action: ['label' => 'Cek status pengajuan', 'url' => route('requests.public.lookup'), 'newTab' => true],
            ),
            $this->guide('tambah-dokumen', '07', 'Tambah dokumen',
                summary: 'Mengarsipkan dokumen legal baru beserta filenya. Tersimpan sebagai versi 1 dan langsung dipantau masa berlakunya.',
                audience: 'Tim legal',
                steps: [
                    ['Buka Dokumen, klik Tambah Dokumen', 0, 'Tombolnya ada di kanan atas halaman Dokumen.'],
                    ['Pilih badan usaha dan jenis dokumen', 3, 'Ketik beberapa huruf untuk mencari. Jenis dokumen menentukan apakah dokumen punya masa berlaku.'],
                    ['Isi judul, nomor, dan pihak lawan', 7.8, 'Nomor dan pihak lawan boleh dikosongkan bila memang tidak ada.'],
                    ['Pilih PIC dan tanggal berakhir', 14, 'PIC adalah penerima pengingat WhatsApp. Tanggal berakhir wajib untuk dokumen yang punya masa berlaku.'],
                    ['Unggah file, lalu klik Simpan', 18.8, 'Format PDF, DOC, DOCX, JPG, atau PNG, maksimal 20 MB.'],
                    ['Dokumen tersimpan sebagai versi 1', 23.4, 'Nama file dibuat otomatis agar mudah dilacak. Pengingat dihitung dari tanggal berakhir.'],
                ],
                impacts: [
                    ['area' => 'Daftar Dokumen', 'text' => 'Langsung muncul, bisa dicari dan diekspor.', 'url' => $documents],
                    ['area' => 'Dashboard', 'text' => 'Ikut dihitung di status dan daftar yang akan berakhir.', 'url' => $dashboard],
                    ['area' => 'Pengingat WhatsApp', 'text' => 'Dikirim ke PIC sesuai jadwal sebelum tanggal berakhir.', 'url' => $settings],
                ],
                action: DocumentResource::canCreate() ? ['label' => 'Tambah dokumen', 'url' => DocumentResource::getUrl('create')] : null,
            ),
            $this->guide('cari-dan-ekspor', '08', 'Cari & ekspor dokumen',
                summary: 'Menemukan dokumen lewat pencarian dan filter, mengganti tampilan tabel atau grid, lalu membawa daftarnya ke Excel.',
                audience: 'Semua yang boleh melihat dokumen',
                steps: [
                    ['Ketik judul, nomor, atau pihak lawan', 0, 'Daftar langsung tersaring saat Anda mengetik.'],
                    ['Klik Filter, pilih status atau lainnya', 5, 'Bisa juga menyaring badan usaha, jenis, PIC, pihak lawan, format file, dan rentang tanggal berakhir. Filter bisa digabung.'],
                    ['Daftar tersaring sesuai filter', 9.6, 'Filter yang aktif tampil di atas tabel. Klik tanda silang untuk menghapusnya.'],
                    ['Pilih tampilan Tabel atau Grid', 12.8, 'Grid menampilkan dokumen sebagai kartu; pilihan Anda diingat.'],
                    ['Klik Ekspor Excel, isinya sesuai filter', 18, 'Yang diekspor hanya dokumen yang sedang tampil, lengkap dengan status dan sisa hari.'],
                ],
                impacts: [
                    ['area' => 'Data dokumen', 'text' => 'Tidak berubah. Pencarian dan ekspor hanya membaca data.', 'url' => null],
                    ['area' => 'File Excel', 'text' => 'Hanya berisi dokumen yang boleh Anda lihat.', 'url' => $documents],
                ],
                action: $documents === null ? null : ['label' => 'Buka Dokumen', 'url' => $documents],
            ),
            $this->guide('perpanjang-dokumen', '09', 'Perpanjang dokumen',
                summary: 'Memperbarui masa berlaku dengan mengunggah versi baru. Tanggal baru terisi otomatis dan versi lama tetap tersimpan.',
                audience: 'Tim legal',
                steps: [
                    ['Buka dokumen, klik Perpanjang', 0, 'Badge Segera Berakhir atau Kedaluwarsa menandai dokumen yang perlu diperpanjang.'],
                    ['Periksa tanggal baru yang sudah terisi', 4.6, 'Tanggal terbit dan berakhir baru diambil dari versi aktif. Ubah bila perlu.'],
                    ['Unggah file, tulis catatan, klik Simpan Versi Baru', 7.8, 'Catatan perubahan ikut tersimpan di riwayat versi.'],
                    ['Versi baru aktif, status ikut diperbarui', 12.4, 'Status dan pengingat dihitung ulang dari tanggal berakhir baru.'],
                    ['Versi lama tersimpan di Riwayat Versi', 17.2, 'Semua versi lama tetap bisa diunduh di tab Riwayat Versi.'],
                ],
                impacts: [
                    ['area' => 'Riwayat Versi', 'text' => 'Versi lama tetap bisa diunduh, tetapi tidak bisa diubah.', 'url' => $documents],
                    ['area' => 'Status & Dashboard', 'text' => 'Dihitung ulang dari tanggal berakhir baru.', 'url' => $dashboard],
                    ['area' => 'Pengingat WhatsApp', 'text' => 'Jadwal lama dibatalkan, jadwal baru mulai dari awal.', 'url' => $logs],
                ],
                action: $documents === null ? null : ['label' => 'Buka Dokumen', 'url' => $documents],
            ),
            $this->guide('impor-arsip-lama', '10', 'Impor arsip lama',
                summary: 'Memasukkan banyak dokumen lama sekaligus dari satu Excel dan ZIP. Data diperiksa dulu sebelum disimpan.',
                audience: 'Tim legal',
                steps: [
                    ['Klik Impor di halaman Dokumen', 0, 'Tombolnya ada di sebelah Tambah Dokumen.'],
                    ['Unduh template Excel, lalu isi datanya', 2.8, 'Sheet Petunjuk memuat daftar badan usaha, jenis dokumen, dan email PIC yang sah.'],
                    ['Unggah Excel dan ZIP, klik Periksa file', 5.8, 'Nama file di Excel harus sama dengan nama file di dalam ZIP.'],
                    ['Cek hasil pemeriksaan tiap baris', 12, 'Tiap baris diberi status siap, dilewati karena sudah ada, atau perlu diperbaiki beserta alasannya. Belum ada yang disimpan.'],
                    ['Klik Impor, dokumen langsung masuk', 16, 'Diproses bertahap. Baris yang gagal bisa diunduh, diperbaiki, lalu diimpor ulang.'],
                    ['Dokumen baru muncul di daftar', 21.6, 'Dokumen hasil impor langsung bisa dicari, diekspor, dan dipantau masa berlakunya.'],
                ],
                impacts: [
                    ['area' => 'Daftar Dokumen', 'text' => 'Baris yang lolos jadi dokumen baru. Yang sudah ada dilewati.', 'url' => $documents],
                    ['area' => 'Pengingat WhatsApp', 'text' => 'Jadwal yang sudah lewat bisa dilewati agar tidak membanjiri WhatsApp.', 'url' => $logs],
                    ['area' => 'Tahap Periksa', 'text' => 'Belum menyimpan apa pun sampai Anda klik Impor.', 'url' => null],
                ],
                action: ImportDocuments::canAccess() ? ['label' => 'Buka halaman impor', 'url' => DocumentResource::getUrl('import')] : null,
            ),
            $this->guide('pengingat-whatsapp', '11', 'Atur pengingat WhatsApp',
                summary: 'Mengatur kapan pengingat dikirim dan isi pesannya, mencoba lewat pesan uji, lalu memantau pengirimannya.',
                audience: 'Admin',
                steps: [
                    ['Buka Pengaturan WhatsApp, klik Atur jadwal', 0, 'Jadwal yang aktif dan contoh pesan langsung terlihat di halaman ini.'],
                    ['Atur mulai, jarak, dan jam pengingat', 2.8, 'Contoh: mulai 30 hari sebelum berakhir, ulangi tiap 7 hari, pukul 08.00 WIB. Pratinjau tanggal pengiriman tampil di bawahnya.'],
                    ['Ubah isi pesan, sisipkan data otomatis', 8.4, 'Klik tombol data seperti Nama Dokumen atau Nama PIC untuk menyisipkannya; data terisi otomatis saat dikirim.'],
                    ['Coba dulu lewat Kirim pesan uji', 15.2, 'Pesan ditandai [PESAN UJI] dan dikirim dengan data contoh ke nomor yang Anda isi.'],
                    ['Pantau di Log Pengingat, kirim ulang yang gagal', 22, 'Status Gagal? Klik Kirim ulang. Pesan yang sudah sampai tidak akan terkirim dua kali.'],
                ],
                impacts: [
                    ['area' => 'Semua dokumen bermasa berlaku', 'text' => 'Jadwal ini berlaku untuk semuanya sekaligus.', 'url' => $settings],
                    ['area' => 'Log Pengingat', 'text' => 'Setiap pesan tercatat. Kirim ulang tidak membuat pesan ganda.', 'url' => $logs],
                    ['area' => 'Dashboard', 'text' => 'Muncul peringatan bila jadwal kosong, PIC tanpa nomor, atau ada pesan gagal.', 'url' => $dashboard],
                ],
                action: $settings === null ? null : ['label' => 'Buka Pengaturan WhatsApp', 'url' => $settings],
            ),
        ];

        // Mengatur jenis dokumen (PIC, pemeriksa, isian, lampiran) hanya untuk yang boleh menambah atau mengubah jenis dokumen.
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

        // Badan usaha dan akun user hanya dikelola admin.
        $manageAction = CompanyResource::canCreate() ? ['label' => 'Tambah badan usaha', 'url' => CompanyResource::getUrl('create')]
            : (UserResource::canCreate() ? ['label' => 'Tambah user', 'url' => UserResource::getUrl('create')] : null);
        if ($user?->isSuperAdmin() || $manageAction !== null) {
            $guides[] = $this->guide('kelola-badan-usaha-user', '13', 'Kelola badan usaha & user',
                summary: 'Menambah badan usaha, membuat akun user beserta nomor WhatsApp dan perannya, lalu mengatur izin tiap peran.',
                audience: 'Super admin',
                steps: [
                    ['Buka Badan Usaha, klik Tambah Badan Usaha', 0, 'Badan usaha dipakai di dokumen dan pilihan di form pengajuan. Yang dinonaktifkan tidak bisa dipilih lagi, tetapi datanya tetap tersimpan.'],
                    ['Isi nama dan bentuk badan usaha, klik Simpan', 2.8, 'Bentuk badan usaha (PT, CV, dan lainnya) ikut tampil di nama, misalnya PT Nusantara Reefer Logistik.'],
                    ['Buka User, klik Tambah User', 9.2, 'Hanya admin yang bisa membuat akun. Tidak ada pendaftaran mandiri.'],
                    ['Isi data, nomor WhatsApp, dan peran', 12, 'Nomor WhatsApp dipakai untuk masuk dengan kode dan menerima pengingat. Peran menentukan menu dan tombol yang bisa dipakai.'],
                    ['Atur izin tiap peran di Hak Akses', 20.8, 'Centang izin per menu, misalnya boleh melihat, membuat, atau menghapus. Perubahan berlaku untuk semua user dengan peran itu.'],
                ],
                impacts: [
                    ['area' => 'Badan Usaha', 'text' => 'Langsung bisa dipilih di dokumen dan form pengajuan.', 'url' => $companies],
                    ['area' => 'User', 'text' => 'Akun baru bisa langsung masuk dengan email atau kode WhatsApp.', 'url' => $users],
                    ['area' => 'Hak Akses', 'text' => 'Izin peran menentukan menu dan tombol yang tampil untuk setiap user.', 'url' => null],
                ],
                action: $manageAction,
            );
        }

        // Menonaktifkan user hanya dikerjakan super admin, jadi panduannya tidak ditampilkan ke peran lain.
        if ($user?->isSuperAdmin()) {
            $guides[] = $this->guide('nonaktifkan-user', '14', 'Nonaktifkan user',
                summary: 'Mematikan akun staf yang keluar tanpa menghapus data. Tugasnya pindah ke pengganti.',
                audience: 'Super admin',
                steps: [
                    ['Buka User, klik ikon nonaktifkan', 0, 'Ikonnya ada di ujung kanan baris user. Untuk staf yang keluar atau pindah, pakai Nonaktifkan, bukan Hapus.'],
                    ['Lihat tugas yang akan dialihkan', 4.8, 'Dokumen, pengajuan, dan jenis dokumen yang dipegang user ini dihitung otomatis.'],
                    ['Pilih pengganti, klik Ya, nonaktifkan', 8.4, 'Pengganti wajib dipilih untuk setiap jenis tugas.'],
                    ['User keluar, tugasnya pindah ke pengganti', 13, 'Peran dan izinnya tetap disimpan, jadi user bisa diaktifkan kembali kapan saja.'],
                ],
                impacts: [
                    ['area' => 'Login', 'text' => 'User langsung keluar dan tidak bisa masuk lagi. Bisa diaktifkan kembali.', 'url' => $users],
                    ['area' => 'Dokumen & Pengajuan', 'text' => 'PIC dan pemeriksa pindah ke pengganti.', 'url' => $documents],
                    ['area' => 'Jenis Dokumen', 'text' => 'Pengaturan PIC per jenis dokumen ikut dipindah.', 'url' => $documentTypes],
                ],
                action: $users === null ? null : ['label' => 'Buka User', 'url' => $users],
            );
        }

        // Menghapus data dikerjakan super admin dan pemegang izin hapus, jadi panduan ini tidak ditampilkan ke peran lain.
        $deleteAction = $this->deleteAction();
        if ($deleteAction !== null) {
            $guides[] = $this->guide('hapus-tempat-sampah', '15', 'Hapus & tempat sampah',
                summary: 'Data yang dihapus masuk tempat sampah dulu, bukan hilang. Super admin bisa memulihkannya atau menghapusnya permanen.',
                audience: 'Super admin dan pemegang izin hapus',
                steps: [
                    ['Klik ikon Hapus di baris dokumen', 0, 'Klik ikon Hapus di baris tabel, atau tombol Hapus di halaman detail. Untuk beberapa sekaligus, centang barisnya lalu klik Hapus terpilih; baris yang tidak bisa dihapus dilewati dan disebut di notifikasinya. Menghapus user? User dinonaktifkan dulu dan tugasnya dialihkan ke pengganti; pada Hapus terpilih, user yang punya tugas dilewati. Untuk staf yang keluar atau pindah, pakai Nonaktifkan, bukan Hapus.'],
                    ['Konfirmasi, data masuk tempat sampah', 4.4, 'Data hilang dari daftar, tetapi belum terhapus. Dokumen yang dihapus menghentikan pengingat masa berlakunya; filenya tidak ikut terhapus.'],
                    ['Super admin buka filter Data dihapus', 11.6, 'Ada di filter tabel tiap menu. Pilih "Hanya data dihapus" untuk melihat isi tempat sampah, atau klik kartu menu di bagian Tempat sampah pada dasbor.'],
                    ['Pulihkan atau Hapus permanen', 14.6, 'Hapus permanen tidak bisa dibatalkan dan ikut menghapus semua versi beserta filenya. Bila datanya masih dipakai data lain, modal menyebut pemakainya dan tombolnya tidak bisa diklik: hapus dulu data itu (pindahkan ke tempat sampah), lalu coba lagi. Kecuali user: hapus permanen user ditolak selama namanya masih tercatat di data mana pun, termasuk yang di tempat sampah.'],
                    ['Pulihkan mengembalikan data seperti semula', 22, 'User yang dipulihkan kembali nonaktif; klik tombol Aktifkan kembali di halaman user. Pulihkan dan hapus permanen hanya bisa dilakukan super admin.'],
                    ['Cek Riwayat Penghapusan', 26.4, 'Setiap hapus, pulihkan, dan hapus permanen tercatat: jenis data, nama, tindakan, siapa, dan kapan. Hanya terlihat oleh super admin.'],
                ],
                impacts: [
                    ['area' => 'Daftar tiap menu', 'text' => 'Data yang dihapus hilang dari daftar, tetapi namanya tetap tampil dengan tanda (dihapus) di data yang memakainya.', 'url' => $documents],
                    ['area' => 'Dashboard', 'text' => 'Super admin melihat kartu Tempat sampah selama masih ada data yang menunggu keputusan.', 'url' => $dashboard],
                    ['area' => 'Riwayat Penghapusan', 'text' => 'Semua tindakan tercatat, termasuk data yang sudah dihapus permanen.', 'url' => $trashLogs],
                ],
                action: $deleteAction,
            );
        }

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
