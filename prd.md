# PRD: LMS (Legal Management System)

| Item | Isi |
|---|---|
| Nama produk | LMS (Legal Management System) |
| Stack | Laravel, Filament, Filament Shield, SQLite |
| Penyimpanan file | S3 / S3-compatible (MinIO, R2, dll) |
| Notifikasi | WhatsApp via WagHub (`waghub.mekayastudio.com`) |
| Zona waktu | Asia/Jakarta |
| Bahasa UI | Indonesia |

---

## 0. ATURAN KERJA UNTUK AGEN / DEVELOPER (WAJIB DIBACA DULU)

### 0.1 Aturan Git

1. Semua pekerjaan **hanya** boleh di-commit ke branch lokal **`ahtar-dev`**.
2. Sebelum mulai Work apa pun, jalankan:
   ```bash
   git branch --list ahtar-dev
   ```
   - Jika branch **belum ada**, buat dulu: `git checkout -b ahtar-dev`
   - Jika **sudah ada**: `git checkout ahtar-dev`
3. Sebelum setiap commit, verifikasi branch aktif:
   ```bash
   git branch --show-current   # harus keluar: ahtar-dev
   ```
   Jika hasilnya bukan `ahtar-dev`, **berhenti** dan pindah branch dulu.
4. **DILARANG KERAS** commit, merge, rebase, cherry-pick, atau push ke branch `staging` maupun `main`.
5. Jangan push ke remote kecuali diminta eksplisit oleh pemilik repo.
6. **Satu Work = satu commit** (atau beberapa commit kecil) setelah Work tersebut selesai dan lolos Definition of Done. Jangan menggabungkan beberapa Work dalam satu commit.
7. Format pesan commit: `feat(work-N): ringkasan singkat`, contoh `feat(work-2): master data perusahaan`.

### 0.2 Aturan Pengerjaan

1. Kerjakan **satu Work per satu waktu**, berurutan. Jangan mulai Work berikutnya sebelum Work sebelumnya selesai, dites, dan di-commit.
2. Setiap selesai satu Work, berhenti dan laporkan ringkasan hasil ke pemilik proyek sebelum lanjut.
3. **Jangan pernah** menulis secret (token WhatsApp, kredensial S3) ke dalam kode atau commit. Semua lewat `.env`, dan pastikan `.env` ada di `.gitignore`. Sediakan `.env.example` dengan nilai kosong.
4. Ikuti konvensi standar Laravel dan Filament (Resource, Policy, Form/Table schema).

---

## 1. Latar Belakang

Dokumen legal perusahaan (perjanjian kerja sama, dokumen perusahaan, dan sejenisnya) saat ini rawan tercecer, sulit dicari, dan masa berlakunya sering terlewat sehingga perpanjangan terlambat. LMS menyediakan arsip digital berbasis cloud, pengingat otomatis untuk dokumen yang punya masa tenggang, dan kontrol versi agar riwayat dokumen tidak hilang.

## 2. Tujuan

1. Mengarsipkan dokumen legal secara digital di penyimpanan cloud.
2. Mengingatkan user lewat WhatsApp sebelum dokumen berakhir masa berlakunya.
3. Menyimpan seluruh versi dokumen. Versi lama menjadi arsip dan tidak pernah dihapus.
4. Menyediakan data master (perusahaan/badan usaha dan jenis dokumen) agar data konsisten.

### Non-Goals (di luar cakupan saat ini)

- Tanda tangan digital / e-sign.
- Workflow persetujuan (approval) dokumen.
- OCR / pencarian isi dokumen.
- Integrasi dengan sistem eksternal selain WagHub dan S3.

## 3. Pengguna dan Hak Akses

- Autentikasi lewat Filament panel (login email + password).
- Hak akses **dinamis** memakai **Filament Shield** (role dan permission dikelola dari UI, bukan hardcode).
- Role awal yang di-seed (dapat diubah dari UI):
  - `super_admin`: akses penuh.
  - `legal`: kelola dokumen, lihat master data.
  - `viewer`: hanya lihat dan unduh.
- Setiap user memiliki **nomor WhatsApp** (dipakai sebagai tujuan pengingat).

## 4. Alur Utama

```
Orang legal → upload file → sistem simpan → masa tenggang berjalan
→ sistem mengingatkan (WhatsApp) → dokumen diperbarui (upload versi baru)
→ sistem menyimpan versioning control (versi lama tidak hilang, menjadi arsip)
```

## 5. Model Data (garis besar)

| Tabel | Kolom utama |
|---|---|
| `users` | (bawaan) + `phone` |
| `companies` | `id`, `name`, `legal_form` (PT/CV/Yayasan/dll), `npwp` (nullable), `address` (nullable), `is_active` |
| `document_types` | `id`, `name`, `has_expiry` (bool), `reminder_days` (JSON, contoh `[90,60,30]`), `is_active` |
| `documents` | `id`, `company_id`, `document_type_id`, `document_number` (nullable), `title`, `counterparty` (nullable), `pic_user_id`, `current_version_id`, `notes` |
| `document_versions` | `id`, `document_id`, `version_number`, `file_path`, `file_name`, `file_size`, `mime_type`, `issued_date` (nullable), `expiry_date` (nullable), `is_current` (bool), `change_note`, `uploaded_by`, timestamps |
| `reminder_logs` | `id`, `document_version_id`, `offset_days`, `recipient_phone`, `status`, `provider_response` (JSON), `sent_at`, unique `(document_version_id, offset_days)` |

Prinsip: identitas dokumen ada di `documents`, sedangkan file dan masa berlaku ada di `document_versions`. Pengingat hanya berlaku untuk versi `is_current = true`.

---

## 6. WORK (dikerjakan satu per satu, berurutan)

> Setiap Work diakhiri dengan: tes lolos → verifikasi branch `ahtar-dev` → commit.

### WORK 1: Setup Proyek dan Fondasi

**Tujuan:** Proyek Laravel siap jalan dengan Filament dan SQLite.

**Cakupan:**
- Inisialisasi Laravel, install Filament (panel `admin`), konfigurasi SQLite.
- Set timezone `Asia/Jakarta`, locale `id`.
- Siapkan `.env.example` dengan variabel: koneksi S3 (`AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `AWS_BUCKET`, `AWS_ENDPOINT`, `AWS_USE_PATH_STYLE_ENDPOINT`) dan WagHub (`WAGHUB_URL`, `WAGHUB_TOKEN`, `WAGHUB_ROUTE_KEY`).
- Pastikan `.env` ter-ignore git.
- Atur `FILESYSTEM_DISK` agar dokumen memakai disk S3 (private).
- Konfigurasi S3 (SeaweedFS, S3-compatible). Nilai non-secret yang dipakai:
  - `AWS_DEFAULT_REGION=us-east-1`
  - `AWS_BUCKET=lms`
  - `AWS_ENDPOINT=https://storage.completeselular.com`
  - `AWS_URL=https://storage.completeselular.com/lms`
  - `AWS_USE_PATH_STYLE_ENDPOINT=true` (wajib untuk SeaweedFS)
  - `AWS_ACCESS_KEY_ID` dan `AWS_SECRET_ACCESS_KEY` **hanya di `.env` lokal**, jangan pernah masuk PRD, kode, atau commit.
- File LMS disimpan di bucket khusus `lms` dengan prefix **`lms/`** (contoh `lms/documents/{company_id}/{document_id}/v{n}/...`).
- `AWS_VERIFY_SSL=false` hanya boleh untuk lingkungan development. Di staging/production wajib `true` dengan sertifikat valid.

**Definition of Done:**
- `php artisan serve` jalan dan halaman login Filament terbuka.
- Git: branch `ahtar-dev` aktif. Commit: `feat(work-1): setup proyek laravel filament sqlite`.

---

### WORK 2: Autentikasi dan Hak Akses Dinamis (Filament Shield)

**Tujuan:** Role dan permission bisa dikelola dari UI.

**Cakupan:**
- Install dan konfigurasi Filament Shield.
- Tambah kolom `phone` pada `users` dan field-nya di User Resource (validasi format nomor Indonesia).
- Seeder: user `super_admin` awal dan role `legal`, `viewer`.
- Resource wajib memakai Policy yang dihasilkan Shield.

**Definition of Done:**
- Super admin bisa membuat role baru dan mengatur permission dari UI.
- User tanpa permission tidak melihat menu/resource terkait.
- Commit: `feat(work-2): filament shield dan manajemen user`.

---

### WORK 3: Data Master Perusahaan / Badan Usaha

**Tujuan:** CRUD perusahaan sebagai referensi dokumen.

**Cakupan:**
- Migration, Model, Filament Resource `Company` (list, create, edit).
- Validasi: nama wajib dan unik; NPWP opsional.
- Soft delete atau toggle `is_active` (jangan hapus permanen jika sudah dipakai dokumen).
- Pencarian dan filter status di tabel.

**Definition of Done:** CRUD berfungsi dan permission Shield ter-generate. Commit: `feat(work-3): master data perusahaan`.

---

### WORK 4: Data Master Jenis Dokumen

**Tujuan:** CRUD jenis dokumen beserta konfigurasi pengingat.

**Cakupan:**
- Migration, Model, Filament Resource `DocumentType`.
- Field `has_expiry` (apakah dokumen ini punya masa tenggang).
- Field `reminder_days`: input berulang (repeater/tags) berisi angka hari sebelum jatuh tempo, contoh `90, 60, 30`. Hanya aktif jika `has_expiry = true`.
- Validasi: angka bulat positif, tanpa duplikat, disimpan urut menurun.
- Seeder contoh: Perjanjian Kerja Sama (90,60,30), Izin Usaha/NIB (60,30,7), Akta Pendirian (tanpa masa tenggang).

**Definition of Done:** CRUD berfungsi dan konfigurasi pengingat tersimpan benar. Commit: `feat(work-4): master data jenis dokumen`.

---

### WORK 5: Pengarsipan Dokumen (Upload dan Penyimpanan Cloud)

**Tujuan:** Orang legal dapat mengunggah dan mengarsipkan dokumen.

**Cakupan:**
- Migration `documents` dan `document_versions`; Model dan relasi.
- Filament Resource `Document`:
  - Form: perusahaan, jenis dokumen, judul, nomor dokumen, pihak lawan, PIC (user), tanggal terbit, tanggal berakhir (wajib bila jenis `has_expiry`), catatan, upload file.
  - Saat create: otomatis membuat `document_versions` versi 1 dengan `is_current = true`.
- File disimpan di S3 pada disk **private**, path terstruktur (misal `documents/{company_id}/{document_id}/v{n}/{nama-file}`).
- Batas file: PDF, DOC, DOCX, JPG, PNG; maksimal 20 MB (konfigurable).
- Unduh/preview memakai **temporary signed URL** (bukan URL publik).
- Tabel: kolom perusahaan, jenis, judul, tanggal berakhir, PIC; filter perusahaan, jenis; pencarian judul/nomor.

**Definition of Done:** Upload berhasil tersimpan di S3, file bisa diunduh lewat signed URL, hak akses sesuai Shield. Commit: `feat(work-5): pengarsipan dokumen dan upload s3`.

---

### WORK 6: Versioning Control

**Tujuan:** Pembaruan dokumen menyimpan versi baru tanpa menghilangkan versi lama.

**Cakupan:**
- Aksi **"Perbarui Dokumen"** di halaman dokumen: upload file baru, tanggal terbit/berakhir baru, catatan perubahan (wajib).
- Proses (dalam satu database transaction):
  1. Versi lama diubah `is_current = false` (menjadi arsip).
  2. Versi baru dibuat dengan `version_number` + 1 dan `is_current = true`.
  3. `documents.current_version_id` diperbarui.
- Relation manager **Riwayat Versi**: daftar semua versi (nomor, tanggal, uploader, catatan, masa berlaku), dengan tombol unduh tiap versi.
- Versi lama **tidak bisa diedit atau dihapus** (read-only). Tidak ada aksi hapus versi di UI.
- Badge jelas: "Versi aktif" vs "Arsip".

**Definition of Done:** Setelah update, versi lama tetap bisa diunduh dan berstatus arsip; hanya satu versi aktif per dokumen. Commit: `feat(work-6): versioning control dokumen`.

---

### WORK 7: Status Masa Tenggang dan Dashboard

**Tujuan:** User melihat kondisi masa berlaku dokumen sekilas.

**Cakupan:**
- Status turunan dari versi aktif: **Aktif**, **Segera Berakhir** (masuk rentang pengingat pertama jenis dokumennya), **Kedaluwarsa**, **Tanpa Masa Tenggang**.
- Badge berwarna di tabel dokumen dan filter berdasarkan status.
- Widget dashboard: jumlah dokumen per status, daftar dokumen yang akan berakhir dalam 30 hari.

**Definition of Done:** Status akurat sesuai tanggal berakhir dan konfigurasi jenis dokumen. Commit: `feat(work-7): status masa tenggang dan dashboard`.

---

### WORK 8: Pengingat WhatsApp (WagHub)

**Tujuan:** Sistem otomatis mengirim pengingat WhatsApp sesuai konfigurasi jenis dokumen.

**Cakupan:**
- Service `WaghubService` yang memanggil `POST {WAGHUB_URL}/api/v1/messages` dengan header:
  - `Authorization: Bearer {WAGHUB_TOKEN}`
  - `Accept: application/json`, `Content-Type: application/json`
  - `Idempotency-Key`: unik per pengingat, contoh `lms-reminder-{document_version_id}-{offset_days}`
- Body mengikuti kontrak WagHub: `recipient` (`type: phone`, `value: nomor`), `message` (`type: text`, `text`), `purpose`, `mode`, `route_key`, `expires_at`, `client_reference`.
- Nilai token dan URL **hanya dari `.env`** (`config/services.php`), tidak pernah ditulis di kode.
- Normalisasi nomor telepon ke format yang diterima WagHub.
- Artisan command `lms:send-reminders` dijadwalkan harian (misal 08:00 WIB) lewat Laravel Scheduler:
  1. Ambil versi aktif yang punya `expiry_date`.
  2. Hitung selisih hari ke jatuh tempo; jika sama dengan salah satu angka di `reminder_days` jenis dokumennya, kirim pengingat. Sejak Work 32, jadwal yang terlewat (scheduler mati atau dokumen baru diunggah di tengah rentang) tetap dikirim terlambat satu kali untuk jadwal terdekat yang sudah tercapai, dengan sisa hari aktual.
  3. Penerima: PIC dokumen (fallback: user dengan permission khusus, misal `receive_reminder`).
  4. Catat di `reminder_logs`; unique `(document_version_id, offset_days)` mencegah kirim ganda.
  5. Jika gagal, status `failed` dan bisa dicoba ulang pada run berikutnya (maksimal N kali).
- Templat pesan: nama dokumen, perusahaan, tanggal berakhir, sisa hari, nama PIC.
- Halaman/Resource **Log Pengingat** (read-only) untuk memantau status kirim.
- Pengingat dihentikan otomatis begitu dokumen diperbarui (versi baru aktif menghitung ulang dari awal).

**Definition of Done:**
- Dengan HTTP fake pada tes, pengingat terkirim tepat pada hari yang ditentukan dan tidak terkirim dua kali.
- Percobaan manual ke satu nomor uji berhasil.
- Commit: `feat(work-8): pengingat whatsapp via waghub`.

---

### WORK 9: Pencarian, Audit, dan Finishing

**Tujuan:** Meningkatkan kenyamanan dan jejak audit.

**Cakupan:**
- Global search Filament untuk dokumen (judul, nomor, perusahaan).
- Activity log sederhana: siapa upload, memperbarui, dan mengunduh dokumen.
- Penyempurnaan UI (label bahasa Indonesia, empty state, notifikasi sukses/gagal).
- Dokumentasi singkat `README` (cara install, konfigurasi `.env`, menjalankan scheduler).

**Definition of Done:** Fitur berjalan dan README lengkap. Commit: `feat(work-9): pencarian, audit log, dan finishing`.

---

### WORK 32: Pengingat Catch-up (Jadwal Terlewat)

**Tujuan:** Pengingat tidak hilang bila scheduler mati pada hari jadwal atau dokumen baru diunggah ketika jadwal sudah lewat.

**Cakupan:**
- Untuk setiap dokumen, pilih jadwal terkecil yang masih `>=` sisa hari (jadwal terdekat yang sudah tercapai). Contoh jadwal H-30 dan H-7: sisa 29 hari mengirim jadwal H-30; sisa 5 hari mengirim jadwal H-7.
- `reminder_logs` unik per `(document_version_id, offset_days)`, jadi satu jadwal tidak pernah terkirim dua kali walau command berjalan setiap hari.
- Teks pesan memakai sisa hari aktual, bukan angka jadwal.
- Dokumen yang sudah kedaluwarsa atau belum masuk jadwal pertama tidak dikirimi.
- `lms:send-reminders --dry-run` hanya menghitung jadwal yang belum punya log.

**Definition of Done:** Tes mencakup jadwal terlewat, dokumen baru di dalam rentang, dokumen di luar rentang atau kedaluwarsa, dan tidak ada kirim ganda. Commit: `feat(work-32): pengingat catch-up untuk jadwal terlewat`.

---

### WORK 33: Peringatan Kesehatan Pengingat di Dashboard

**Tujuan:** Admin langsung tahu bila pengingat tidak akan berjalan, tanpa harus memeriksa log satu per satu.

**Cakupan:**
- Widget dashboard "Perlu Perhatian: Pengingat WhatsApp", tampil paling atas dan hanya bila ada masalah.
- Peringatan yang diperiksa (`ReminderHealthChecker`):
  1. Template pengingat belum dibuat, nonaktif, atau jadwalnya kosong (tidak ada pengingat yang akan dikirim).
  2. Dokumen aktif dengan PIC tanpa nomor WhatsApp valid. Level "Perhatian" bila ada penerima cadangan (permission `receive_reminder` dengan nomor valid), level "Penting" bila tidak ada.
  3. Pengingat berstatus `failed` pada Log Pengingat.
- Tautan "Periksa" mengarah ke pengaturan template atau Log Pengingat terfilter status Gagal, hanya bila user punya akses halaman tersebut.
- Widget hanya terlihat oleh user dengan akses lihat Template atau Log Pengingat.

**Definition of Done:** Tes mencakup setup sehat tanpa peringatan, tiap jenis peringatan, pengecualian dokumen kedaluwarsa dan tanpa masa tenggang, serta pembatasan akses widget. Commit: `feat(work-33): peringatan kesehatan pengingat di dashboard`.

---

### WORK 34: Pengingat Setelah Kedaluwarsa

**Tujuan:** Dokumen yang sudah lewat masa berlaku tetap diingatkan sampai diperbarui, bukan dibiarkan diam.

**Cakupan:**
- Pengaturan baru "Setelah kedaluwarsa (opsional)" di jadwal pengingat: daftar hari setelah tanggal berakhir, misalnya 1 dan 7. Kosong berarti tidak ada pengingat kedaluwarsa. Mengikuti status aktif template global.
- Log pengingat memakai `offset_days` negatif untuk pengingat kedaluwarsa (`-1` = H+1), sehingga unik per `(document_version_id, offset_days)` dan tidak terkirim ganda. Kolom diubah menjadi signed integer.
- Catch-up berlaku sama: bila H+1 terlewat, dikirim terlambat satu kali untuk jadwal terdekat yang sudah tercapai.
- Pesan memakai teks khusus kedaluwarsa dengan data `{hari_terlambat}`. Teks bawaan: `DEFAULT_OVERDUE_BODY`; dapat diubah sejak Work 36.
- `expires_at` pesan kedaluwarsa dihitung dari hari pengiriman, bukan tanggal berakhir dokumen.
- Pengingat kedaluwarsa dibatalkan otomatis bila versi baru diunggah atau jadwal dihapus.
- Log Pengingat menampilkan `H-N` untuk sebelum dan `H+N` untuk setelah berakhir.

**Definition of Done:** Tes mencakup pengiriman pada hari yang dikonfigurasi, tanpa kirim ganda, tidak terkirim bila belum dikonfigurasi atau belum tercapai, pembatalan saat jadwal dihapus, validasi input, dan penyimpanan lewat form jadwal. Commit: `feat(work-34): pengingat setelah kedaluwarsa`.

---

### WORK 35: Tombol Perpanjang Dokumen

**Tujuan:** Memperpanjang dokumen cukup dengan satu klik dan unggah file, tanpa mengisi ulang tanggal dan catatan.

**Cakupan:**
- Aksi "Perpanjang" di halaman detail dokumen, hanya untuk jenis dokumen bermasa berlaku yang versi aktifnya punya tanggal berakhir, dan hanya untuk user yang boleh mengubah dokumen.
- Form terisi saran dari `Document::renewalSuggestion()`:
  - Tanggal terbit baru = tanggal berakhir versi aktif, atau hari ini bila sudah lewat.
  - Tanggal berakhir baru = tanggal terbit baru ditambah lama masa versi aktif (dalam bulan bila rapi, selain itu dalam hari), atau setahun bila tanggal terbit lama tidak diketahui.
  - Catatan perubahan: "Perpanjangan masa berlaku dokumen."
- Semua isian dapat diubah sebelum disimpan. Penyimpanan memakai alur versi baru yang sama (`appendVersion`), jadi versi lama tetap menjadi arsip.
- Tombol berwarna peringatan bila dokumen berstatus Segera Berakhir atau Kedaluwarsa.
- Aksi "Unggah Versi Baru" tetap tersedia untuk perubahan lain.

**Definition of Done:** Tes mencakup saran tanggal (berakhir di masa depan, sudah lewat, tanpa tanggal terbit, masa tidak rapi bulan), form terisi dan tersimpan sebagai versi baru, serta visibilitas aksi untuk viewer dan jenis dokumen tanpa masa berlaku. Commit: `feat(work-35): tombol perpanjang dokumen`.

---

### WORK 36: Template Pesan Kedaluwarsa Dapat Diubah

**Tujuan:** Admin dan PIC dapat menyesuaikan isi pesan pengingat kedaluwarsa dengan standar masing-masing, sama seperti template lainnya.

**Cakupan:**
- Kolom `overdue_body` (nullable) pada pengaturan global. Kosong berarti memakai teks bawaan `DEFAULT_OVERDUE_BODY`.
- Panel "Pesan setelah kedaluwarsa" di halaman Pengaturan WhatsApp, lengkap dengan pratinjau, ubah template, kirim pesan uji, dan kembalikan bawaan, setara template pengingat masa berlaku.
- Data otomatis yang tersedia: `{dokumen}`, `{nomor}`, `{perusahaan}`, `{jenis_dokumen}`, `{tanggal_berakhir}`, `{hari_terlambat}`, `{pic}`. `{sisa_hari}` tidak tersedia karena tidak bermakna setelah kedaluwarsa. Variabel tak dikenal ditolak, maksimal 4000 karakter.
- Pengirim pengingat memakai template tersimpan; bila kosong memakai teks bawaan.
- Pesan uji mendukung jenis `overdue` dengan data contoh, dan riwayat pengiriman menamainya "Pesan uji · Setelah kedaluwarsa".
- Menyimpan isi yang sama dengan teks bawaan dicatat sebagai kosong, sehingga perubahan teks bawaan di kode ikut berlaku.

**Definition of Done:** Tes mencakup ubah, pratinjau, dan pulihkan lewat halaman pengaturan, penolakan variabel tak dikenal (termasuk `{sisa_hari}`), pengiriman memakai template kustom dan fallback bawaan, serta pesan uji. Commit: `feat(work-36): template pesan kedaluwarsa dapat diubah`.

---

### WORK 37: Pengingat Pengajuan yang Menggantung

**Tujuan:** Pengajuan tidak diam di tahap pemeriksa atau penyetuju tanpa ada yang tahu. Pihak yang bertugas diingatkan otomatis lewat WhatsApp.

**Cakupan:**
- Pengaturan di halaman Pengaturan WhatsApp, panel "Pengingat pengajuan menggantung": aktif/nonaktif, mulai setelah N hari (bawaan 2), ulangi setiap N hari (bawaan 2), maksimal N kali (bawaan 3). Tanpa pengaturan tersimpan, nilai bawaan berlaku dan pengingat aktif.
- Command `lms:send-request-reminders`, dijadwalkan harian pada jam pengingat (08:00 WIB). Command hanya membuat notifikasi; pengiriman dan percobaan ulang tetap oleh `lms:send-request-notifications` (tiap menit).
- Penerima menurut tahap:
  - Diperiksa: pemeriksa pengajuan (`reviewer_id`), atau PIC bila kosong.
  - Menunggu Persetujuan: penyetuju (`approver_id`), atau semua user dengan permission `Approve:DocumentRequest` bila belum ditetapkan.
- Lama menunggu dihitung dari peristiwa terakhir di riwayat. Pengingat ke-k jatuh tempo pada `mulai + (k-1) x interval` hari. Bila beberapa run terlewat, hanya pengingat terbaru yang dikirim.
- Idempotensi lewat `event_key` `lms-request-{id}-{jumlah riwayat}-stale{k}-u{user}`. Karena memakai awalan yang sama dengan notifikasi status, pengingat tertunda otomatis dibatalkan saat pengajuan berpindah tahap.
- Isi pesan dapat diubah (ubah, pratinjau, pesan uji, kembalikan bawaan). Data otomatis: nomor pengajuan, judul, pengaju, perusahaan, jenis dokumen, tahap, hari menunggu, tautan. Kolom kosong memakai `DEFAULT_REQUEST_REMINDER_BODY`.
- Penerima tanpa nomor WhatsApp valid tetap dicatat sebagai notifikasi gagal di riwayat pengiriman.
- Kartu dashboard jumlah pengajuan menunggu dikerjakan terpisah di Work 46.

**Definition of Done:** Tes mencakup ambang pertama, ulangan dan batas maksimal tanpa duplikat, catch-up, penerima tiap tahap dan fallback, status yang diabaikan, pengaturan nonaktif dan kustom, template kustom dan validasi, pengiriman dan pembatalan saat status berubah, nomor kosong, serta form pengaturan. Commit: `feat(work-37): pengingat pengajuan yang menggantung`.

---

### WORK 38: Timeline Status di Halaman Publik

**Tujuan:** Pengaju memahami posisi pengajuannya dan apa yang sudah terjadi, tidak hanya melihat status terakhir.

**Cakupan:**
- Halaman `/pengajuan/{token}` menampilkan indikator tahap: Diperiksa, Menunggu Persetujuan, Menunggu Tanda Tangan, Selesai. Tahap berjalan disorot (`aria-current`), tahap yang dilewati ditandai selesai.
- Status Perlu Revisi kembali ke tahap pertama. Status Ditolak menandai tahap tempat penolakan terjadi (Diperiksa atau Menunggu Persetujuan) sebagai dihentikan.
- Riwayat berurutan, terbaru di atas: nama tahap, peran pelaku, waktu (WIB), dan catatan reviewer bila ada. Pengiriman ulang setelah revisi tampil sebagai "Revisi dikirim". Draf internal tidak ditampilkan.
- Halaman publik hanya menyebut peran (Pengaju, Pemeriksa, Penyetuju), tidak memuat nama staf dari riwayat. Keputusan setelah tahap persetujuan dicatat sebagai Penyetuju, selain itu Pemeriksa. Nama PIC pada ringkasan baru disembunyikan di Work 41.
- Data diambil dari kolom `history` yang sudah ada, tanpa migrasi. Riwayat lama tanpa waktu atau catatan tetap tampil.
- Estimasi selesai tidak ditampilkan karena form publik tidak mengisi tanggal target.

**Definition of Done:** Tes mencakup status tiap tahap, revisi, penolakan oleh pemeriksa dan penyetuju, urutan dan zona waktu riwayat, riwayat tanpa waktu atau catatan, nama staf tidak muncul di halaman, dan form revisi tetap berfungsi. Commit: `feat(work-38): timeline status pengajuan di halaman publik`.

---

### WORK 39: Kirim Ulang Notifikasi yang Gagal

**Tujuan:** Admin dapat memulihkan pesan WhatsApp yang gagal dari halaman Riwayat pengiriman, tanpa mengubah data langsung di database.

**Cakupan:**
- Tombol "Kirim ulang" pada baris berstatus Gagal, untuk notifikasi pengajuan dan pengingat masa berlaku dokumen. Pesan uji tidak dapat dikirim ulang. Ada konfirmasi sebelum mengirim.
- Hanya user yang boleh mengubah pengaturan WhatsApp (`Update:ReminderTemplate`) yang melihat dan dapat memakai tombol. Notifikasi pengajuan harus berada dalam cakupan pengajuan yang boleh dilihat user tersebut, selain itu ditolak.
- Percobaan direset dan kunci idempotensi tetap sama, sehingga pesan yang ternyata sudah sampai tidak terkirim ganda. Pesan dan payload yang sama dipakai ulang.
- Nomor penerima dihitung ulang dari data saat ini (nomor pengaju, PIC, atau pemeriksa/penyetuju), sehingga nomor yang sudah diperbaiki ikut terpakai.
- Pesan yang sudah tidak berlaku (versi dokumen diganti, jadwal dihapus, atau pengajuan sudah berpindah tahap) dibatalkan, bukan dikirim.
- Hasil ditampilkan sebagai notifikasi: diterima WagHub, gagal lagi, atau dibatalkan.
- Audit: kolom `resent_by` dan `resent_at` pada `reminder_logs` dan `document_request_notifications`; riwayat menampilkan "Dikirim ulang oleh {nama}".
- Pesan error yang lebih rinci per penyebab tidak dikerjakan karena rinciannya sudah tersedia di WagHub.

**Definition of Done:** Tes mencakup kirim ulang berhasil dengan kunci dan payload sama, percobaan yang habis, nomor yang diperbaiki, hanya status Gagal, pembatalan untuk versi atau tahap yang sudah lewat, gagal lagi, tampilan tombol menurut status dan izin, audit, penolakan tanpa izin, di luar cakupan pengajuan, dan sumber tidak dikenal. Commit: `feat(work-39): kirim ulang notifikasi yang gagal`.

---

### WORK 47: Uji Alur Lengkap dari Ujung ke Ujung

**Tujuan:** Memastikan seluruh perjalanan pengajuan sampai dokumen berjalan utuh dan saling nyambung, bukan hanya tiap bagian sendiri-sendiri.

**Cakupan:** `FullLifecycleFlowTest`, memakai akun dummy per role (`DummyAccountSeeder`), WagHub dipalsukan, dan waktu dimajukan sesuai alur.
- Alur utama: pengaju publik mengisi form dan mengunggah lampiran → pengaju dan pemeriksa menerima WhatsApp → halaman status menampilkan tahap dan riwayat tanpa nama staf → 2 hari tanpa tindakan memicu pengingat ke pemeriksa (tidak ganda bila command diulang) → pemeriksa meminta revisi dan pengaju menerima catatannya → pengaju mengirim revisi lewat tautan → pemeriksa meneruskan, penyetuju menyetujui → dokumen final diarsipkan (dokumen, versi 1, berkas di S3, PIC, pihak lawan) → muncul di daftar dan pencarian tim legal → pengingat masa berlaku: jadwal terlewat dikirim terlambat sekali, lalu tepat waktu di H-7 → tim legal memperpanjang (form terisi otomatis, versi 2, versi lama menjadi arsip) → pengingat versi baru dihitung ulang dari awal → ekspor Excel memuat versi aktif terbaru.
- Pemulihan: pesan gagal dikirim ulang dari halaman riwayat dengan kunci idempotensi yang sama dan tanpa pengiriman ganda.
- Penolakan: pengajuan ditolak, pengaju menerima alasannya, halaman status menandai tahap dihentikan, dan pengingat tidak lagi dikirim.

**Definition of Done:** Ketiga skenario lulus. Commit: `test(work-47): uji alur lengkap pengajuan sampai perpanjangan`.

---

### WORK 46: Pengajuan yang Menunggu Terlalu Lama di Dashboard dan Daftar

**Tujuan:** Staf langsung melihat pengajuan yang menggantung dan dapat menyaringnya, tanpa menunggu pengingat WhatsApp.

**Cakupan:**
- Kolom `status_changed_at` pada `document_requests`, diisi setiap pengajuan berpindah tahap (bersama riwayat) dan diisi ulang dari riwayat terakhir untuk data lama oleh migrasi. `waitingSince()` kini memakai kolom ini, lalu riwayat, lalu `updated_at`.
- Scope `DocumentRequest::waitingLongerThan($hari)`: hanya status Diperiksa dan Menunggu Persetujuan, batas inklusif. Data tanpa `status_changed_at` memakai `updated_at`.
- Filter "Menunggu lebih dari N hari" (toggle) di daftar pengajuan. N mengikuti pengaturan "Mulai setelah" pada pengingat pengajuan (bawaan 2), dan dapat digabung dengan filter status.
- Widget dashboard "Pengajuan Menunggu Tindakan": dua kartu (Menunggu Pemeriksaan dan Menunggu Persetujuan) berisi jumlah pengajuan yang boleh dilihat user, dengan keterangan berapa yang menunggu lebih dari N hari. Kartu menaut ke daftar terfilter status, dan filter menunggu lama bila ada yang menunggu. Widget hanya tampil bagi user yang boleh melihat pengajuan dan hanya bila ada pengajuan yang menunggu.
- Pengingat pengajuan (Work 37) memakai daftar status menunggu yang sama.

**Definition of Done:** Tes mencakup batas inklusif dan status yang diabaikan, cadangan ke `updated_at`, prioritas `waitingSince`, transisi yang mereset waktu tunggu, filter tabel beserta ambang yang mengikuti pengaturan, jumlah dan tautan kartu, pembatasan sesuai hak lihat, serta visibilitas widget. Commit: `feat(work-46): pengajuan menunggu terlalu lama di dashboard dan daftar`.

---

### WORK 45: Ringkasan Dokumen Berakhir per Badan Usaha

**Tujuan:** Dari dashboard terlihat badan usaha mana yang paling banyak punya dokumen akan berakhir, tanpa membuka daftar satu per satu.

**Cakupan:**
- Widget dashboard "Dokumen Berakhir per Badan Usaha" (lebar penuh, di bawah daftar 30 hari) untuk user yang boleh melihat dokumen.
- Kolom per badan usaha: 0-30 hari, 31-60 hari, 61-90 hari (dihitung dari hari ini, kedua ujung termasuk), dan Kedaluwarsa. Dihitung dari versi aktif dokumen bermasa berlaku yang belum dihapus; dokumen tanpa masa tenggang dan versi lama tidak dihitung.
- Badan usaha tanpa dokumen dalam 90 hari maupun kedaluwarsa tidak ditampilkan. Urutan: paling banyak berakhir dalam 30 hari, lalu paling banyak kedaluwarsa, lalu nama.
- Setiap angka di atas nol menjadi tautan ke daftar dokumen yang sudah terfilter badan usaha dan rentang tanggalnya (atau status Kedaluwarsa), memakai filter dari Work 43. Angka nol tidak berupa tautan.
- Scope `Document::expiringBetween($dariHari, $sampaiHari)` ditambahkan; `expiringWithin` tetap berperilaku sama dengan memakainya.

**Definition of Done:** Tes mencakup batas tiap rentang, dokumen yang tidak dihitung (tanpa masa tenggang, dihapus, versi lama), badan usaha yang disembunyikan dan urutan, tautan yang cocok dengan hasil daftar terfilter, akses dan kemunculan di dashboard, keadaan kosong, serta scope 30 hari yang tidak berubah. Commit: `feat(work-45): ringkasan dokumen berakhir per badan usaha`.

---

### WORK 44: Ekspor Excel

**Tujuan:** Daftar dokumen dapat dibawa ke Excel untuk dilaporkan atau diolah.

**Cakupan:**
- Tombol "Ekspor Excel" di halaman daftar dokumen. Isinya mengikuti pencarian, filter, dan urutan yang sedang aktif, dan tetap dibatasi hak akses user (hanya yang boleh melihat daftar dokumen).
- Tombol yang sama di widget dashboard "Berakhir dalam 30 Hari", berisi dokumen yang tampil di widget tersebut.
- Berkas `.xlsx` (satu sheet "Dokumen", baris judul tebal) dibuat secara streaming per 500 baris, sehingga aman untuk data besar. Memakai OpenSpout yang sudah terpasang bersama Filament, tanpa paket baru.
- Kolom: Judul, Nomor Dokumen, Badan Usaha, Jenis Dokumen, Pihak Lawan, PIC, Tanggal Terbit, Tanggal Berakhir (tanggal sungguhan, format dd/mm/yyyy), Status, Sisa Hari (negatif bila sudah lewat, kosong bila tanpa masa tenggang), Versi Aktif, Nama File, Dibuat.
- Nama berkas memuat waktu unduh (WIB), unduhan tidak disimpan di cache (`no-store, private`). Dokumen tanpa versi tetap diekspor dengan kolom versi dikosongkan.
- Penunjang pengujian: `phpunit.xml` menonaktifkan Debugbar (`DEBUGBAR_ENABLED=false`, yang juga menghentikan penumpukan berkas di `storage/debugbar`) dan menaikkan `memory_limit` tes menjadi 512M, karena suite yang makin besar melewati batas bawaan 128 MB.

**Definition of Done:** Tes mencakup isi sheet dan kolom hitungan, respons unduhan, dokumen tanpa versi, lebih dari satu chunk, ekspor mengikuti pencarian dan filter, ekspor widget 30 hari, dan pembatasan akses. Commit: `feat(work-44): ekspor excel daftar dokumen`.

---

### WORK 43: Filter dan Pencarian Lanjutan Dokumen

**Tujuan:** Dokumen dapat dicari berdasarkan pihak lawan dan rentang tanggal berakhir, selain filter badan usaha, jenis, status, PIC, dan format yang sudah ada.

**Cakupan:**
- Filter "Pihak Lawan": cocok sebagian nama, tidak peduli huruf besar atau kecil, spasi di tepi diabaikan. Karakter `%` dan `_` diperlakukan sebagai huruf biasa.
- Filter "Tanggal Berakhir" (dari dan sampai, keduanya inklusif dan boleh salah satu): memakai tanggal berakhir versi aktif. Dokumen tanpa masa berlaku tidak muncul saat rentang dipakai. Rentang terbalik tidak menghasilkan dokumen dan menampilkan "Dokumen tidak ditemukan".
- Pencarian tabel kini juga mencari pihak lawan, dan ada kolom "Pihak Lawan" yang dapat ditampilkan.
- Indikator filter aktif yang terbaca ("Pihak lawan: ...", "Berakhir dari ..."). Berlaku sama di tampilan tabel dan grid, dan dapat digabung dengan filter lain.

**Definition of Done:** Tes mencakup pencocokan sebagian tanpa pembeda huruf, wildcard literal, rentang inklusif dengan versi aktif, rentang terbalik, pencarian pihak lawan digabung filter, indikator, dan tampilan grid. Commit: `feat(work-43): filter pihak lawan dan rentang tanggal berakhir`.

---

### WORK 42: Perbaikan Tes yang Usang

**Tujuan:** Suite tes hijau, sehingga kegagalan baru langsung terlihat. Sebelumnya 17 tes gagal sejak jadwal pengingat dipindah ke template global (Work 11) dan sejak permission pengajuan ditambahkan ke Shield (Work 12).

**Cakupan:** hanya tes yang diperbarui; tidak ada perubahan perilaku aplikasi.
- `AdminMonitoringAccessTest`: seeder permission kustom diuji terhadap seluruh daftar `filament-shield.custom_permissions` dan dijalankan dua kali (idempoten), bukan angka tetap 3.
- `DocumentStatusDashboardTest` dan `DummyAcceptanceTest`: status Segera Berakhir kini menyiapkan jadwal pada template global, bukan `reminder_days` per jenis dokumen.
- `DocumentTypeResourceTest`: tiga tes yang mengisi `reminder_days` lewat form diganti. Form jenis dokumen tidak punya kolom itu lagi, sehingga yang diuji adalah: kolom tidak ada, jadwal efektif mengikuti template global (hanya untuk jenis bermasa berlaku, mengikuti status aktif), nilai lama pada model tidak diubah saat edit, dan validasi data lama pada model tetap berlaku.
- `ReminderScheduleValidationTest` (baru): aturan input jadwal global (negatif, desimal, teks, terlalu besar, duplikat, kosong ditolak; 0 diterima dan diurutkan menurun; mode interval dan inherit), menggantikan validasi yang dulu diuji lewat form jenis dokumen.

**Definition of Done:** Seluruh suite hijau. Commit: `test(work-42): perbarui tes yang usang terhadap jadwal pengingat global`.

---

### WORK 41: Sembunyikan Nama PIC di Halaman Publik

**Tujuan:** Pengaju tidak perlu mengetahui siapa staf internal yang menangani pengajuannya.

**Cakupan:**
- Baris "PIC" dihapus dari ringkasan halaman status publik `/pengajuan/{token}`. Halaman publik tidak lagi memuat nama staf mana pun (PIC maupun pelaku di riwayat).
- Notifikasi WhatsApp ke pengaju tidak berubah; variabel `{pic}` pada template tetap tersedia bagi admin yang ingin memakainya.

**Definition of Done:** Tes memastikan nama PIC dan nama staf pada riwayat tidak tampil di halaman status. Commit: `feat(work-41): sembunyikan nama PIC di halaman status publik`.

---

### WORK 40: Akun Dummy per Role untuk Pengujian

**Tujuan:** Pengujian manual (oleh developer maupun agen) dapat memakai akun dari setiap role tanpa membuat akun satu per satu.

**Cakupan:**
- Seeder `DummyAccountSeeder`, dijalankan manual: `php artisan db:seed --class=DummyAccountSeeder`. Tidak dipanggil oleh `DatabaseSeeder` dan menolak berjalan di production.
- Satu akun per role yang ada di database (termasuk role buatan sendiri): email `{nama-role}@dummy.test` (garis bawah menjadi tanda hubung), kata sandi dummy tetap yang tertulis di seeder. Aman dijalankan ulang; kata sandi dikembalikan ke nilai dummy.
- Role dasar berawalan `dummy_` agar tidak menimpa role buatan sendiri, dengan izin untuk menguji alur dokumen dan pengajuan: `dummy_legal` (kelola dokumen, lihat master, log, template, pemeriksa pengajuan, penerima pengingat), `dummy_viewer` (lihat dokumen), `dummy_pemeriksa` (periksa pengajuan), `dummy_penyetuju` (setujui pengajuan dan arsipkan dokumen final), `dummy_pengaju` (buat dan ubah pengajuan). Izin role buatan sendiri tidak diubah.
- Nomor WhatsApp akun dummy dikosongkan agar pesan tidak sampai ke nomor sungguhan. Variabel `.env` `LMS_DUMMY_PHONE` mengisi nomor milik sendiri untuk menguji pengiriman.

**Definition of Done:** Tes mencakup akun per role (termasuk role kustom) dapat membuka panel, seeder idempoten, izin tiap role dasar, role kustom tidak ditimpa, nama akun, dan penolakan di production. Commit: `feat(work-40): seeder akun dummy per role`.

---

## 7. Persyaratan Non-Fungsional

- **Keamanan:** file private di S3, akses lewat signed URL berumur pendek; semua resource dilindungi Policy/Shield; secret hanya di `.env`.
- **Integritas:** versi dokumen immutable; penghapusan dokumen memakai soft delete.
- **Keandalan:** pengingat idempoten (tidak terkirim ganda); kegagalan WagHub tidak menghentikan proses lain.
- **Testing:** Pest/PHPUnit untuk logika versioning, perhitungan status, dan scheduler pengingat (HTTP di-fake).
- **Catatan SQLite:** cocok untuk beban kecil-menengah; hindari operasi tulis bersamaan yang berat, dan siapkan jalur migrasi ke MySQL/PostgreSQL bila pengguna bertambah.

## 8. Asumsi dan Pertanyaan Terbuka

1. Nilai `purpose` untuk pesan pengingat di WagHub belum diketahui (contoh dokumentasi memakai `otp`). **Perlu dikonfirmasi** ke dokumentasi WagHub sebelum Work 8; sementara dipakai nilai yang valid menurut dokumentasi mereka.
2. Nilai `mode` (`sync`/`async`) dan `route_key` memakai `default`, dapat diubah lewat `.env`.
3. Batas ukuran dan tipe file (PDF/DOC/DOCX/JPG/PNG, 20 MB) adalah asumsi awal.
4. Jam pengiriman pengingat harian diasumsikan 08:00 WIB.
5. Satu dokumen memiliki satu PIC. Jika butuh banyak penerima, dikembangkan di iterasi berikutnya.

## 9. Ringkasan Urutan Work

| # | Work | Commit ke |
|---|---|---|
| 1 | Setup proyek | `ahtar-dev` |
| 2 | Auth dan Shield | `ahtar-dev` |
| 3 | Master perusahaan | `ahtar-dev` |
| 4 | Master jenis dokumen | `ahtar-dev` |
| 5 | Pengarsipan dokumen | `ahtar-dev` |
| 6 | Versioning control | `ahtar-dev` |
| 7 | Status masa tenggang dan dashboard | `ahtar-dev` |
| 8 | Pengingat WhatsApp | `ahtar-dev` |
| 9 | Pencarian, audit, finishing | `ahtar-dev` |
| 32 | Pengingat catch-up | `ahtar-dev` |
| 33 | Peringatan kesehatan pengingat di dashboard | `ahtar-dev` |
| 34 | Pengingat setelah kedaluwarsa | `ahtar-dev` |
| 35 | Tombol perpanjang dokumen | `ahtar-dev` |
| 36 | Template pesan kedaluwarsa dapat diubah | `ahtar-dev` |
| 37 | Pengingat pengajuan yang menggantung | `ahtar-dev` |
| 38 | Timeline status pengajuan di halaman publik | `ahtar-dev` |
| 39 | Kirim ulang notifikasi yang gagal | `ahtar-dev` |
| 40 | Seeder akun dummy per role | `ahtar-dev` |
| 41 | Sembunyikan nama PIC di halaman publik | `ahtar-dev` |
| 42 | Perbaikan tes yang usang | `ahtar-dev` |
| 43 | Filter pihak lawan dan rentang tanggal berakhir | `ahtar-dev` |
| 44 | Ekspor Excel daftar dokumen | `ahtar-dev` |
| 45 | Ringkasan dokumen berakhir per badan usaha | `ahtar-dev` |
| 46 | Pengajuan menunggu terlalu lama di dashboard dan daftar | `ahtar-dev` |
| 47 | Uji alur lengkap dari ujung ke ujung | `ahtar-dev` |

> **Tidak ada commit ke `staging` atau `main` dalam proyek ini.**
