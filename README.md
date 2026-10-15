# LMS — Arsip Dokumen Legal

Aplikasi Laravel 13, PHP 8.4, Filament 5, Livewire 4, dan SQLite untuk mengarsipkan dokumen perusahaan. File disimpan secara privat di S3-compatible storage.

## Fitur

- Master perusahaan dan jenis dokumen dengan konfigurasi hari pengingat.
- Tampilan dokumen tabel/grid, pencarian dan filter perusahaan, jenis, status, PIC, serta format file.
- Pencarian global berdasarkan judul, nomor dokumen, dan perusahaan.
- Versi file immutable; unggah versi baru mempertahankan file lama. Penghapusan dokumen memakai soft delete.
- Status masa berlaku, dashboard, dan pengingat WhatsApp WagHub.
- Riwayat aktivitas pada detail dokumen: unggah, versi baru, perubahan informasi, permintaan unduhan, dan pratinjau.
- Hak akses per resource melalui Filament Shield.

## Persiapan dan instalasi

Diperlukan PHP 8.4 dengan SQLite, Composer, Node.js/npm, serta bucket S3 privat. Redis diperlukan jika menjalankan Horizon; pengingat harian saat ini dikirim langsung oleh command, tanpa antrean.

```bash
composer install
npm install
```

Salin `.env.example` ke `.env` (PowerShell: `Copy-Item .env.example .env`). Buat file kosong `database/database.sqlite` jika belum tersedia. Jangan menimpa `.env` atau database instalasi yang sudah berjalan.

```bash
php artisan key:generate --no-interaction
php artisan migrate --no-interaction
npm run build
php artisan filament:assets --no-interaction
```

Isi `.env` untuk aplikasi dan storage:

```dotenv
APP_NAME="LMS Legal"
APP_URL=http://127.0.0.1:8000
APP_LOCALE=id
DB_CONNECTION=sqlite
FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=lms
AWS_ENDPOINT=https://storage.example.com
AWS_USE_PATH_STYLE_ENDPOINT=true
AWS_VERIFY_SSL=true
LMS_MAX_UPLOAD_SIZE_KB=20480
```

Kredensial hanya di `.env`. File dokumen disimpan ke disk yang dipilih lewat `FILESYSTEM_DISK`: `s3` (bucket S3, unduh lewat signed URL), `local` (`storage/app/private`, dikirim aplikasi setelah cek izin), atau `public` (`storage/app/public`, **dapat dibuka siapa saja tanpa login** lewat `/storage/...` setelah `php artisan storage:link`, jangan dipakai untuk dokumen legal). Setiap versi mencatat disk penyimpanannya, jadi mengganti `FILESYSTEM_DISK` hanya memengaruhi upload berikutnya dan file lama tetap diunduh dari disk asalnya. Di setiap disk, file disusun satu folder per jenis dokumen dengan nama `{badan-usaha}_{jenis-dokumen}_{nomor-dokumen}_{judul}_dok{id}-v{versi}_{yyyymmdd-hhmmss}.{ext}` (waktu upload WIB; bagian kosong dilewati, tiap bagian maksimal 50 karakter), misalnya `perjanjian-kerja-sama/pt-abc_perjanjian-kerja-sama_001-pks-2026_sewa-gudang_dok12-v2_20261012-091500.pdf`. Nama ini juga yang tampil di web dan dipakai saat file diunduh; nama asli dari komputer pengunggah tetap tersimpan sebagai "Nama File Asli". Lampiran pengajuan disimpan di disk `local` pada `lms/requests/{jenis-dokumen}/{badan-usaha}_{jenis-dokumen}_{nama-lampiran}_{yyyymmdd-hhmmss}.{ext}`, dengan akhiran `-2`, `-3` bila nama sudah dipakai. File dokumen dengan struktur atau nama lama dipindahkan dengan `php artisan lms:reorganize-files` (coba dulu dengan `--dry-run`). Bucket harus menolak akses publik. Signed URL berlaku lima menit. Batas unggah default 20 MB; tipe yang diterima PDF, DOC, DOCX, JPG, dan PNG. Sesuaikan pula batas upload PHP/server.

## Akun dan permission

Buat permission tanpa menambahkan data contoh:

```bash
php artisan db:seed --class=CompanySeeder --no-interaction
php artisan db:seed --class=DocumentTypeSeeder --no-interaction
php artisan db:seed --class=DocumentSeeder --no-interaction
php artisan db:seed --class=MonitoringPermissionSeeder --no-interaction
php artisan db:seed --class=ReminderLogSeeder --no-interaction
php artisan shield:generate --resource=RoleResource --panel=admin --option=permissions --no-interaction
php artisan make:filament-user --panel=admin
```

Perintah terakhir meminta nama, email, dan password secara interaktif. Setelah mengetahui ID pengguna, jalankan `php artisan shield:super-admin --user=ID --panel=admin --no-interaction` untuk menyinkronkan role admin dengan permission yang tersedia. Di instalasi yang sudah berjalan, atur permission baru melalui menu Hak Akses sesuai kebutuhan.

`ViewAny:Document` mengizinkan daftar/pencarian; `View:Document` mengizinkan detail, riwayat, pratinjau, dan unduhan. `Create:Document`, `Update:Document`, dan `Delete:Document` mengatur perubahan. Log Pengingat hanya memakai `ViewAny:ReminderLog` dan `View:ReminderLog`. `receive_reminder` menentukan penerima cadangan jika PIC tidak memiliki nomor WhatsApp valid. Isi nomor melalui menu profil. Horizon dan Log Laravel memiliki permission tersendiri.

## Menjalankan aplikasi

```bash
php artisan serve --host=127.0.0.1 --port=8000
```

Buka `http://127.0.0.1:8000/admin`. Untuk pengembangan frontend, jalankan `npm run dev` pada terminal terpisah. Setelah mengubah CSS dokumen, jalankan `php artisan filament:assets --no-interaction`.

## WhatsApp dan scheduler

```dotenv
WAGHUB_URL=https://waghub.mekayastudio.com
WAGHUB_TOKEN=
WAGHUB_PURPOSE=otp
WAGHUB_OTP_PURPOSE=otp
WAGHUB_MODE=sync
WAGHUB_ROUTE_KEY=default
LMS_REMINDER_MAX_ATTEMPTS=3
```

`otp` adalah nilai dari contoh API yang telah diterima WagHub pada uji integrasi; konfirmasikan purpose yang sesuai untuk pesan pengingat kepada pengelola WagHub sebelum penggunaan operasional. Token tidak boleh masuk kode, README, log, atau Git.

### Login dengan kode WhatsApp

Selain email dan password, user bisa masuk lewat `/admin/login/whatsapp` (tombol "Masuk dengan kode WhatsApp" di halaman login): isi nomor WhatsApp di profil, lalu masukkan kode 6 digit yang dikirim lewat WagHub. Kode berlaku 5 menit, hangus setelah 5 kali salah, kirim ulang paling cepat 60 detik, maksimal 5 kode per jam per nomor dan 10 permintaan per 10 menit per IP. Hanya hash kode yang disimpan di tabel `login_codes`; record lebih dari 7 hari dihapus oleh `model:prune` harian. Kode hanya dikirim bila nomor milik tepat satu user yang berhak membuka panel, dan tampilan layar sama untuk nomor yang tidak terdaftar. Karena itu nomor WhatsApp harus unik per user (divalidasi di profil dan form User). Pesan memakai `WAGHUB_OTP_PURPOSE`; bila kosong, memakai `WAGHUB_PURPOSE`.

Scheduler mengirim pukul 08.00 Asia/Jakarta. Pastikan scheduler Laravel dijalankan oleh server:

```bash
php artisan schedule:work
```

Pada produksi, jalankan `php artisan schedule:run` setiap menit melalui cron atau Task Scheduler Windows dengan direktori kerja aplikasi dan PHP yang benar. `php artisan schedule:list` menampilkan jadwal; waktu pada daftar dapat ditampilkan dalam zona aplikasi (UTC).

```bash
php artisan lms:send-reminders --dry-run --no-interaction
```

Dry run memeriksa jumlah dokumen yang jatuh pada jadwal tanpa membuat log atau mengirim pesan. `php artisan lms:send-reminders --no-interaction` benar-benar mengirim semua pengingat yang memenuhi syarat dan retry yang tersedia; gunakan hanya setelah penerima dan konfigurasi siap.

PIC menjadi penerima utama. Jika nomornya tidak valid, dipilih pengguna berizin `receive_reminder` dengan nomor valid, berdasarkan ID terkecil. Nomor `08...` dan `+62...` dinormalisasi menjadi `62...`. Setiap versi dan offset memiliki satu log serta Idempotency-Key tetap. Retry dilakukan pada hari berikutnya hingga batas percobaan; payload dan kunci tetap sama. Pengingat versi lama dibatalkan setelah pembaruan. Status `accepted` berarti permintaan diterima WagHub, bukan bukti pesan telah diterima di perangkat.

Contoh isi pesan:

```text
Pengingat masa berlaku dokumen
Dokumen: Perjanjian Kerja Sama
Perusahaan: PT Contoh
Berakhir: 2026-11-05
Sisa waktu: 30 hari
PIC: Budi
```

## Riwayat dan batas pencatatan

Riwayat aktivitas tersedia pada tab Riwayat Aktivitas di detail dokumen, mengikuti izin melihat dokumen. Pencatatan unggah/versi dan perubahan informasi melalui UI bersifat transaksional. Kegagalan penyimpanan tidak menghasilkan aktivitas sukses. Permintaan file yang ditolak atau file yang tidak ditemukan tidak dicatat sebagai unduhan.

`Unduhan diminta` mencatat bahwa pengguna telah memperoleh redirect ke signed URL. Transfer selesai di S3 tidak dapat dipastikan oleh aplikasi. Aktivitas sebelum fitur audit diaktifkan tidak direkonstruksi. Perubahan melalui SQL atau skrip di luar alur aplikasi tidak otomatis dicatat. Riwayat tidak memiliki aksi edit/hapus di UI dan model menolak mutasi melalui Eloquent.

## Pengujian dan pemeliharaan

```bash
php artisan test --compact --exclude-group=s3-live
php vendor/bin/pint --dirty --format agent
```

Tes memakai database terpisah dan HTTP/storage fake. Uji S3 nyata bersifat opt-in dengan `LMS_TEST_REAL_S3=1` dan grup `s3-live`; uji ini menulis lalu membersihkan file uji pada bucket terkonfigurasi. Tidak ada pengiriman WhatsApp nyata pada suite otomatis.

Cadangkan database SQLite dan bucket bersama-sama; file histori versi harus dipertahankan. Untuk produksi gunakan `APP_ENV=production`, `APP_DEBUG=false`, HTTPS, sertifikat storage valid, lalu `php artisan config:cache`. Setelah mengganti `.env` pada development, jalankan `php artisan config:clear`. Jika tampilan asset belum diperbarui, bangun kembali Vite dan asset Filament.

Pengembangan proyek dilakukan pada branch lokal `ahtar-dev`; push ke remote hanya jika diminta.

## UAT dummy dan pemeriksaan produksi

UAT memakai database SQLite terisolasi, file dummy melalui storage fake, serta HTTP fake. Akun editor, viewer, dan pengguna tanpa izin hanya hidup di database tes. Nomor telepon dummy tidak dihubungi dan data aplikasi lokal tidak ditambahkan atau diganti.

```bash
php artisan test --compact tests/Feature/DummyAcceptanceTest.php tests/Feature/DummyBackupRestoreTest.php tests/Feature/ProductionReadinessTest.php
php artisan lms:check-production --no-interaction
```

Pemeriksaan produksi bersifat read-only dan tidak menampilkan secret. Exit code nonzero menandakan konfigurasi perlu diatur. Pada development, kegagalan pemeriksaan `APP_ENV`, debug, URL HTTPS, secure cookie, atau config cache adalah wajar. Simulasi konfigurasi produksi yang aman dan penolakan konfigurasi tidak aman dicakup tes.

Di server produksi, gunakan pengaturan berikut dengan domain dan kredensial server yang benar:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://lms.example.com
APP_LOCALE=id
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_DRIVER=database
CACHE_STORE=database
AWS_VERIFY_SSL=true
DEBUGBAR_ENABLED=false
```

Jalankan migrasi dengan `--force --no-interaction`, `npm ci` (lockfile telah tersedia), `npm run build`, `php artisan filament:assets --no-interaction`, dan `php artisan config:cache`. Jalankan pemeriksaan produksi lagi. Pembatasan akses resource tetap berlaku di produksi; tidak ada bypass otomatis untuk role tanpa permission. Status masa berlaku, filter, dashboard, dan pengingat menggunakan hari kalender Asia/Jakarta, sementara timestamp database mengikuti zona aplikasi.

Tes scheduler mengevaluasi bahwa command jatuh pada 08.00 WIB, bukan 07.59, dan pengiriman command memakai HTTP fake. Aktivasi cron/Task Scheduler, koneksi bucket privat, sertifikat/domain, serta penerimaan WhatsApp pada perangkat harus diverifikasi pada server tujuan. UAT dummy tidak menyatakan layanan produksi tersebut telah diuji nyata.

### Prosedur backup dan restore

Rehearsal otomatis memulihkan snapshot database dummy, dua versi file, audit, dan log pengingat. Pemeriksaan mencakup referensi versi aktif, `integrity_check`, `foreign_key_check`, serta kecocokan SHA-256 setiap file. Storage S3 disimulasikan; tes tidak mengakses bucket nyata.

Untuk backup operasional, hentikan sementara penulisan aplikasi, scheduler, dan worker agar database serta file bucket memiliki titik pemulihan yang sama. Buat snapshot SQLite di luar transaksi dengan target baru atau kosong:

```sql
VACUUM INTO '/direktori-backup/lms-snapshot.sqlite';
```

`VACUUM INTO` menghasilkan snapshot konsisten tanpa mengganti database sumber; target tidak boleh berisi database lama. Lihat [dokumentasi SQLite](https://www.sqlite.org/lang_vacuum.html). Salin seluruh objek di bucket dokumen, termasuk versi arsip, dengan path utuh. Buat manifest path, ukuran, dan SHA-256; simpan bersama snapshot. Kredensial dan APP_KEY dicadangkan secara terpisah di penyimpanan terbatas, bukan di Git. Tetapkan jadwal, retensi, kapasitas, dan lokasi backup terpisah sesuai kebutuhan operasional.

Untuk restore, gunakan lingkungan terisolasi terlebih dahulu. Pulihkan snapshot database dan file ke path asal dari backup yang sama, kemudian jalankan:

```sql
PRAGMA integrity_check;
PRAGMA foreign_key_check;
```

Hasil integrity harus `ok`, dan foreign key check harus kosong. Cocokkan jumlah versi, referensi versi aktif, dan hash setiap file dengan manifest. Uji login role, detail, serta unduhan versi aktif dan arsip. Aktifkan penulisan dan scheduler setelah validasi; jangan menjalankan scheduler pada salinan UAT dengan token WhatsApp produksi.
