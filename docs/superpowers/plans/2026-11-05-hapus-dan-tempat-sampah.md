# Hapus Data, Tempat Sampah, dan Hapus Permanen — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Lima menu (Dokumen, Badan Usaha, Jenis Dokumen, User, Pengajuan Dokumen) bisa dihapus satu per satu dan massal ke tempat sampah. Hanya super admin yang bisa memulihkan atau menghapus permanen. Hapus permanen ditolak selama data masih dipakai.

**Architecture:**
- Empat model baru memakai `SoftDeletes` beserta kolom `deleted_by`.
- Satu layanan `App\Services\Trash\TrashManager` menjadi satu-satunya jalur hapus, pulihkan, dan hapus permanen. Layanan ini mendelegasikan aturan per model ke kelas `TrashHandler` per model, dan mencatat setiap tindakan ke tabel `trash_logs`.
- Di sisi Filament, satu pabrik aksi bersama `App\Filament\Concerns\TrashActions` dipasang di kelima resource.

**Tech Stack:** Laravel 13, PHP 8.4, Filament 5, spatie/laravel-permission + Filament Shield, PHPUnit (SQLite in-memory), Pint.

**Spec:** `docs/superpowers/specs/2026-11-05-hapus-dan-tempat-sampah-design.md` (disetujui 2026-11-05). Baca spec bersama rencana ini. Spec yang memutuskan perilaku dan teks.

## Global Constraints

- **Pulihkan dan hapus permanen hanya untuk super admin** (`User::isSuperAdmin()`), dikunci di policy, tidak bergantung pada izin `Restore:*` / `ForceDelete:*`.
- Hapus biasa memakai izin `Delete:<Model>`, hapus massal memakai `DeleteAny:<Model>`. Super admin juga selalu masuk tempat sampah dulu.
- Tempat sampah, filter "Data dihapus", kartu dasbor, dan halaman Riwayat Penghapusan **hanya terlihat oleh super admin**.
- **Tidak ada pengosongan otomatis.** Foreign key `restrict` dan indeks unik yang sudah ada **tidak diubah**.
- **Hapus permanen** ditolak bila ada data **aktif** yang memakai. Data terkait yang ada di tempat sampah ikut dihapus permanen. **Pengecualian User:** data terkait di tempat sampah pun ikut menghalangi; referensi yang boleh kosong dikosongkan.
- **File** (S3 untuk versi dokumen, `local` untuk berkas pengajuan) disentuh **hanya** saat hapus permanen, dan **setelah** transaksi database berhasil.
- **Teks UI** berbahasa Indonesia, diambil dari spec bila spec menuliskannya. Contohnya:
  - "Data dipindahkan ke tempat sampah dan hilang dari daftar. Super admin bisa memulihkannya atau menghapusnya permanen."
  - "Tindakan ini tidak bisa dibatalkan."
  - "(dihapus)"
  - "Nama ini dipakai badan usaha di tempat sampah. Minta super admin memulihkan atau menghapusnya permanen."
  - "Email ini dipakai user di tempat sampah."
- **Keamanan data:** jangan pernah menyentuh `database/database.sqlite`. Tes hanya lewat `php artisan test` (phpunit.xml, `:memory:`). Jangan pakai `--no-configuration` atau `-c`. Tinker, seed, dan cek browser hanya di salinan DB di scratchpad. Bila mengecek di browser, pakai `php -S`, bukan `php artisan serve`.
- PHP hanya ada di PATH PowerShell (Herd). Jalankan `vendor/bin/pint --dirty --format agent` sebelum tiap commit.
- Pesan commit diakhiri dengan `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- Kerjakan di branch baru `feat/hapus-tempat-sampah`, yang bercabang dari `feat/alur-tanda-tangan`. Jangan push.

## Review Focus

1. **Dokumen aktif dengan Badan Usaha di tempat sampah diubah dan disimpan tanpa mengganti badan usahanya.** Yang diharapkan: tersimpan, dan dropdown menampilkan "Nama (dihapus)". Tes ada di Task 2.
2. **User di tempat sampah mencoba login lewat password, OTP WhatsApp, dan OTP email.** Ketiganya harus ditolak, dan sesi lamanya harus terputus. Tes ada di Task 6.
3. **Tautan publik pengajuan di tempat sampah** (status, unduh berkas, unggah scan, alasan, cek status) harus 404 atau "tidak ditemukan", tanpa membocorkan data. Tes ada di Task 5.
4. **Hapus file S3 gagal saat hapus permanen dokumen.** Baris database tetap terhapus, dan `trash_logs.details` mencatat path yang tertinggal. Tes ada di Task 4.
5. **Hapus permanen massal campuran** (sebagian diblokir, sebagian bebas) tetap memproses yang bebas, lalu notifikasi menyebut yang dilewati beserta alasannya. Tes ada di Task 7.

---

### Task 1: Fondasi — kolom, log, trait, izin, policy

**Files:**
- Create:
  - migration `add_soft_deletes_for_trash` (`deleted_at` + `deleted_by` untuk `companies`, `document_types`, `users`, `document_requests`; `deleted_by` saja untuk `documents`, yang sudah punya `deleted_at`)
  - migration `create_trash_logs_table`
  - `app/Models/TrashLog.php`
  - `app/Models/Concerns/Trashable.php`
- Modify:
  - `app/Models/{Company,DocumentType,User,DocumentRequest,Document}.php`
  - `database/seeders/PermissionSeeder.php:31-40`
  - `config/filament-shield.php:191-197`
  - `app/Policies/{Company,DocumentType,User,DocumentRequest,Document}Policy.php`
  - factories: tambahkan state `trashed()` pada kelima factory
- Test: `tests/Feature/TrashFoundationTest.php`, `tests/Feature/PermissionSeederTest.php`

**Interfaces — Produces:**
- `deleted_by`: `foreignId()->nullable()->constrained('users')->nullOnDelete()`.
- `trash_logs` berisi kolom:
  - `id`
  - `subject_type` string: `document|company|document_type|user|document_request`
  - `subject_id` unsignedBigInteger, **tanpa** FK
  - `subject_label` string
  - `action` string: `deleted|restored|force_deleted`
  - `user_id` nullable FK nullOnDelete
  - `details` json nullable
  - `created_at`
- `TrashLog::ACTIONS = ['deleted' => 'Dihapus', 'restored' => 'Dipulihkan', 'force_deleted' => 'Dihapus permanen']`
- `TrashLog::SUBJECTS = ['document' => 'Dokumen', 'company' => 'Badan Usaha', 'document_type' => 'Jenis Dokumen', 'user' => 'User', 'document_request' => 'Pengajuan']`
- Trait `Trashable` (memakai `SoftDeletes`) menyediakan:
  - `deletedBy(): BelongsTo` (ke User, `withTrashed()`)
  - `trashSubject(): string` (kunci `SUBJECTS`)
  - `trashLabel(): string` (nama/judul untuk log dan pesan)
  - `displayName(): string` — mengembalikan `trashLabel()` ditambah `' (dihapus)'` bila `trashed()`
- Policy kelima model:
  - `restore`, `restoreAny`, `forceDelete`, `forceDeleteAny` → `$user->isSuperAdmin()`
  - `delete` → izin `Delete:<Model>`, sedangkan `deleteAny` → `DeleteAny:<Model>`
  - **UserPolicy::delete:** juga menolak bila `UserDeactivator::deactivationDenialReason($actor, $user) !== null`, kecuali user itu sudah nonaktif. Dalam kasus itu yang ditolak hanya hapus diri sendiri, dan hapus super admin oleh non-super-admin.
- `PermissionSeeder::RESOURCE_ACTIONS`: `User` dan `DocumentRequest` → `ALL_ACTIONS`. `manage` di shield config disamakan (tambahkan `delete`, `deleteAny`, `restore`, `restoreAny`, `forceDelete`, `forceDeleteAny`).

- [ ] **Step 1:** Tulis tes di `TrashFoundationTest`:
  - `test_five_models_soft_delete_and_record_who_deleted` — tiap model: `delete()` → `assertSoftDeleted`, relasi `deletedBy` terbaca.
  - `test_display_name_marks_trashed_records` — `Company` bernama "PT A" yang di-trash → `displayName() === 'PT A (dihapus)'`.
  - `test_only_super_admin_may_restore_or_force_delete_even_with_permission` — user biasa yang diberi `Restore:Company` + `ForceDelete:Company` → `can('restore')` dan `can('forceDelete')` false; super admin → true.
  - `test_delete_follows_delete_permission` — tanpa izin false, dengan `Delete:DocumentRequest` true.
  - `test_user_cannot_delete_self_or_last_super_admin`.
  - Perbarui `PermissionSeederTest`: `Delete:User`, `DeleteAny:DocumentRequest`, dan yang sejenis harus ada.
- [ ] **Step 2:** Jalankan `php artisan test --compact tests/Feature/TrashFoundationTest.php`. Diharapkan FAIL.
- [ ] **Step 3:** Implementasikan migration, model, trait, seeder, config, dan policy.
- [ ] **Step 4:** Jalankan tes yang sama sampai PASS. Lalu jalankan seluruh suite; tes yang sengaja bergantung pada "tidak bisa hapus" boleh merah, catat daftarnya untuk Task 7.
  - **Catatan:** `DocumentTrashTest` restore oleh non-super-admin kini harus gagal sesuai spec. Perbarui tes itu di task ini.
- [ ] **Step 5:** Pint, lalu commit: `feat: fondasi tempat sampah (soft delete, log, izin)`.

### Task 2: Induk yang dihapus tetap tampil, dan validasi "pertahankan nilai lama"

**Files:**
- Create: `app/Support/ActiveReference.php`
- Modify — relasi `belongsTo` ke model Trashable diberi `->withTrashed()`:
  - `app/Models/Document.php` (company, documentType, pic)
  - `app/Models/DocumentRequest.php` (company, documentType, requester, pic, reviewer, approver)
  - `app/Models/DocumentVersion.php` (uploader)
  - `app/Models/DocumentImport.php` (user)
  - `app/Models/DocumentType.php` (relasi request_* bila ada)
- Modify — validasi:
  - `Document.php:218-223`
  - `DocumentRequest.php:478,530-532,788`
  - `app/Filament/Resources/Documents/Schemas/DocumentForm.php:25-37`
  - `DocumentRequestForm.php:182-192`
  - `EditDocumentRequest.php:112`
  - `CompanyForm.php:32`, `DocumentTypeForm.php:28`, `UserForm.php:21`
- Modify — tampilan nama induk:
  - kolom tabel dan infolist yang menampilkan nama induk (pakai `displayName()`)
  - `DocumentSpreadsheetExporter`
  - `StorageFileName::companyName` beserta pemanggilnya (`Document.php:319`, `DocumentRequest.php:580,866`): memakai `Company::withTrashed()->find()`
- Test: `tests/Feature/TrashedParentDisplayTest.php`

**Interfaces — Produces:**
- `ActiveReference::rule(string $table, int|string|null $keepId = null, array $where = []): \Closure` — aturan validasi. Lolos bila id ada, tidak di tempat sampah, dan memenuhi `$where` (misalnya `['is_active' => true]` atau `['deactivated_at' => null]`). **Atau** lolos bila id sama dengan `$keepId`, walaupun di tempat sampah atau nonaktif.
- `ActiveReference::options(Builder $query, int|string|null $keepId, \Closure $label): array` — opsi dropdown aktif, ditambah nilai yang sedang terpasang dengan label `displayName()`.
- `ActiveReference::uniqueRule(string $modelClass, string $column, ?int $ignoreId, string $trashedMessage): \Closure` — gagal dengan `$trashedMessage` bila bentrok dengan baris di tempat sampah, dan pesan unik biasa bila bentrok dengan baris aktif.

- [ ] **Step 1:** Tulis tes:
  - `test_document_with_trashed_company_still_shows_company_name_with_marker` — infolist dan tabel memuat "PT A (dihapus)".
  - `test_active_document_with_trashed_company_saves_without_changing_company` — Livewire `EditDocument` → `fillForm` judul baru → `call('save')` → `assertHasNoFormErrors`.
  - `test_trashed_company_is_not_offered_for_new_documents_or_public_form` — `CreateDocument` options dan halaman `/pengajuan` tidak memuat nama itu.
  - `test_running_request_with_trashed_company_can_still_be_received` — `markReceived` sukses, dokumen arsip memakai `company_id` yang sama.
  - `test_running_request_with_inactive_company_can_still_be_received` — sama, tetapi badan usahanya nonaktif (perbaikan bug lama).
  - `test_new_company_with_trashed_name_is_rejected_with_trash_message` — `CreateCompany` → error memuat teks spec.
  - `test_new_user_with_trashed_email_is_rejected_with_trash_message`.
  - `test_export_and_archive_file_name_keep_trashed_company_name`.
  - `test_import_row_with_trashed_company_name_fails_with_clear_message` — status baris impor gagal dengan pesan "badan usaha tidak ditemukan" (atau pesan importir yang sudah ada untuk nama tak dikenal).
  - `test_document_table_company_filter_does_not_offer_trashed_company`.
- [ ] **Step 2:** Jalankan tes; diharapkan FAIL.
- [ ] **Step 3:** Implementasikan `ActiveReference`, lalu ganti semua aturan `exists`/`unique` dan opsi dropdown yang disebut di atas. Pola `User::scopeActiveOrKeep` yang sudah ada menjadi acuan perilaku.
- [ ] **Step 4:** Jalankan tes sampai PASS, lalu seluruh suite.
- [ ] **Step 5:** Pint, lalu commit: `feat: induk di tempat sampah tetap tampil dan bisa dipertahankan`.

### Task 3: `TrashManager`, log, dan handler Badan Usaha serta Jenis Dokumen

**Files:**
- Create:
  - `app/Services/Trash/TrashManager.php`
  - `app/Services/Trash/TrashHandler.php` (interface)
  - `app/Services/Trash/TrashUsage.php` (value object)
  - `app/Services/Trash/CompanyTrashHandler.php`
  - `app/Services/Trash/DocumentTypeTrashHandler.php`
- Test: `tests/Feature/TrashManagerTest.php`

**Interfaces — Produces:**
- `TrashUsage` (readonly):
  - `array $active` — `list<array{label: string, count: int, examples: list<string>}>`, data aktif yang memakai
  - `array $trashed` — bentuk sama, data di tempat sampah yang akan ikut terhapus permanen
  - `array $notes` — `list<string>`, kalimat tambahan modal, misalnya "3 pengajuan masih berjalan dan tetap bisa diselesaikan."
  - `blocksForceDelete(): bool`
  - `blockMessage(): string` — memakai format spec §3.4, contoh dibatasi 5 nama ditambah "dan N lainnya"
  - `summary(): string` — kalimat keterkaitan untuk modal hapus (§3.1)
- `TrashHandler`:
  - `supports(Model $record): bool`
  - `usage(Model $record): TrashUsage`
  - `beforeTrash(Model $record, User $actor, array $data): void`
  - `afterRestore(Model $record, User $actor): void`
  - `purge(Model $record): array` — menghapus permanen di dalam transaksi, termasuk anak yang di tempat sampah. Mengembalikan `list<array{disk: string, path: string}>` file yang harus dihapus setelah commit.
  - `trashDenialReason(Model $record, User $actor): ?string`
- `TrashManager`:
  - `trash(Model $record, User $actor, array $data = []): void` — transaksi: `beforeTrash`, set `deleted_by`, `delete()`, `TrashLog` `deleted`.
  - `restore(Model $record, User $actor): void` — transaksi: `restore()`, kosongkan `deleted_by`, `afterRestore`, log `restored`.
  - `forceDelete(Model $record, User $actor): void` — lempar `ValidationException` dengan `blockMessage()` bila diblokir. Bila tidak: transaksi `purge` + log `force_deleted` (label disimpan sebelum hapus), lalu hapus file setelah commit. File yang gagal dihapus dicatat di `details.left_files` pada log yang sama.
  - `usage(Model $record): TrashUsage`
  - `bulk(string $operation, iterable $records, User $actor): array` — `$operation`: `trash|restore|forceDelete`. Mengembalikan `array{done: int, skipped: list<array{label: string, reason: string}>}`. Tiap record diproses dalam transaksinya sendiri.
- Aturan Company dan DocumentType (spec §4.2–4.3):
  - aktif = dokumen dan pengajuan tidak di tempat sampah;
  - trashed = dokumen dan pengajuan di tempat sampah;
  - purge: hapus permanen anak di tempat sampah lewat handler Dokumen/Pengajuan (Task 4/5 mendaftarkannya; di task ini cukup lewat `TrashManager`, tes cascade menyusul di Task 4/5), lalu hapus induk;
  - DocumentType: `reminderTemplate` ikut dihapus.

- [ ] **Step 1:** Tulis tes:
  - `test_trash_and_restore_company_writes_logs_with_actor`
  - `test_force_delete_company_used_by_active_document_is_blocked_with_names` — pesan memuat judul dokumen dan "Hapus atau pindahkan data itu dulu.".
  - `test_force_delete_unused_company_removes_row_and_logs_label`
  - `test_usage_summary_counts_documents_requests_and_running_requests`
  - `test_block_message_lists_five_examples_then_more_count`
  - `test_bulk_reports_done_and_skipped_with_reasons`
  - `test_force_delete_document_type_removes_its_reminder_template`
- [ ] **Step 2:** Jalankan; diharapkan FAIL.
- [ ] **Step 3:** Implementasikan. Daftarkan handler lewat container, misalnya `TrashManager` menerima `iterable<TrashHandler>` yang di-tag di `AppServiceProvider`.
- [ ] **Step 4:** Jalankan sampai PASS.
- [ ] **Step 5:** Pint, lalu commit: `feat: layanan tempat sampah untuk badan usaha dan jenis dokumen`.

### Task 4: Handler Dokumen — hapus permanen beserta file

**Files:**
- Create: `app/Services/Trash/DocumentTrashHandler.php`
- Modify: `app/Filament/Resources/Documents/DocumentResource.php:78-99` (`changeTrashedState` mendelegasikan ke `TrashManager`, dan aktivitas `deleted`/`restored` tetap dicatat oleh handler)
- Test: `tests/Feature/DocumentForceDeleteTest.php`

**Interfaces:**
- Consumes: `TrashHandler`, `TrashManager` (Task 3).
- Produces: aturan Dokumen.
  - **aktif** = pengajuan tidak di tempat sampah dengan `document_id` = dokumen ini;
  - **trashed** = pengajuan di tempat sampah dengan `document_id` = dokumen ini;
  - **purge**, berurutan:
    1. pengajuan di tempat sampah (lewat handler Pengajuan);
    2. `reminder_logs` milik versi-versi dokumen;
    3. aktivitas;
    4. `current_version_id` = null;
    5. versi;
    6. dokumen.
  - Kumpulkan `{disk, path}` semua versi.
  - **notes** (modal hapus biasa): "Dokumen ini hasil pengajuan #N." bila ada pengajuan yang menunjuknya (spec §4.1).
  - Hook `deleting` pada `DocumentVersion` dan `DocumentActivity` dilewati dengan query builder (`DB::table(...)->delete()` atau `Model::query()->toBase()->delete()`), **hanya** di handler ini.

- [ ] **Step 1:** Tulis tes:
  - `test_force_delete_removes_versions_activities_reminder_logs_and_s3_files` — pakai `Storage::fake('s3')`, `assertMissing` untuk tiap file.
  - `test_trashing_does_not_touch_files`
  - `test_force_delete_blocked_while_active_request_points_to_document`
  - `test_force_delete_cascades_trashed_request_that_points_to_document`
  - `test_force_delete_when_file_removal_fails_still_removes_rows_and_logs_left_files` — fake disk dengan `delete` yang melempar exception atau mengembalikan false. Assert baris hilang dan `trash_logs.details['left_files']` berisi path. **(Review Focus #4.)**
  - `test_company_force_delete_cascades_its_trashed_documents_with_files` — melengkapi Task 3.
  - `test_restore_reopens_reminders_like_before` — perilaku `ReminderLog::reopenForRestoredDocument` tetap.
  - Perbarui `DocumentTrashTest`/`DocumentResourceTest` yang mengharapkan `canForceDelete` false.
- [ ] **Step 2:** Jalankan; diharapkan FAIL.
- [ ] **Step 3:** Implementasikan.
- [ ] **Step 4:** Jalankan sampai PASS, termasuk `DocumentReminderTest` dan `DocumentArchiveTest`.
- [ ] **Step 5:** Pint, lalu commit: `feat: hapus permanen dokumen beserta file`.

### Task 5: Handler Pengajuan Dokumen

**Files:**
- Create: `app/Services/Trash/DocumentRequestTrashHandler.php`
- Modify:
  - `app/Services/HandoverReminderSender.php`
  - `app/Services/DocumentRequestReminderSender.php` (melewati pengingat yang terlewat saat pulih)
  - `tests/Feature/PublicRequestSigningTest.php:121-134` (pakai token berbeda per iterasi, atau `forceDelete()`)
- Test: `tests/Feature/DocumentRequestTrashTest.php`

**Interfaces:**
- Produces: aturan Pengajuan.
  - **aktif:** kosong; pengajuan tidak pernah memblokir.
  - **notes:** peringatan "masih berjalan" bila status `submitted|signing|handover` (teks spec §4.5).
  - **beforeTrash:** batalkan `DocumentRequestNotification` berstatus `pending|failed` dengan `error_message` "Pengajuan dipindahkan ke tempat sampah."
  - **afterRestore:** panggil `HandoverReminderSender::skipStagesAlreadyReached(DocumentRequest $request): int` dan `DocumentRequestReminderSender::skipDueReminders(DocumentRequest $request): int`. Keduanya membuat baris notifikasi berstatus `cancelled` dengan `event_key` yang sama seperti yang akan dibuat run berikutnya untuk tahap yang sudah jatuh tempo. Dengan begitu `firstOrCreate` tidak mengirimnya.
  - **purge:**
    - hapus baris (notifikasi dan alasan terlambat terhapus karena `cascade`);
    - kembalikan file `local`: semua nilai `attachments`, `draft_path`, `signed_path`;
    - dokumen arsip tidak disentuh.

- [ ] **Step 1:** Tulis tes:
  - `test_trashing_running_request_cancels_pending_notifications_and_hides_from_lists_and_widget`
  - `test_public_links_of_trashed_request_answer_not_found` — GET status, GET berkas draft, POST scan, POST alasan → 404; POST `/pengajuan/cek` dengan nomor dan telepon yang benar → pesan "tidak ditemukan". **(Review Focus #3.)**
  - `test_trashed_request_gets_no_handover_or_scan_reminders`
  - `test_restore_after_long_trash_does_not_burst_passed_reminders_but_sends_next_stage` — scan 10 hari lalu, trash, travel ke hari 16, restore, `HandoverReminderSender::run()`:
    - tahap 7 dan 14 tidak terkirim;
    - travel ke hari 29 → tahap 28 terkirim.
  - `test_restore_keeps_lateness_counted` — `daysLate()` tetap dihitung dari `signed_uploaded_at`.
  - `test_restore_does_not_send_stale_review_reminder_immediately`
  - `test_force_delete_removes_local_files_and_keeps_archived_document`
- [ ] **Step 2:** Jalankan; diharapkan FAIL.
- [ ] **Step 3:** Implementasikan.
- [ ] **Step 4:** Jalankan sampai PASS, termasuk `HandoverReminderTest`, `RequestReminderTest`, dan `PublicRequest*Test`.
- [ ] **Step 5:** Pint, lalu commit: `feat: tempat sampah untuk pengajuan dokumen`.

### Task 6: Handler User — hapus = nonaktifkan + tempat sampah

**Files:**
- Create: `app/Services/Trash/UserTrashHandler.php`
- Modify:
  - `app/Services/UserDeactivator.php` (`openRequests()` dan `DocumentType` memakai `withTrashed()` saat mengalihkan tugas)
  - `app/Models/User.php` (`canAccessPanel`; sudah tertutup oleh SoftDeletes, tetapi pastikan lewat tes)
  - jalur login OTP: `InteractsWithWhatsAppLogin`, `InteractsWithEmailLogin`, `EmailLogin.php:71`
- Test: `tests/Feature/UserTrashTest.php`

**Interfaces:**
- Produces: aturan User.
  - **trashDenialReason:** sama seperti `deactivationDenialReason` untuk user aktif. Untuk user yang sudah nonaktif: hanya diri sendiri, dan super admin oleh non-super-admin.
  - **beforeTrash($user, $actor, $data):**
    - bila user aktif → `UserDeactivator::deactivate($user, $actor, $data)`, dengan `$data` = pengganti per grup;
    - bila sudah nonaktif → hanya hapus sesi.
  - **afterRestore:** user tetap nonaktif (`deactivated_at` tidak diubah).
  - **usage:** referensi wajib di semua baris termasuk yang di tempat sampah memblokir hapus permanen. Contohnya:
    - `documents.pic_user_id`, `document_versions.uploaded_by`;
    - `document_requests.requester_id`, `pic_user_id`;
    - `document_types.request_pic_id`, `request_reviewer_id`;
    - `document_imports.user_id`.
    - Daftar label mengikuti spec §4.4.
  - **purge:**
    - kosongkan `document_requests.reviewer_id` dan `approver_id`, serta `document_types.request_approver_id` (semua withTrashed);
    - hapus `model_has_roles` dan `model_has_permissions` untuk user ini, beserta baris `sessions`;
    - hapus user. Kolom `nullOnDelete` diurus database.
  - **Bulk trash:** user dengan `UserDeactivator::requiredGroups()` tidak kosong dilewati, dengan alasan "punya tugas yang harus dialihkan, hapus satu per satu".

- [ ] **Step 1:** Tulis tes:
  - `test_deleting_active_user_reassigns_work_including_trashed_requests_and_types_then_trashes` — pengajuan dan jenis dokumen di tempat sampah yang menunjuk user ikut dialihkan.
  - `test_trashed_user_cannot_log_in_by_password_whatsapp_or_email_otp_and_sessions_end` **(Review Focus #2)**
  - `test_trashed_user_receives_no_whatsapp_and_is_not_offered_as_pic_or_reviewer`
  - `test_restored_user_stays_deactivated`
  - `test_force_delete_blocked_by_trashed_document_where_user_is_pic` — tidak berantai.
  - `test_force_delete_unused_user_clears_optional_refs_roles_and_sessions`
  - `test_bulk_trash_skips_users_with_work`
  - `test_cannot_trash_self_or_last_super_admin`
- [ ] **Step 2:** Jalankan; diharapkan FAIL.
- [ ] **Step 3:** Implementasikan.
- [ ] **Step 4:** Jalankan sampai PASS, termasuk `UserDeactivationTest`.
- [ ] **Step 5:** Pint, lalu commit: `feat: hapus user lewat nonaktifkan dan tempat sampah`.

### Task 7: UI Filament di lima menu

**Files:**
- Create: `app/Filament/Concerns/TrashActions.php`
- Modify — Resource:
  - `CompanyResource`, `DocumentTypeResource`, `UserResource`: hapus override `canDelete*`, dan tambah `getRecordRouteBindingEloquentQuery()` `withTrashed` khusus super admin
  - `DocumentResource`: hapus `canForceDelete*` false; edit tetap ditolak untuk record di tempat sampah
  - `DocumentRequestResource`
- Modify — Table:
  - `CompaniesTable`, `DocumentTypesTable`, `UsersTable`, `DocumentsTable`, `DocumentRequestsTable`
  - tambahkan record action, `toolbarActions` bulk, `TrashActions::filter()`, serta kolom/badge "Dihapus" (tanggal dan oleh siapa) yang muncul bila filter aktif
- Modify — halaman Edit/View: `EditCompany`, `EditDocumentType`, `EditUser`, `EditDocument`/`ViewDocument`, `EditDocumentRequest`. Record di tempat sampah tampil baca-saja untuk super admin dan 404 untuk lainnya.
- Test: `tests/Feature/TrashUiTest.php`. Perbarui `CompanyResourceTest:183`, `DocumentTypeResourceTest:150`, `DocumentTrashTest`, `DocumentResourceTest:223`.

**Interfaces:**
- Consumes: `TrashManager::{trash, restore, forceDelete, usage, bulk}`.
- Produces (static, semuanya memakai `TrashManager` dan policy):
  - `TrashActions::delete(string $noun): Action` — modal berisi teks spec §3.1, `usage()->summary()`, dan `notes`. Untuk User, modal memakai field pengganti dari `UserResource::replacementFields`, yang dijadikan `public static`.
  - `TrashActions::restore(string $noun): Action`
  - `TrashActions::forceDelete(string $noun): Action` — modal merah "Tindakan ini tidak bisa dibatalkan.", menyebut anak di tempat sampah yang ikut terhapus. Bila diblokir, aksi tetap bisa diklik tetapi berakhir dengan notifikasi bahaya berisi `blockMessage()`.
  - `TrashActions::bulkDelete(string $noun): BulkAction`
  - `TrashActions::bulkRestore(string $noun): BulkAction`
  - `TrashActions::bulkForceDelete(string $noun): BulkAction` — notifikasi hasil berformat "4 …, 1 dilewati: <nama> (<alasan>)".
  - `TrashActions::filter(): TrashedFilter` — label "Data dihapus", `visible(fn () => auth()->user()->isSuperAdmin())`.
  - `$noun` misalnya `'badan usaha'`, dipakai di judul modal ("Hapus badan usaha ini?", "Hapus 5 badan usaha?").
  - Delete dan bulkDelete hanya tampil untuk record yang tidak di tempat sampah; restore dan forceDelete hanya untuk yang di tempat sampah.

- [ ] **Step 1:** Tulis tes per menu, memakai data provider atas kelima resource:
  - `test_delete_action_moves_record_to_trash_with_usage_in_modal` — `mountAction('delete')` → `assertSee` ringkasan keterkaitan → `callAction` → `assertSoftDeleted`.
  - `test_non_super_admin_never_sees_trash_filter_restore_or_force_delete` — termasuk saat diberi izin `Restore:*`.
  - `test_super_admin_can_restore_and_force_delete_from_trash_view`
  - `test_bulk_force_delete_processes_free_rows_and_reports_blocked_ones` **(Review Focus #5)**
  - `test_trashed_record_detail_is_read_only_for_super_admin_and_404_for_others`
  - `test_user_delete_modal_requires_replacement_when_user_has_work`
- [ ] **Step 2:** Jalankan; diharapkan FAIL.
- [ ] **Step 3:** Implementasikan.
- [ ] **Step 4:** Jalankan seluruh suite sampai hijau.
- [ ] **Step 5:** Pint, lalu commit: `feat: aksi hapus, pulihkan, dan hapus permanen di lima menu`.

### Task 8: Dasbor, Riwayat Penghapusan, dan Panduan

**Files:**
- Create:
  - `app/Filament/Widgets/TrashOverview.php`
  - `app/Filament/Resources/TrashLogs/TrashLogResource.php` (+ `Pages/ListTrashLogs.php`, `Tables/TrashLogsTable.php`)
- Modify: `app/Filament/Pages/Panduan.php` (tambahkan panduan teks "Hapus & tempat sampah" tanpa video, mengikuti struktur panduan lain), `tests/Feature/PanduanPageTest.php`
- Test: `tests/Feature/TrashOverviewTest.php`

**Interfaces:**
- `TrashOverview`:
  - `canView()` = super admin dan ada data di tempat sampah;
  - satu Stat "Tempat sampah" bernilai total, dengan deskripsi rincian per menu ("2 dokumen, 1 badan usaha");
  - tautan ke menu dengan jumlah terbanyak, dengan filter `trashed` = "hanya data dihapus".
- `TrashLogResource`:
  - grup navigasi `Administrasi`, label "Riwayat Penghapusan";
  - hanya `viewAny` untuk super admin; tanpa create, edit, delete;
  - kolom: Waktu, Jenis data, Nama, Tindakan (badge), Oleh, Keterangan (file tertinggal);
  - filter: jenis data, tindakan; bisa dicari nama.
- Panduan: audiens "Super admin dan pemegang izin hapus". Langkah-langkah:
  1. Hapus dari daftar atau halaman ubah
  2. Data masuk tempat sampah
  3. Super admin buka filter Data dihapus
  4. Pulihkan atau Hapus permanen
  5. Hapus permanen ditolak bila masih dipakai
  6. Cek Riwayat Penghapusan

- [ ] **Step 1:** Tulis tes:
  - `test_trash_card_visible_only_to_super_admin_with_breakdown_and_link`
  - `test_trash_card_hidden_when_trash_is_empty`
  - `test_trash_log_page_lists_actions_for_super_admin_and_forbidden_for_others`
  - Perbarui jumlah panduan di `PanduanPageTest`.
- [ ] **Step 2:** Jalankan; diharapkan FAIL.
- [ ] **Step 3:** Implementasikan.
- [ ] **Step 4:** Jalankan seluruh suite sampai hijau.
- [ ] **Step 5:** Pint, lalu commit: `feat: kartu tempat sampah, riwayat penghapusan, dan panduan`.

### Task 9: Verifikasi menyeluruh di browser

- [ ] **Step 1:** Siapkan server terisolasi:
  - salin `database/database.sqlite` ke scratchpad;
  - `migrate` dan `db:seed --class=PermissionSeeder` pada salinan itu (dengan `$env:DB_DATABASE` terisi);
  - jalankan `php -S` dengan env aman (FILESYSTEM_DISK=local, WagHub/AWS ke `http://127.0.0.1:9`).
- [ ] **Step 2:** Sebagai user biasa yang punya izin hapus:
  - hapus 1 badan usaha yang masih dipakai, dan pastikan modal menyebut keterkaitannya;
  - hapus massal 2 jenis dokumen;
  - hapus 1 pengajuan berjalan.
  - Pastikan tidak ada filter, pulihkan, atau hapus permanen yang terlihat.
- [ ] **Step 3:** Sebagai super admin:
  - kartu dasbor tampil;
  - filter "hanya data dihapus" bekerja;
  - pulihkan 1 data;
  - hapus permanen yang diblokir menampilkan pesan dengan nama;
  - hapus permanen yang bebas berhasil;
  - Riwayat Penghapusan mencatat semuanya.
- [ ] **Step 4:** Hapus user yang punya tugas (pilih pengganti), lalu pastikan user itu tidak bisa login.
- [ ] **Step 5:** Ambil screenshot sebagai bukti, hentikan server, dan hapus file uji yang muncul di `storage/app/private`.
