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

> **Tidak ada commit ke `staging` atau `main` dalam proyek ini.**
