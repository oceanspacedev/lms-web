# Alur Draft, Tanda Tangan, dan Dokumen Fisik — Rencana Implementasi

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Mengganti tahap persetujuan pengajuan dengan alur draft → scan bertanda tangan → penyerahan dokumen fisik, lengkap dengan batas waktu 7 hari, alasan keterlambatan, pengingat 7/14/28 hari, pembatalan, dan pantauan untuk legal.

**Architecture:** Status `review`/`approved` diganti `signing` (Menunggu Tanda Tangan), `handover` (Menunggu Dokumen Fisik), dan `cancelled` (Dibatalkan) di `DocumentRequest`. File draft dan scan disimpan di disk `local` seperti lampiran pengajuan, lalu scan dipindahkan ke arsip lewat `Document::archive()` saat fisik diterima. "Terlambat" dihitung dari tanggal unggah scan, bukan disimpan sebagai status. Notifikasi memakai antrean `document_request_notifications` yang sudah ada, dengan `event_key` sebagai kunci idempotensi.

**Tech Stack:** Laravel 13, PHP 8.4, Filament 5, Livewire, spatie/laravel-permission + Filament Shield, PHPUnit, SQLite (lokal) dan MySQL (server).

**Spec:** `docs/superpowers/specs/2026-11-04-alur-tanda-tangan-dan-dokumen-fisik-design.md`

## Global Constraints

- Batas penyerahan fisik: **7 hari kalender** sejak scan **pertama** diunggah, dihitung dengan tanggal Asia/Jakarta (`config('lms.reminder_timezone')`). Pengajuan Terlambat bila tanggal hari ini (WIB) > tanggal unggah (WIB) + 7.
- Pengingat keterlambatan: lewat hari ke-7 (pemohon), ke-14 (pemohon + Staff Legal), ke-28 (pemohon + Staff Legal + Koordinator). Angka **tetap**, **selalu aktif**, tidak ikut sakelar `request_reminder_enabled`. Satu kali per tahap. Bila tahap terlewati, kirim **hanya tahap tertinggi** yang sudah tercapai.
- Pengingat unggah scan: **sekali**, ke pemohon, bila lewat 7 hari sejak draft **pertama** dikeluarkan dan scan belum ada.
- Mengganti scan **tidak** mengulang hitungan 7 hari. Mengganti draft **tidak** mengulang hitungan pengingat scan.
- Alasan keterlambatan: ditulis pemohon, boleh berkali-kali, tidak disetujui, tidak memperpanjang batas.
- Staff Legal = pemegang `Review:DocumentRequest`. Aksi legal (draft, scan atas nama pemohon, Fisik Diterima, Batalkan) boleh dilakukan **semua** Staff Legal, tidak dibatasi `reviewer_id`. Fisik Diterima juga mensyaratkan `Create:Document`.
- Koordinator = pemegang izin baru `ReceiveEscalation:DocumentRequest` (label **"Terima eskalasi pengajuan"**).
- Jenis file draft/scan: `config('lms.allowed_extensions')`, ukuran maks `config('lms.max_upload_size_kb')`, dengan aturan yang sama seperti lampiran pengajuan.
- Label status (Bahasa Indonesia): `draft` Draf, `submitted` Diperiksa, `revision` Perlu Revisi, `rejected` Ditolak, `signing` Menunggu Tanda Tangan, `handover` Menunggu Dokumen Fisik, `archived` Selesai, `cancelled` Dibatalkan.
- Ikuti gaya kode sekitar: PHPDoc berbahasa Indonesia, transaksi + `lockForUpdate()` untuk perubahan status, `Gate::forUser()->authorize()`, pesan validasi berbahasa Indonesia. Jalankan `vendor/bin/pint --dirty --format agent` sebelum commit.
- Tes **wajib** memakai `phpunit.xml` (DB `:memory:`). Jangan pernah memakai `--no-configuration` atau `-c`.

## Review Focus

1. **Tautan publik dipakai di status yang salah:** unggah scan atau alasan lewat `/pengajuan/{token}` saat status `archived`/`cancelled`/`submitted` harus ditolak dengan pesan, bukan error 500. Tes ada di Task 7 (`test_public_scan_upload_is_refused_after_completion`).
2. **Batas hari di sekitar tengah malam WIB:** scan diunggah 23:30 WIB (16:30 UTC) dihitung sebagai tanggal WIB itu. Tes ada di Task 1 (`test_handover_due_date_uses_jakarta_date`).
3. **Penjadwal jalan dua kali, atau mati beberapa hari:** pengingat tidak dobel, dan tahap yang terlewati tidak dikirim mundur. Tes ada di Task 5 (`test_runs_twice_without_duplicates`, `test_after_scheduler_gap_only_highest_stage_is_sent`).
4. **Dua staff legal menekan Fisik Diterima bersamaan:** hanya satu dokumen arsip yang tercipta. Tes ada di Task 3 (`test_mark_received_twice_creates_one_document`).
5. **File tidak valid:** draft atau scan berformat `.txt`, atau berisi teks dengan nama `.pdf`, ditolak dengan pesan validasi, dan tidak ada file yang tertinggal di disk. Tes ada di Task 3 (`test_invalid_draft_file_is_rejected_and_not_stored`).

---

## File Structure

| File | Tanggung jawab |
|---|---|
| `database/migrations/2026_11_04_000001_add_signing_handover_to_document_requests.php` | Kolom baru + tabel `document_request_late_reasons` |
| `database/migrations/2026_11_04_000002_migrate_request_statuses_to_signing_flow.php` | Ubah status lama, bersihkan template `review`/`approved`, beri izin koordinator |
| `app/Models/DocumentRequest.php` | Status baru, helper tenggat, scope pantauan, aksi alur (draft, scan, alasan, terima, batal) |
| `app/Models/DocumentRequestLateReason.php` (baru) | Alasan keterlambatan |
| `app/Policies/DocumentRequestPolicy.php` | Ability baru, hapus `review`/`approve`/`archive` |
| `app/Services/DocumentRequestNotifier.php` | Penerima per status baru |
| `app/Services/HandoverReminderSender.php` (baru) | Pengingat 7/14/28 dan pengingat scan |
| `app/Console/Commands/SendRequestReminders.php` | Juga memanggil `HandoverReminderSender` |
| `app/Models/ReminderTemplate.php` | Event template baru dan teks bawaan |
| `app/Http/Controllers/DocumentRequestFileController.php` (baru) | Unduh draft/scan (admin dan publik) |
| `app/Http/Controllers/PublicDocumentRequestController.php` | Unggah scan dan kirim alasan dari tautan publik |
| `resources/views/requests/status.blade.php` | UI publik untuk draft, scan, tenggat, alasan, dibatalkan |
| `app/Filament/Resources/DocumentRequests/Pages/EditDocumentRequest.php` | Aksi admin baru |
| `app/Filament/Resources/DocumentRequests/Schemas/DocumentRequestForm.php` | Bagian "Tanda tangan & dokumen fisik" (hanya-baca) |
| `app/Filament/Resources/DocumentRequests/DocumentRequestResource.php` | Visibilitas dan `scopeToActionable` |
| `app/Filament/Resources/DocumentRequests/Tables/DocumentRequestsTable.php` | Filter "Tahap" dan kolom "Keterangan" |
| `app/Filament/Widgets/WaitingRequestsOverview.php` | Kartu Menunggu Fisik dan Terlambat |
| `app/Filament/Resources/ReminderTemplates/Pages/ManageReminderTemplates.php` | Label template baru |
| `app/Filament/Resources/DocumentTypes/Schemas/DocumentTypeForm.php` | Sembunyikan "Pemberi persetujuan" |
| `config/filament-shield.php`, `database/seeders/DummyAccountSeeder.php` | Izin eskalasi, role contoh |
| `routes/web.php` | Rute file dan rute aksi publik |

---

### Task 1: Skema, status baru, dan helper tenggat

**Files:**
- Create: `database/migrations/2026_11_04_000001_add_signing_handover_to_document_requests.php`, `app/Models/DocumentRequestLateReason.php`, `database/factories/DocumentRequestLateReasonFactory.php`
- Modify: `app/Models/DocumentRequest.php`, `database/factories/DocumentRequestFactory.php`
- Test: `tests/Feature/RequestHandoverDeadlineTest.php`

**Interfaces:**
- Produces:
  - Kolom `document_requests`: `draft_path` string null, `draft_name` string null, `draft_issued_at` timestamp null (draft **pertama**), `signed_path` string null, `signed_name` string null, `signed_uploaded_at` timestamp null (scan **pertama**), `physical_received_at` timestamp null.
  - Tabel `document_request_late_reasons`: `id`, `document_request_id` (FK, cascade), `reason` text, `user_id` FK users nullable (null bila dari tautan publik), timestamps.
  - `DocumentRequest::STATUSES` sesuai Global Constraints, urutan: draft, submitted, revision, signing, handover, archived, rejected, cancelled.
  - `DocumentRequest::WAITING_STATUSES = ['submitted']`.
  - `DocumentRequest::PROGRESS_STEPS = ['submitted' => 'Diperiksa', 'signing' => 'Menunggu Tanda Tangan', 'handover' => 'Menunggu Dokumen Fisik', 'archived' => 'Selesai']`.
  - `public const HANDOVER_DAYS = 7; public const LATE_REMINDER_DAYS = [7, 14, 28]; public const SCAN_REMINDER_DAYS = 7;`
  - `lateReasons(): HasMany` (urut `created_at` naik).
  - `handoverDueDate(): ?CarbonImmutable`: tanggal WIB (`startOfDay`) = tanggal WIB `signed_uploaded_at` + 7, atau null bila belum ada scan.
  - `daysSinceSigned(?CarbonImmutable $today = null): ?int`: selisih hari kalender WIB sejak tanggal unggah scan.
  - `daysSinceDraft(?CarbonImmutable $today = null): ?int`: sama, sejak `draft_issued_at`.
  - `isHandoverLate(?CarbonImmutable $today = null): bool`: `status === 'handover'` dan `daysSinceSigned > 7`.
  - `daysLate(): int`: `max(0, daysSinceSigned - 7)` (0 bila tidak terlambat).
  - Scope `handoverOnTime()`, `handoverLate()`, `scanOverdue()` (status `signing`, `signed_uploaded_at` null, draft lebih dari 7 hari), dan `lateWithoutReason()`. Scope dihitung di SQL memakai batas `now(WIB)->startOfDay()->subDays(7)` yang dikonversi ke UTC.
  - Factory states: `signing(?int $draftDaysAgo = 0)`, `handover(int $signedDaysAgo = 0)`, `cancelled()`.

- [ ] **Step 1: Write failing tests** in `RequestHandoverDeadlineTest`:
  - `test_handover_due_date_is_seven_calendar_days_after_first_scan`: `travelTo('2026-11-04 10:00 WIB')`, factory `handover(0)`, lalu `handoverDueDate()->toDateString() === '2026-11-11'`.
  - `test_handover_due_date_uses_jakarta_date`: `signed_uploaded_at = '2026-11-04 16:30:00 UTC'` (23:30 WIB) → due `2026-11-11`. Untuk `'2026-11-04 17:30 UTC'` (00:30 WIB 5 Nov) → due `2026-11-12`.
  - `test_is_late_only_after_day_seven`: hari ke-7 belum terlambat, hari ke-8 terlambat dengan `daysLate() === 1`.
  - `test_scopes_split_waiting_requests`: buat handover hari 3, handover hari 9, handover hari 9 dengan alasan, signing dengan draft hari 9 tanpa scan, dan cancelled hari 20. Pastikan `handoverOnTime` → 1, `handoverLate` → 2, `lateWithoutReason` → 1, `scanOverdue` → 1, dan yang cancelled tidak muncul di scope mana pun.
- [ ] **Step 2: Run, expect FAIL** — `php artisan test --compact tests/Feature/RequestHandoverDeadlineTest.php` (method/kolom belum ada).
- [ ] **Step 3: Buat migrasi via `php artisan make:migration add_signing_handover_to_document_requests --no-interaction`**, model `DocumentRequestLateReason` (Fillable `reason`, `user_id`; relasi `documentRequest()`, `user()`) dan factory-nya via `php artisan make:model DocumentRequestLateReason -f --no-interaction`. Lalu tambahkan konstanta, cast datetime untuk lima kolom waktu, helper, scope, dan state factory sesuai Interfaces.
- [ ] **Step 4: Run, expect PASS** — perintah yang sama.
- [ ] **Step 5: Commit** — `feat: kolom dan helper tenggat untuk alur tanda tangan`

### Task 2: Migrasi data, izin koordinator, dan role contoh

**Files:**
- Create: `database/migrations/2026_11_04_000002_migrate_request_statuses_to_signing_flow.php`
- Modify: `config/filament-shield.php` (custom_permissions), `database/seeders/DummyAccountSeeder.php`
- Test: `tests/Feature/SigningFlowMigrationTest.php`, update `tests/Feature/DummyAccountSeederTest.php`

**Interfaces:**
- Produces:
  - Izin `ReceiveEscalation:DocumentRequest` => `'Terima eskalasi pengajuan'`. `Approve:DocumentRequest` tetap ada di daftar (tidak dipakai alur).
  - Role contoh: `dummy_legal` mendapat juga `Create:Document` (sudah) dan tetap `Review:DocumentRequest`. `dummy_pemeriksa` mendapat `Create:Document`. `dummy_penyetuju` diganti nama kuncinya menjadi `dummy_koordinator` dengan izin `ViewAny/View/ViewAll:DocumentRequest`, `ViewAny/View:Document`, dan `ReceiveEscalation:DocumentRequest`.
- Migrasi `up()` (idempoten):
  1. `status = 'review'` → `'submitted'`.
  2. `status = 'approved'` → `'signing'` (draft kosong).
  3. Untuk setiap baris `reminder_templates`, hapus kunci `review` dan `approved` dari JSON `request_templates`.
  4. Buat izin `ReceiveEscalation:DocumentRequest`. Setiap user yang `can('Approve:DocumentRequest')` (langsung maupun lewat role; lewati bila izin `Approve` belum ada) diberi `ReceiveEscalation:DocumentRequest` dan `ViewAll:DocumentRequest` secara langsung. Reset cache izin.
  - `down()`: `signing` tanpa draft → `approved`. Tidak membalik pemberian izin.

- [ ] **Step 1: Write failing tests** in `SigningFlowMigrationTest` (jalankan migrasi via `(require database_path('migrations/...php'))->up()` di atas data yang dibuat setelah `RefreshDatabase`, mengikuti pola `tests/Feature/PartnerNameMigrationTest.php`):
  - `test_review_becomes_submitted_and_approved_becomes_signing`
  - `test_request_templates_drop_review_and_approved_keys`: `['approved' => 'x', 'submitted' => 'y']` → `['submitted' => 'y']`.
  - `test_approvers_receive_escalation_and_view_all`: user dengan role yang punya `Approve:DocumentRequest` → `can('ReceiveEscalation:DocumentRequest')` dan `can('ViewAll:DocumentRequest')` bernilai true. User lain tidak berubah.
  - `DummyAccountSeederTest`: ganti ekspektasi `dummy_penyetuju` dengan `dummy_koordinator` (email `koordinator@test.com`, nama `Koordinator`), dan pastikan `dummy_pemeriksa` bisa `Create:Document`.
- [ ] **Step 2: Run, expect FAIL** — `php artisan test --compact tests/Feature/SigningFlowMigrationTest.php tests/Feature/DummyAccountSeederTest.php`
- [ ] **Step 3: Implement** migrasi, config, dan seeder sesuai Interfaces.
- [ ] **Step 4: Run, expect PASS** — perintah yang sama.
- [ ] **Step 5: Commit** — `feat: migrasi status lama dan izin eskalasi koordinator`

### Task 3: Aksi alur di model dan policy

**Files:**
- Modify: `app/Models/DocumentRequest.php`, `app/Policies/DocumentRequestPolicy.php`
- Test: `tests/Feature/RequestSigningFlowTest.php`. Perbarui tes lama yang memakai `review`/`approve`/`archiveSigned`: `FullLifecycleFlowTest`, `DeactivationAndImportFlowTest`, `PublicRequestCounterpartyTest`, `PublicRequestStatusTimelineTest`, `DeliveryResendTest`, `UserDeactivationTest`.

**Interfaces:**
- Consumes: kolom dan helper dari Task 1.
- Produces (semuanya memakai transaksi + `lockForUpdate`, otorisasi lewat `Gate::forUser($user)`, menulis riwayat, lalu `DocumentRequestNotifier::enqueue()` bila status berubah atau pemohon perlu tahu):
  - `issueDraft(UploadedFile $file, User $user): void`: dari `submitted` → `signing`, set `draft_issued_at` bila masih null. Dari `signing` tanpa scan, file diganti dan event riwayat `draft_replaced` dicatat (pemohon diberi tahu). Ability `issueDraft`.
  - `uploadSigned(UploadedFile $file, ?User $user): void`: dari `signing` → `handover` dengan `signed_uploaded_at` diisi bila null. Dari `handover`, file diganti dan event `signed_replaced` dicatat **tanpa** mengubah `signed_uploaded_at`. `$user` null berarti dari tautan publik (otorisasi oleh token di controller). Bila tidak null, ability `uploadSigned`.
  - `addLateReason(string $reason, ?User $user): DocumentRequestLateReason`: hanya bila `isHandoverLate()`. `reason` wajib, maks 2000. Event riwayat `late_reason` dengan catatan = alasan. Ability `addLateReason` bila `$user` tidak null.
  - `markReceived(string $number, ?string $issuedDate, ?string $expiryDate, User $user, ?int $companyId = null, ?UploadedFile $file = null): Document`: menggantikan `archiveSigned`. Dari `signing` atau `handover`. File = `$file` bila ada, kalau tidak dari `signed_path`. Bila keduanya kosong, lempar `ValidationException` (`file` => 'Unggah dokumen yang sudah ditandatangani.'). Set `physical_received_at`, status `archived`. Ability `markReceived`. Pertahankan pembersihan file S3 yang ada di `archiveSigned`.
  - `transition()`: hanya `submitted`, `revision`, `rejected` (dari `submitted`), dan `cancelled` (dari `signing`/`handover`, catatan wajib, ability `cancel`). Hapus cabang `review`/`approved`. Pembatalan **tidak** menghapus `draft_path`/`signed_path` (spec §3.8).
  - File disimpan lewat helper privat `storeFlowFile(UploadedFile $file, string $kind): string` dengan `$kind` berupa `draft` atau `signed`, di `lms/requests/{folder jenis}/`, dengan nama dari `StorageFileName::build([...badan usaha, jenis, 'draft'|'ttd', "pengajuan{$id}"])`. File lama dihapus **setelah** commit berhasil. Bila transaksi gagal, file baru dihapus.
  - `recordEvent()` tetap untuk perubahan status. Tambah `recordActivity(string $event, ?User $user, ?string $note = null): void`, yang menulis entri riwayat `['event' => $event, 'status' => $this->status, ...]` **tanpa** mengubah `status_changed_at`.
  - Policy: tambah `issueDraft` (Review, status `submitted` dengan aturan pemeriksa yang ada, yaitu bukan pengajuan sendiri dan `reviewer_id` null atau diri sendiri; **atau** status `signing` dengan scan belum ada, dan dari status ini semua pemegang Review boleh), `uploadSigned` (requester sendiri atau Review, status `signing`/`handover`), `addLateReason` (requester sendiri, `isHandoverLate()`), `markReceived` (Review + `Create:Document`, status `signing`/`handover`), dan `cancel` (Review, status `signing`/`handover`). `decide` diganti `revise` (Review, status `submitted`, aturan pemeriksa yang ada). Hapus `review`, `approve`, `archive`. `view`: pemegang Review juga boleh melihat semua pengajuan berstatus `signing`, `handover`, `archived`, dan `cancelled`.

- [ ] **Step 1: Write failing tests** in `RequestSigningFlowTest` (`Storage::fake('local')`, `Storage::fake('s3')`, WagHub di-fake seperti `DocumentRequestFormScreenTest`):
  - `test_issue_draft_moves_submitted_to_signing_and_stores_file`
  - `test_replacing_draft_keeps_first_issue_time`
  - `test_draft_cannot_be_replaced_after_scan`
  - `test_upload_signed_starts_handover_once_and_replacing_keeps_clock`: unggah hari 0, ganti hari 3, lalu `signed_uploaded_at` tetap hari 0 dan riwayat memuat `signed_replaced`.
  - `test_any_legal_staff_can_act_not_only_assigned_reviewer`: reviewer_id = A, aksi oleh B (pemegang Review) pada `signing` berhasil.
  - `test_late_reason_only_when_late_and_kept_as_history`: hari 5 ditolak, hari 9 dua alasan, lalu `lateReasons()->count() === 2`.
  - `test_mark_received_archives_signed_scan`: dokumen arsip dibuat dengan file dari scan, status `archived`, dan `physical_received_at` terisi.
  - `test_mark_received_from_signing_requires_file`
  - `test_mark_received_twice_creates_one_document`: panggil dua kali, yang kedua melempar `ValidationException`, dan `Document::count() === 1`.
  - `test_cancel_requires_note_and_stops_flow`: dari `handover` terlambat → `cancelled`, catatan tersimpan, file draft dan scan masih ada di disk.
  - `test_invalid_draft_file_is_rejected_and_not_stored`: `.txt` dan PDF palsu, dengan `Storage::disk('local')->allFiles()` kosong.
  - `test_requester_cannot_issue_draft_or_mark_received`
- [ ] **Step 2: Run, expect FAIL** — `php artisan test --compact tests/Feature/RequestSigningFlowTest.php`
- [ ] **Step 3: Implement** model dan policy sesuai Interfaces.
- [ ] **Step 4: Perbarui tes lama** yang tercantum di **Files**. Ganti `transition('review'|'approved')` dengan `issueDraft()` (+ `uploadSigned()` bila perlu), dan `archiveSigned()` dengan `markReceived()`. Pertahankan maksud setiap tes.
- [ ] **Step 5: Run, expect PASS** — `php artisan test --compact tests/Feature/RequestSigningFlowTest.php tests/Feature/FullLifecycleFlowTest.php tests/Feature/DeactivationAndImportFlowTest.php tests/Feature/PublicRequestCounterpartyTest.php tests/Feature/PublicRequestStatusTimelineTest.php tests/Feature/DeliveryResendTest.php tests/Feature/UserDeactivationTest.php`
- [ ] **Step 6: Commit** — `feat: aksi draft, scan, alasan, fisik diterima, dan batal pada pengajuan`

### Task 4: Notifikasi per tahap dan template

**Files:**
- Modify: `app/Services/DocumentRequestNotifier.php`, `app/Models/ReminderTemplate.php`, `app/Models/DocumentRequest.php` (helper penerima)
- Test: `tests/Feature/RequestSigningNotificationTest.php`, perbarui `tests/Feature/WhatsAppTemplateSettingsTest.php`

**Interfaces:**
- Consumes: aksi dari Task 3.
- Produces:
  - `DocumentRequest::legalStaffRecipients(): Collection<int, User>`: `reviewer` bila aktif, punya Review, dan nomornya valid. Kalau tidak, semua user aktif pemegang `Review:DocumentRequest` dengan nomor valid.
  - `DocumentRequest::coordinatorRecipients(): Collection<int, User>`: user aktif pemegang `ReceiveEscalation:DocumentRequest` dengan nomor valid. Kembalikan koleksi kosong bila izin belum ada.
  - `ReminderTemplate::REQUEST_TEMPLATE_LABELS` menjadi: `submitted`, `revision`, `rejected`, `signing` ('Draft dikeluarkan'), `draft_replaced` ('Draft diperbarui'), `handover_staff` ('Scan bertanda tangan masuk (untuk legal)'), `scan_reminder` ('Pengingat unggah scan'), `late_7`, `late_14`, `late_28` ('Dokumen fisik terlambat: minggu ke-1/2/4'), `archived` ('Dokumen fisik diterima / selesai'), `cancelled` ('Dibatalkan'), `pic`. Teks bawaan `DEFAULT_REQUEST_TEMPLATES` untuk setiap kunci ditulis bebas oleh implementer, mengikuti gaya yang ada (`Pengajuan #{nomor_pengajuan}\n{judul}\n...\n{tautan}`).
  - Variabel baru di `REQUEST_VARIABLE_LABELS`/`REQUEST_EXAMPLE_VALUES`: `batas_waktu` (tanggal d M Y), `hari_terlambat`.
  - `enqueue()`: `signing` (draft pertama) dan `draft_replaced` ke pemohon. `handover` (scan pertama) ke `legalStaffRecipients()` dengan event template `handover_staff` dan kunci `-u{id}`. `archived` dan `cancelled` ke pemohon. Event `submitted`/`revision`/`rejected` tetap seperti sekarang. Ganti scan **tidak** mengirim notifikasi.
  - Tautan pemohon: tautan publik bertoken bila ada, kalau tidak halaman edit admin (perilaku sekarang).

- [ ] **Step 1: Write failing tests**:
  - `test_issuing_draft_notifies_applicant_with_link`
  - `test_replacing_draft_notifies_applicant_again`
  - `test_first_scan_notifies_legal_staff_only_once`: ganti scan tidak membuat notifikasi baru.
  - `test_legal_staff_falls_back_to_all_reviewers_with_phone`
  - `test_cancel_and_archive_notify_applicant_with_note`
  - Di `WhatsAppTemplateSettingsTest`: data provider status diganti dengan kunci baru, dan menyimpan `request_templates` berisi `approved` ditolak validasi.
- [ ] **Step 2: Run, expect FAIL** — `php artisan test --compact tests/Feature/RequestSigningNotificationTest.php tests/Feature/WhatsAppTemplateSettingsTest.php`
- [ ] **Step 3: Implement** sesuai Interfaces.
- [ ] **Step 4: Run, expect PASS** — perintah yang sama.
- [ ] **Step 5: Commit** — `feat: notifikasi WhatsApp untuk draft, scan, selesai, dan batal`

### Task 5: Pengingat keterlambatan dan pengingat scan

**Files:**
- Create: `app/Services/HandoverReminderSender.php`
- Modify: `app/Console/Commands/SendRequestReminders.php`, `app/Services/DocumentRequestReminderSender.php` (hanya status `submitted`)
- Test: `tests/Feature/HandoverReminderTest.php`, perbarui `tests/Feature/RequestReminderTest.php`

**Interfaces:**
- Consumes: scope dan helper Task 1, penerima dan template Task 4.
- Produces: `HandoverReminderSender::run(?CarbonImmutable $today = null): int` (jumlah notifikasi baru).
  - Untuk setiap `handover` terlambat: `stage` = angka tertinggi dari `LATE_REMINDER_DAYS` yang `< daysSinceSigned`. Kunci `lms-request-{id}-late{stage}-{kind}` dengan `kind` `applicant`, atau `u{userId}` untuk staff dan koordinator. Penerima: stage 7 → pemohon. 14 → pemohon + `legalStaffRecipients()`. 28 → pemohon + staff + `coordinatorRecipients()`.
  - Untuk setiap `scanOverdue`: kunci `lms-request-{id}-scan7-applicant`, ke pemohon.
  - Notifikasi dibuat dengan `firstOrCreate` di `document_request_notifications` (pola `DocumentRequestReminderSender`), lalu dikirim oleh `lms:send-request-notifications` yang sudah berjalan tiap menit.
  - `SendRequestReminders::handle()` memanggil kedua sender dan mencetak jumlahnya. Jadwal tetap `dailyAt(config('lms.reminder_time'))`.

- [ ] **Step 1: Write failing tests** in `HandoverReminderTest`:
  - `test_day_seven_is_quiet_day_eight_reminds_applicant`
  - `test_day_fifteen_reminds_applicant_and_legal_staff`
  - `test_day_twenty_nine_reminds_applicant_staff_and_coordinator`
  - `test_runs_twice_without_duplicates`
  - `test_after_scheduler_gap_only_highest_stage_is_sent`: hari 30 tanpa riwayat → hanya `late28` yang dibuat.
  - `test_no_reminders_for_cancelled_archived_or_after_day_28_stage`
  - `test_scan_reminder_once_after_seven_days_without_scan`
  - `test_late_reminders_ignore_request_reminder_switch`: `request_reminder_enabled = false` tetap mengirim.
  - `RequestReminderTest`: hapus kasus status `review` dan approver.
- [ ] **Step 2: Run, expect FAIL** — `php artisan test --compact tests/Feature/HandoverReminderTest.php tests/Feature/RequestReminderTest.php`
- [ ] **Step 3: Implement** sesuai Interfaces.
- [ ] **Step 4: Run, expect PASS** — perintah yang sama.
- [ ] **Step 5: Commit** — `feat: pengingat keterlambatan dokumen fisik dan pengingat unggah scan`

### Task 6: Halaman pengajuan di admin

**Files:**
- Create: `app/Http/Controllers/DocumentRequestFileController.php`
- Modify: `app/Filament/Resources/DocumentRequests/Pages/EditDocumentRequest.php`, `app/Filament/Resources/DocumentRequests/Schemas/DocumentRequestForm.php`, `routes/web.php`
- Test: `tests/Feature/RequestSigningScreenTest.php`

**Interfaces:**
- Consumes: aksi model dan policy Task 3.
- Produces:
  - Rute `GET /admin/document-requests/{documentRequest}/files/{kind}` (`kind` = `draft|signed`, `auth:web`, nama `requests.file`). Otorisasi `view` (policy). Mengembalikan 404 bila file tidak ada. Respons mengikuti `DocumentRequestAttachmentController` (inline untuk PDF/gambar, `Cache-Control: no-store, private`).
  - `DocumentRequestFileController::publicDownload(string $token, string $kind)` dipakai Task 7 (rute publik didaftarkan di Task 7).
  - Header actions (hapus `review`, `approve`, `archive`):

    | Nama | Label | Kapan terlihat |
    |---|---|---|
    | `issueDraft` | "Keluarkan Draft" / "Ganti Draft" | `can('issueDraft')` |
    | `uploadSigned` | "Unggah Scan Bertanda Tangan" / "Ganti Scan" | `can('uploadSigned')` |
    | `addLateReason` | "Tulis Alasan Keterlambatan" | `can('addLateReason')` |
    | `markReceived` | "Fisik Diterima" | `can('markReceived')`; form = form `archive` lama + `FileUpload file`, wajib bila scan belum ada dan opsional bila sudah ada |
    | `cancel` | "Batalkan" | `can('cancel')`, warna danger, `Textarea note` wajib |

    Aksi `revise`/`reject` tetap ada dengan ability `revise`.
  - Bagian hanya-baca baru **"Tanda tangan & dokumen fisik"** di `DocumentRequestForm` (terlihat bila status `signing`/`handover`/`archived`/`cancelled`). Isinya: tautan unduh draft dan scan, tanggal draft keluar, tanggal scan, batas waktu, badge Terlambat X hari atau sisa N hari, tanggal fisik diterima, dan daftar alasan (waktu + teks).
- [ ] **Step 1: Write failing tests** (Livewire, pola `DocumentRequestFormScreenTest`):
  - `test_legal_sees_issue_draft_on_submitted_and_requester_does_not`
  - `test_issue_draft_action_uploads_and_changes_status`
  - `test_requester_uploads_scan_and_sees_deadline`
  - `test_late_requester_sees_reason_action_and_reason_list`
  - `test_mark_received_without_scan_requires_file`
  - `test_cancel_action_requires_note`
  - `test_file_route_requires_view_permission_and_returns_404_when_missing`
- [ ] **Step 2: Run, expect FAIL** — `php artisan test --compact tests/Feature/RequestSigningScreenTest.php`
- [ ] **Step 3: Implement** sesuai Interfaces.
- [ ] **Step 4: Run, expect PASS** — perintah yang sama, ditambah `tests/Feature/DocumentRequestFormScreenTest.php`.
- [ ] **Step 5: Commit** — `feat: aksi draft, scan, fisik diterima, dan batal di halaman pengajuan`

### Task 7: Halaman status publik

**Files:**
- Modify: `app/Http/Controllers/PublicDocumentRequestController.php`, `resources/views/requests/status.blade.php`, `app/Models/DocumentRequest.php` (`progressSteps`, `publicTimeline`), `routes/web.php`
- Test: `tests/Feature/PublicRequestSigningTest.php`, perbarui `tests/Feature/PublicRequestStatusTimelineTest.php`

**Interfaces:**
- Consumes: `uploadSigned(file, null)`, `addLateReason(reason, null)` (Task 3), `DocumentRequestFileController::publicDownload` (Task 6).
- Produces:
  - Rute (token `[A-Za-z0-9]{64}`):
    - `GET /pengajuan/{token}/berkas/{kind}` (`requests.public.file`, throttle `public-request-status`)
    - `POST /pengajuan/{token}/scan` (`requests.public.scan`, throttle `public-request-revise`)
    - `POST /pengajuan/{token}/alasan` (`requests.public.reason`, throttle `public-request-revise`)
  - Status tidak cocok: redirect ke halaman status dengan error `'Pengajuan ini tidak sedang menunggu dokumen tersebut.'`. Validasi file dan alasan: redirect kembali dengan error. Gangguan sistem: pola `handleSubmission`.
  - `progressSteps()` memakai `PROGRESS_STEPS` baru. `rejected` berhenti di Diperiksa. `cancelled` berhenti di tahap terakhir sebelum batal (`signing` atau `handover`, dibaca dari riwayat).
  - `publicTimeline()` memberi label untuk status baru dan event (`draft_replaced`, `signed_replaced`, `late_reason`). Aktor: "Tim legal" untuk aksi legal, "Pengaju" untuk scan dan alasan. Tone `danger` untuk `cancelled`, `warning` untuk `late_reason`.
  - View: tombol unduh draft (signing/handover), form unggah scan (signing/handover), batas waktu dan sisa hari (handover), kotak Terlambat + form alasan + daftar alasan (handover terlambat), alasan pembatalan (cancelled). Halaman `/pengajuan/cek` (lookup) tidak menampilkan form apa pun.
- [ ] **Step 1: Write failing tests**:
  - `test_public_page_offers_draft_download_and_scan_upload_when_signing`
  - `test_public_scan_upload_starts_handover`
  - `test_public_late_reason_is_saved_and_listed`
  - `test_public_scan_upload_is_refused_after_completion`: status `archived` dan `cancelled`, lalu redirect dengan error dan tidak ada file baru.
  - `test_lookup_page_shows_no_upload_or_reason_forms`
  - `test_cancelled_shows_reason_and_stopped_progress`
  - `test_public_file_route_rejects_unknown_token_and_kind`
- [ ] **Step 2: Run, expect FAIL** — `php artisan test --compact tests/Feature/PublicRequestSigningTest.php tests/Feature/PublicRequestStatusTimelineTest.php`
- [ ] **Step 3: Implement** sesuai Interfaces.
- [ ] **Step 4: Run, expect PASS** — perintah yang sama.
- [ ] **Step 5: Commit** — `feat: unduh draft, unggah scan, dan alasan keterlambatan di halaman status publik`

### Task 8: Pantauan legal (daftar, visibilitas, widget)

**Files:**
- Modify: `app/Filament/Resources/DocumentRequests/DocumentRequestResource.php`, `app/Filament/Resources/DocumentRequests/Tables/DocumentRequestsTable.php`, `app/Filament/Widgets/WaitingRequestsOverview.php`
- Test: `tests/Feature/RequestMonitoringTest.php`, perbarui `tests/Feature/DocumentRequestListScreenTest.php` dan `tests/Feature/WaitingRequestsTest.php`

**Interfaces:**
- Consumes: scope Task 1.
- Produces:
  - `getEloquentQuery()`: pemegang Review juga melihat semua pengajuan berstatus `signing`/`handover`/`archived`/`cancelled`. Cabang `Approve`/`approver_id` dihapus.
  - `scopeToActionable()`: hanya cabang Review (`submitted`), karena cabang `review` dihapus.
  - Tabel: `SelectFilter::make('stage')` berlabel "Tahap" dengan opsi `to_review` (Perlu diperiksa), `signing` (Menunggu tanda tangan), `handover_on_time` (Menunggu fisik), `late` (Terlambat), `archived` (Selesai), dan `cancelled` (Dibatalkan). Kolom `TextColumn::make('handover_note')` berlabel "Keterangan" berisi: "Sisa N hari" (handover tepat waktu), "Terlambat N hari · {alasan terakhir}" atau "Terlambat N hari · belum ada alasan" (warna danger), dan "Belum unggah scan > 7 hari" (signing yang terlambat scan, warna warning). Kosong untuk status lain.
  - Widget: `STAGES` diganti tiga kartu: Menunggu Pemeriksaan (`submitted`, perilaku lama), Menunggu Fisik (`handoverOnTime`), dan Terlambat (`handoverLate`, deskripsi "N belum ada alasan"). Setiap kartu menaut ke daftar dengan filter `stage` yang sesuai. `canView()` berlaku bila ada salah satunya.
- [ ] **Step 1: Write failing tests**:
  - `test_stage_filter_groups_requests` (satu pengajuan untuk setiap tahap)
  - `test_late_row_shows_days_and_latest_reason_or_missing_reason`
  - `test_any_reviewer_sees_signing_and_handover_requests_assigned_to_someone_else`
  - `test_widget_counts_waiting_handover_and_late`
  - Perbarui `DocumentRequestListScreenTest`/`WaitingRequestsTest`: hapus kasus `review`/approver.
- [ ] **Step 2: Run, expect FAIL** — `php artisan test --compact tests/Feature/RequestMonitoringTest.php tests/Feature/DocumentRequestListScreenTest.php tests/Feature/WaitingRequestsTest.php`
- [ ] **Step 3: Implement** sesuai Interfaces.
- [ ] **Step 4: Run, expect PASS** — perintah yang sama.
- [ ] **Step 5: Commit** — `feat: pantauan tanda tangan dan dokumen fisik untuk legal`

### Task 9: Pengaturan dan pembersihan sisa alur lama, lalu verifikasi menyeluruh

**Files:**
- Modify: `app/Filament/Resources/ReminderTemplates/Pages/ManageReminderTemplates.php` (label template dan pesan uji untuk kunci baru), `app/Filament/Resources/DocumentTypes/Schemas/DocumentTypeForm.php` (sembunyikan `request_approver_id`), `app/Models/DocumentType.php` (`resolveRequestPic` tidak lagi melihat `request_approver_id`), `app/Services/UserDeactivator.php` (grup approver tidak lagi wajib dipindahkan; tetap kosongkan `approver_id` agar tidak menggantung)
- Test: perbarui `tests/Feature/WhatsAppTemplateSettingsTest.php`, `tests/Feature/UserDeactivationTest.php`. Tambah `test_document_type_form_hides_approver` di `tests/Feature/DocumentTypeResourceTest.php` (buat bila belum ada, dengan `php artisan make:test --phpunit DocumentTypeResourceTest`).

- [ ] **Step 1: Write/update failing tests**: halaman Pengaturan WhatsApp menampilkan dan bisa menyimpan semua kunci `REQUEST_TEMPLATE_LABELS` baru, dan "Kirim pesan uji" berhasil untuk `late_14`. Form Jenis Dokumen tidak punya field `request_approver_id`. Menonaktifkan user yang dulu approver tidak diblokir.
- [ ] **Step 2: Run, expect FAIL** — `php artisan test --compact tests/Feature/WhatsAppTemplateSettingsTest.php tests/Feature/UserDeactivationTest.php tests/Feature/DocumentTypeResourceTest.php`
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run, expect PASS** — perintah yang sama.
- [ ] **Step 5: Verifikasi menyeluruh**:
  - `grep -rn "'review'\|'approved'\|archiveSigned\|Approve:DocumentRequest" app resources routes`. Satu-satunya sisa yang boleh ada: config izin, migrasi, dan `UserDeactivator` bila sengaja.
  - `vendor/bin/pint --dirty --format agent`
  - `php artisan test --compact`. Semua harus lulus.
- [ ] **Step 6: Commit** — `chore: rapikan sisa alur persetujuan lama`

---

## Catatan untuk pelaksana

- **Deploy:** jalankan `php artisan migrate --force` lalu `php artisan db:seed --class=PermissionSeeder --force` agar izin baru tercipta untuk super admin. Migrasi Task 2 sudah memberi izin ke koordinator yang ada.
- **Panduan/video** tidak termasuk rencana ini (spec §9).
