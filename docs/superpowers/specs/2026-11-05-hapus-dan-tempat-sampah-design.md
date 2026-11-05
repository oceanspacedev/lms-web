# Rancangan: Hapus Data, Tempat Sampah, dan Hapus Permanen

Tanggal: 5 November 2026
Sumber: diskusi dengan pengembang (5 November 2026), setelah audit alur tanda tangan.

## 1. Latar belakang dan tujuan

Saat ini hampir tidak ada yang bisa dihapus. Badan Usaha, Jenis Dokumen, User, dan Pengajuan sama sekali tidak punya tombol hapus. Dokumen bisa dihapus ke "tempat sampah" (soft delete), tetapi tidak bisa dihapus permanen. Tidak ada hapus massal di tabel mana pun. Data yang salah input (badan usaha dobel, jenis dokumen keliru, user salah buat, pengajuan uji coba) akhirnya hanya bisa dinonaktifkan dan tetap mengotori daftar.

Permintaan pengguna, dengan kata-katanya sendiri:

> "kalau gw gabisa hapus samsek gimana coba, tapi kalau user yang apus dia masuk ke softdeletes dulu, dan hanya super admin yang bisa menentukan apakah itu akan langsung dihapus atau tidak"

Tujuan:

- Data bisa dihapus dari lima menu: **Dokumen, Badan Usaha, Jenis Dokumen, User, Pengajuan Dokumen**.
- Menghapus selalu memindahkan data ke **tempat sampah** dulu. Data hilang dari tampilan, tetapi masih ada dan bisa dipulihkan.
- **Hanya super admin** yang memutuskan nasib data di tempat sampah: **pulihkan** atau **hapus permanen**.
- Ada **hapus massal** (centang beberapa baris, lalu hapus).
- Riwayat tetap utuh: hapus permanen ditolak selama data masih dipakai data lain.

**Ukuran keberhasilan:** data yang salah input bisa dibereskan tanpa bantuan programmer. Tidak ada data yang hilang permanen tanpa keputusan super admin. Tidak ada dokumen atau pengajuan yang rusak karena data induknya hilang.

**Bukan tujuan (non-goal):**
- Pengosongan tempat sampah otomatis berdasarkan umur data. Data tetap di tempat sampah sampai super admin memutuskan.
- Hapus permanen untuk data yang masih dipakai, termasuk dengan memaksa atau bertingkat (cascade) ke data aktif.
- Fitur hapus untuk menu lain (Log Pengingat, Pengaturan WhatsApp, Peran).

**Anti-goal (gagal walaupun "jalan"):**
- Dokumen, pengajuan, atau riwayat aktivitas jadi rusak atau kosong karena induknya terhapus permanen.
- User yang sudah dihapus masih bisa login, masih menerima WhatsApp, atau masih muncul sebagai pilihan PIC atau pemeriksa.
- Pengingat WhatsApp tetap terkirim untuk data yang sudah dihapus.

## 2. Siapa melakukan apa

| Tindakan | Siapa | Izin |
|---|---|---|
| Hapus (ke tempat sampah), satu per satu | Siapa pun yang punya izin hapus untuk menu itu | `Delete:<Model>` |
| Hapus massal (ke tempat sampah) | Siapa pun yang punya izin hapus massal | `DeleteAny:<Model>` |
| Melihat tempat sampah | **Super admin saja** | — |
| Pulihkan (satu atau massal) | **Super admin saja** | — |
| Hapus permanen (satu atau massal) | **Super admin saja** | — |

- **Keputusan pengguna:** hanya super admin yang memutuskan pulihkan atau hapus permanen.
- **Keputusan pengembang:** pulihkan dan hapus permanen dikunci ke **peran super admin di dalam policy**, bukan hanya ke izin `Restore:*` dan `ForceDelete:*`. Izin itu tetap ada di daftar peran (dibuat otomatis oleh Filament Shield), tetapi memberikannya ke peran lain tidak membuka tombolnya.
  - Ini **mengubah perilaku yang ada**: saat ini Dokumen bisa dipulihkan oleh siapa pun yang punya `Restore:Document`. Setelah perubahan, hanya super admin.
- **Keputusan pengembang:** super admin yang menghapus juga masuk ke tempat sampah dulu, tidak langsung permanen. Alurnya sama untuk semua orang, dan salah klik tetap bisa dipulihkan.
- **Keputusan pengembang:** secara bawaan, izin hapus (`Delete:*` / `DeleteAny:*`) hanya dimiliki super admin, karena seeder memberi super admin semua izin. Super admin bisa memberikannya ke peran lain lewat menu Peran. Peran contoh dari `DummyAccountSeeder` tidak diubah.
- `PermissionSeeder` dan `config/filament-shield.php` saat ini hanya membuat izin `ViewAny`, `View`, `Create`, dan `Update` untuk User dan Pengajuan. Keduanya perlu diberi izin `Delete` dan `DeleteAny`. Izin `Restore*` dan `ForceDelete*` boleh ikut dibuat supaya seragam dengan menu lain, tetapi tidak berpengaruh (lihat poin di atas).

## 3. Pengalaman pengguna

### 3.1 Menghapus satu data

- Tombol **Hapus** ada di halaman ubah/detail tiap menu, dan sebagai ikon di baris tabel. Warna dan ikon sama di kelima menu: Hapus abu-abu dengan ikon tempat sampah (masih bisa dipulihkan), tooltip "Hapus {jenis data}"; Pulihkan abu-abu dengan ikon panah kembali, tooltip "Pulihkan {jenis data}"; Hapus permanen merah dengan ikon `heroicon-o-x-circle`, tooltip "Hapus permanen {jenis data}". Tombol modalnya "Ya, hapus" (merah), "Ya, pulihkan" (utama), dan "Ya, hapus permanen" (merah).
- Selalu ada **modal konfirmasi**. Isinya:
  - Judul, misalnya "Hapus badan usaha ini?"
  - Penjelasan dengan sebutan jenis datanya, misalnya "Badan usaha dipindahkan ke tempat sampah dan hilang dari daftar. Super admin bisa memulihkannya atau menghapusnya permanen."
  - Pengajuan menambahkan "Untuk menghentikan pengajuan yang sah, pakai Batalkan — riwayatnya tetap tercatat. Hapus hanya untuk data uji atau salah input." User menambahkan "Staf keluar atau pindah? Pakai Nonaktifkan. Hapus hanya untuk user yang salah dibuat." (juga di modal hapus massal).
  - **Keterkaitan**, bila ada (keputusan pengguna: data yang masih terhubung tetap boleh dihapus, dengan konfirmasi). Contoh: "Badan usaha ini masih dipakai **12 dokumen** dan **3 pengajuan**. Dokumen dan pengajuan itu tidak ikut terhapus, dan nama badan usaha tetap tampil di sana dengan tanda (dihapus)."
  - Tombol: "Ya, hapus" / "Kembali".
- Setelah berhasil: notifikasi "{Jenis data} dipindahkan ke tempat sampah" (misalnya "Badan usaha dipindahkan ke tempat sampah"), lalu kembali ke daftar. Pulihkan dan hapus permanen memakai "{Jenis data} dipulihkan" dan "{Jenis data} dihapus permanen".

### 3.2 Hapus massal

- Di tiap tabel ada kotak centang per baris. Setelah memilih, muncul aksi **Hapus terpilih**.
- Modal konfirmasi menyebut jumlah baris yang relevan ("Hapus 5 badan usaha?") dan ringkasan keterkaitan ("2 di antaranya masih dipakai dokumen atau pengajuan"). Baris terpilih yang tidak relevan (misalnya Hapus terpilih pada baris yang sudah di tempat sampah) disebut di judul: "Hapus 3 badan usaha? (2 baris lain dilewati.)".
- Bila tidak satu pun baris terpilih relevan, modal tidak dibuka; muncul peringatan "Tidak ada {jenis data} di pilihan ini yang bisa {dihapus|dipulihkan|dihapus permanen}." Hasil tanpa satu pun yang diproses selalu berupa peringatan, tidak pernah notifikasi sukses.
- Baris yang tidak boleh dihapus (lihat aturan per menu di §4) dilewati. Notifikasi hasil menyebut "4 badan usaha dipindahkan ke tempat sampah, 1 dilewati: <nama> (<alasan>)".

### 3.3 Tempat sampah (super admin)

- **Keputusan pengembang:** tempat sampah dilihat per menu, lewat filter **"Data dihapus"** di tabel masing-masing. Pilihannya: tanpa data dihapus (bawaan) / dengan data dihapus / hanya data dihapus. Pola ini sudah dipakai menu Dokumen saat ini.
  - Filter ini hanya terlihat oleh super admin.
- Baris yang dihapus ditandai jelas: kolom status berisi badge abu-abu "Dihapus" (Badan Usaha, Jenis Dokumen, User, Dokumen), dan kolom "Dihapus" berisi teks "{d/m/Y H:i} WIB oleh {nama}" tanpa badge kedua. Pengajuan tetap menampilkan status alurnya, tetapi kolom Keterangan kosong ("—").
- Baris di tempat sampah tidak punya aksi Ubah (pensil); gantinya ikon mata "Lihat {jenis data}" ke halaman detail yang hanya-baca.
- Bila filter "Hanya data dihapus" tidak menemukan apa pun: judul "Tempat sampah kosong", keterangan "Tidak ada {jenis data} di tempat sampah."
- Di layar sempit (di bawah `md`) kolom sekunder disembunyikan; yang tetap tampil hanya nama/judul, status, dan aksi.
- **Keputusan pengembang:** di dasbor super admin ada bagian **"Tempat sampah"** berisi satu kartu per menu yang punya isi tempat sampah: label nama menu (misalnya "Dokumen"), angka jumlahnya, dan keterangan "menunggu keputusan". Tiap kartu tautan ke tabel menu itu yang sudah tersaring "hanya data dihapus". Bagian ini disembunyikan bila tempat sampah kosong.
- Aksi untuk baris yang dihapus, satu per satu atau massal:
  - **Pulihkan**: modal konfirmasi, lalu data kembali seperti semula.
  - **Hapus permanen**: modal konfirmasi berwarna merah dengan teks "Tindakan ini tidak bisa dibatalkan."
- Halaman detail data yang dihapus bisa dibuka super admin dalam mode hanya-baca, dengan tombol Pulihkan dan Hapus permanen. Pengguna lain mendapat 404.
  - Judulnya "Detail {nama}" dan breadcrumb-nya "Detail" (bukan "Ubah"). Di atasnya ada pemberitahuan: "Data ini ada di tempat sampah — dihapus {d/m/Y H:i} WIB oleh {nama}. Pulihkan untuk mengubahnya." Halaman detail Dokumen memakai kalimat yang sama.
  - Tombol Kembali menuju daftar dengan filter "Hanya data dihapus". Setelah Hapus permanen dari halaman ini, pengguna dibawa ke daftar yang sama. Setelah Pulihkan, pengguna tetap di halaman itu, yang kini bisa diubah dan berjudul biasa.

### 3.4 Hapus permanen ditolak bila masih dipakai

- **Keputusan pengguna:** hapus permanen **ditolak** selama data masih dipakai data lain yang **tidak** ada di tempat sampah. Pesannya menyebut dipakai di mana. Contoh: "Tidak bisa dihapus permanen: masih dipakai 2 dokumen (PKS Sewa Gudang, Kontrak Vendor) dan 1 pengajuan (#14). Hapus dulu data itu (pindahkan ke tempat sampah), lalu coba lagi."
  - Pesan per jenis data: Badan Usaha dan Jenis Dokumen memakai contoh di atas. Dokumen: "Tidak bisa dihapus permanen: masih dipakai pengajuan #14, #15. Hapus dulu pengajuan itu, lalu coba lagi." User: "Tidak bisa dihapus permanen: user ini masih tercatat di {daftar}. User yang punya riwayat cukup dibiarkan di tempat sampah — ia tetap tidak bisa masuk dan tidak menerima WhatsApp."
  - Keterkaitan dihitung saat modal hapus permanen dibuka. Bila ditolak, modal langsung menampilkan pesan penolakan dan tombol "Ya, hapus permanen" dinonaktifkan; pemeriksaan di server tetap berlaku.
  - Daftar contoh dibatasi sekitar 5 nama, ditambah "dan N lainnya".
- **Keputusan pengguna:** data terkait yang **ikut ada di tempat sampah** tidak dihitung sebagai "dipakai". Pengecualiannya User (§4.4).
  - **Keputusan pengembang:** data terkait yang di tempat sampah itu **ikut dihapus permanen**. Ini tidak terhindarkan: foreign key database bersifat `restrict`, sehingga induk tidak bisa hilang selama anak (meskipun ada di tempat sampah) masih menunjuknya.
  - Modal konfirmasi wajib menyebutnya, misalnya "3 dokumen di tempat sampah yang memakai badan usaha ini juga akan dihapus permanen beserta semua versi dan filenya." Bagian "beserta semua versi dan filenya" hanya muncul bila ada dokumen yang ikut terhapus.
- Pada hapus permanen massal, data yang ditolak dilewati dan alasannya dilaporkan di notifikasi hasil. Data lain tetap diproses. Modalnya sudah menyebut berapa yang akan ditolak, misalnya "2 dari 5 akan ditolak karena masih dipakai."

### 3.5 Riwayat penghapusan

- Setiap **hapus, pulihkan, dan hapus permanen** tercatat: jenis data, nama/judul saat itu, tindakan, siapa, dan kapan. Pengajuan dicatat sebagai "#{nomor} · {judul}" (juga di notifikasi dan pesan penolakan). Kolom "Oleh" memakai nama tampilan user, jadi pelaku yang ada di tempat sampah bertanda "(dihapus)"; bila user itu sudah dihapus permanen, nama yang tersimpan di log yang tampil.
- Kolom Keterangan: induk yang dihapus permanen beserta anaknya menyebut "Ikut menghapus permanen: 3 dokumen, 1 pengajuan."; anak yang ikut terhapus menyebut "Ikut terhapus bersama {jenis data} "{nama}"."; file yang gagal dihapus menyebut "File tertinggal di penyimpanan: {nama file}; hubungi admin IT." (nama file saja, tanpa path).
- Untuk Dokumen, catatan ini tetap juga masuk ke riwayat aktivitas dokumen (`deleted` / `restored`), seperti sekarang.
- **Keputusan pengembang:** karena data yang dihapus permanen sudah tidak ada, riwayat disimpan di tabel baru tersendiri (misalnya `trash_logs`). Riwayat ini tampil di halaman baca-saja **Riwayat Penghapusan** di grup Administrasi, khusus super admin. Isinya bisa dicari dan disaring per menu dan per tindakan.

## 4. Aturan per menu

### 4.1 Dokumen

- Soft delete sudah ada: tombol **Hapus Dokumen**, filter, Pulihkan, dan pengingat yang otomatis berhenti dan jalan lagi saat dipulihkan. Semua ini dipertahankan.
- Perubahan:
  - Pulihkan → **super admin saja**.
  - Tambah **hapus massal** dan **hapus permanen**.
- **Hapus permanen** menghapus:
  - semua versi dokumen beserta **filenya** di disk masing-masing (kolom `disk`, biasanya S3);
  - riwayat aktivitas dokumen;
  - log pengingat (`reminder_logs`).
- **Keputusan pengguna:** saat masuk tempat sampah, file **tidak** disentuh. File ikut terhapus hanya saat hapus permanen.
  - Model `DocumentVersion` dan `DocumentActivity` sengaja melarang penghapusan (hook `deleting` melempar exception). Hapus permanen harus melewatinya secara sengaja, terkendali, dan hanya dari satu layanan (service) khusus.
  - File dihapus **setelah** transaksi database berhasil. Kalau penghapusan file gagal, data database tetap terhapus, dan kegagalannya dicatat di riwayat penghapusan beserta lokasi file yang tertinggal.
- **Dipakai oleh:** pengajuan yang menunjuk dokumen ini (`document_requests.document_id`, yaitu pengajuan yang sudah Selesai).
  - Bila pengajuan itu aktif (tidak di tempat sampah), hapus permanen ditolak.
  - Bila pengajuan itu juga di tempat sampah, pengajuan itu ikut dihapus permanen (sesuai §3.4).
- **Keputusan pengembang:** menghapus dokumen yang punya pengajuan Selesai tetap diizinkan (soft). Modal menyebut "Dokumen ini hasil pengajuan #14."

### 4.2 Badan Usaha

- **Dipakai oleh:** dokumen (`documents.company_id`) dan pengajuan (`document_requests.company_id`).
- **Saat di tempat sampah:**
  - hilang dari semua pilihan: form dokumen, form pengajuan internal, form publik `/pengajuan`, filter tabel, template dan validasi impor Excel, aturan validasi `exists`;
  - dokumen dan pengajuan lama tetap menampilkan namanya dengan tanda **"(dihapus)"**.
- **Nama ganda:** nama badan usaha unik di database. Membuat badan usaha baru dengan nama yang sama dengan yang ada di tempat sampah akan ditolak dengan pesan "Nama ini dipakai badan usaha di tempat sampah. Pulihkan data itu (super admin) atau pakai nama lain."
  - **Keputusan pengembang:** indeks unik di database tidak diubah, supaya pemulihan tidak pernah bentrok.
- **Data aktif yang masih memakai badan usaha di tempat sampah tetap bisa jalan.** **Keputusan pengembang:** mengikuti pola "pilih yang aktif, atau pertahankan yang sudah terpasang" (`activeOrKeep`) yang sudah dipakai untuk PIC.
  - Dokumen dan pengajuan yang **sudah** memakai badan usaha itu tetap bisa diubah dan disimpan tanpa menggantinya. Pilihan dropdown-nya tetap memuat nilai lama dengan tanda "(dihapus)".
  - Pengajuan yang masih berjalan tetap bisa diselesaikan (Fisik Diterima). Dokumen arsip baru akan memakai badan usaha yang sama.
  - Konsekuensinya, hapus permanen badan usaha itu ditolak selama dokumen tersebut aktif, sesuai §3.4.
  - Validasi `exists` di jalur arsip dan simpan menerima nilai yang sama dengan nilai yang sudah tersimpan, walaupun ada di tempat sampah atau nonaktif. Nilai **baru** tetap harus aktif dan tidak di tempat sampah.
  - Ini sekaligus membereskan masalah serupa yang sudah ada untuk badan usaha **nonaktif**: pengajuan berjalan yang badan usahanya dinonaktifkan saat ini tidak bisa diselesaikan.
  - Modal hapus menyebut pengajuan yang masih berjalan secara khusus, misalnya "3 pengajuan masih berjalan dan tetap bisa diselesaikan."

### 4.3 Jenis Dokumen

- **Dipakai oleh:** dokumen dan pengajuan (`document_type_id`).
- Jenis dokumen sendiri menunjuk ke user (`request_reviewer_id`, `request_approver_id`, `request_pic_id`). Ini tidak menghalangi penghapusan jenis dokumen.
- Template pengingat yang terkait (`reminderTemplate`) ikut terhapus permanen bersama jenis dokumen. Tidak dihitung sebagai "dipakai".
- Perilaku di tempat sampah, aturan nama ganda, dan aturan "data aktif tetap bisa jalan" sama seperti Badan Usaha.

### 4.4 User

- **Menghapus user = menonaktifkan + memindahkan ke tempat sampah.** Modal hapus memakai alur nonaktifkan yang sudah ada (`UserDeactivator`):
  - menampilkan tugas yang harus dialihkan (PIC dokumen, pemeriksa/PIC pengajuan terbuka, pemeriksa/PIC di jenis dokumen);
  - mewajibkan memilih pengganti;
  - membatalkan notifikasi yang antre;
  - mengakhiri sesi login.
  - Setelah itu user dipindahkan ke tempat sampah.
- Larangan yang sudah berlaku untuk nonaktifkan juga berlaku untuk hapus:
  - tidak bisa menghapus diri sendiri;
  - non-super-admin tidak bisa menghapus super admin;
  - super admin aktif terakhir tidak bisa dihapus.
- **Saat di tempat sampah:**
  - tidak bisa login (lewat password, OTP WhatsApp, maupun OTP email);
  - tidak menerima WhatsApp;
  - tidak muncul di pilihan PIC, pemeriksa, atau penerima;
  - nama di riwayat lama tetap tampil, dengan tanda "(dihapus)" di relasi yang ditampilkan.
- **Pulihkan:** user kembali dalam keadaan **nonaktif**. Super admin mengaktifkannya kembali lewat tombol Aktifkan kembali yang sudah ada. Notifikasinya "User dipulihkan dalam keadaan nonaktif" dengan isi "Aktifkan kembali lewat tombol Aktifkan kembali di halaman user." dan tombol "Buka user". Modal hapus user menyebut "Peran dan izinnya tetap disimpan bila user dipulihkan."
- Filter Status "Aktif" (bawaan) di daftar User memuat user di tempat sampah hanya bila filter Data dihapus "Hanya data dihapus"; pada "Dengan data dihapus", user di tempat sampah tidak ikut disebut aktif. **Keputusan pengembang:** ini supaya tugas yang sudah dialihkan tidak kacau dan pemulihan tidak langsung memberi akses login.
- **Hapus massal user:** hanya untuk user yang tidak punya tugas yang perlu dialihkan. User yang punya tugas dilewati, dengan alasan "punya tugas yang harus dialihkan, hapus satu per satu". **Keputusan pengembang:** memilih pengganti untuk banyak user sekaligus terlalu rawan salah.
- **Keputusan pengembang:** pengalihan tugas saat menghapus (dan menonaktifkan) juga mencakup pengajuan dan jenis dokumen **di tempat sampah**, sama seperti dokumen di tempat sampah sekarang. Dengan begitu, data yang kelak dipulihkan tidak menunjuk ke user yang sudah dihapus.
- **Hapus permanen user tidak berantai.** **Keputusan pengembang:** user tidak memakai aturan "data terkait di tempat sampah ikut dihapus permanen" (§3.4), supaya super admin tidak kehilangan file dokumen tanpa sengaja.
  - Referensi yang **boleh kosong** dikosongkan: `document_types.request_*_id`, `document_requests.reviewer_id`, `approver_id`.
  - Referensi **wajib** yang masih ada, termasuk di data yang di tempat sampah, membuat hapus permanen ditolak. Contohnya: PIC dokumen, pengunggah versi, pengaju, riwayat impor.
- **Dipakai oleh** (bila ada salah satu, termasuk di tempat sampah, hapus permanen ditolak):
  - dokumen (PIC);
  - versi dokumen (pengunggah);
  - pengajuan (pengaju, pemeriksa, penyetuju, PIC);
  - jenis dokumen (pemeriksa, penyetuju, PIC);
  - riwayat impor.
  - Akibatnya, praktis hanya user yang belum pernah melakukan apa pun (misalnya salah buat) yang bisa dihapus permanen. Ini disengaja, supaya riwayat tetap utuh.
- Referensi yang **tidak menghalangi** dan diurus otomatis saat hapus permanen:
  - di-null-kan otomatis oleh database (`nullOnDelete`): aktivitas dokumen, alasan terlambat, pengirim ulang, pesan uji WhatsApp, OTP, `deactivated_by`;
  - dibersihkan: sesi login, peran dan izin (tabel spatie).
- **Email ganda:** aturannya sama seperti nama badan usaha (§4.2). Pesannya: "Email ini dipakai user di tempat sampah. Pulihkan user itu (super admin) atau pakai email lain."

### 4.5 Pengajuan Dokumen

- Boleh dihapus di **status apa pun**. **Keputusan pengembang:** termasuk pengajuan yang sedang berjalan, karena kasus utamanya pengajuan uji coba atau salah input.
  - Modal memberi peringatan bila status masih berjalan: "Pengajuan ini masih berjalan (Menunggu Dokumen Fisik). Pengingat terkait pengajuan ini akan berhenti." Di modal hapus massal: "{n} di antaranya masih berjalan; pengingat terkait pengajuan itu akan berhenti."
- **Saat di tempat sampah:**
  - hilang dari daftar, tab "Perlu tindakan saya", dan kartu dasbor;
  - pengingat (pemeriksaan, scan, keterlambatan) berhenti, dan notifikasi yang antre dibatalkan;
  - **tautan status publik** (`/pengajuan/{token}`), unduh berkas, unggah scan, alasan terlambat, dan cek status (`/pengajuan/cek`) menjawab seperti pengajuan tidak ditemukan;
  - **tidak ada WhatsApp ke pengaju** soal penghapusan. **Keputusan pengembang:** penghapusan dianggap urusan internal.
- **Pulihkan:** kembali ke status semula. **Keputusan pengembang:** waktu di tempat sampah **tetap dihitung**. Batas penyerahan dan hari terlambat tidak digeser, karena dokumen fisiknya memang belum diserahkan.
  - Supaya tidak ada banjir pesan saat pulih, tahap pengingat yang terlewat selama di tempat sampah (misalnya eskalasi hari ke-14 dan ke-28) **dianggap sudah lewat** dan tidak dikirim. Hanya tahap yang jatuh **setelah** pemulihan yang dikirim.
  - Notifikasi status yang dibatalkan saat penghapusan tidak dihidupkan lagi.
  - Pengingat "pengajuan menunggu terlalu lama" juga tidak langsung menyusul. Hitungannya mengikuti jadwal berikutnya.
- **Hapus permanen:**
  - menghapus berkas pengajuan di disk `local`: lampiran, draft, scan;
  - notifikasi dan alasan terlambat ikut terhapus otomatis (`cascade`);
  - dokumen arsip hasil pengajuan **tidak** ikut terhapus.
- **Dipakai oleh:** tidak ada. Pengajuan tidak pernah menghalangi hapus permanennya sendiri.

## 5. Dampak ke bagian lain

- Begitu Badan Usaha, Jenis Dokumen, User, dan Pengajuan memakai soft delete, relasi `belongsTo` ke induk yang dihapus akan bernilai null, kecuali memakai `withTrashed()`. Akibatnya nama induk bisa hilang dari tampilan.
  - Semua tampilan yang menunjukkan induk harus tetap menampilkan namanya: tabel, infolist, ekspor Excel, isi pesan WhatsApp, dan penamaan file arsip (`StorageFileName::companyName`).
- Aturan validasi `Rule::exists(...)` dan `exists:users,...` tidak mengenal soft delete, sehingga harus diberi syarat `deleted_at` null. Lokasinya ada di Document, DocumentRequest, dan DocumentForm (lihat lampiran).
- Impor Excel memakai `Company::all()` / `DocumentType::all()` / semua user. Setelah soft delete aktif, data di tempat sampah otomatis tidak terbaca, dan pesan galatnya harus tetap jelas ("badan usaha tidak ditemukan").
- `UserDeactivator` dan beberapa tempat lain sudah memakai `withTrashed()` untuk dokumen. Perilaku itu dipertahankan.
- Tes yang saat ini memastikan "tidak bisa dihapus" (Badan Usaha, Jenis Dokumen, hapus permanen Dokumen) diubah mengikuti aturan baru.
- Panduan: tambah satu panduan baru **"Hapus & tempat sampah"**, cukup teks dan langkah (paling banyak 8): hapus dari daftar atau halaman detail, hapus beberapa sekaligus, menghapus user (dinonaktifkan dan tugasnya dialihkan; dilewati pada hapus massal bila punya tugas; untuk staf keluar pakai Nonaktifkan), masuk tempat sampah, filter Data dihapus, pulihkan atau hapus permanen, hapus permanen ditolak (dengan saran "Hapus dulu data itu (pindahkan ke tempat sampah), lalu coba lagi."), dan Riwayat Penghapusan. **Keputusan pengembang:** videonya menyusul bila diminta.

## 6. Yang diserahkan ke pembangun

- Bentuk tabel `trash_logs` dan halaman Riwayat Penghapusan, selama memenuhi §3.5.
- Cara menandai "(dihapus)" di tiap tampilan, selama konsisten.
- Struktur kode layanan hapus permanen (satu service per model atau satu service umum dengan aturan per model), selama hanya ada satu jalur hapus permanen per model.
- Urutan penghapusan agar sesuai foreign key yang `restrict`. Contoh untuk Dokumen: `reminder_logs` → aktivitas → `current_version_id` = null → versi → file.
- Penempatan persis tombol dan ikon, selama mengikuti pola Filament yang sudah dipakai aplikasi ini.

## Lampiran: titik kode yang terdampak (hasil pemetaan)

- **SoftDeletes saat ini hanya ada di `Document`.** Migrasi `deleted_at` diperlukan untuk `companies`, `document_types`, `users`, dan `document_requests`.
- **Foreign key `restrict`:**
  - ke `companies` / `document_types`: dari `documents` dan `document_requests`;
  - ke `users`: dari `documents.pic_user_id`, `document_versions.uploaded_by`, `document_requests` (requester / reviewer / approver / pic), `document_types` (request_*), dan `document_imports.user_id`;
  - ke `documents`: dari `document_versions`, `document_activities`, `reminder_logs` (lewat versi), dan `document_requests.document_id`.
- **Unik:** `companies.name`, `document_types.name`, `users.email`, `document_requests.public_token`.
- **Validasi tanpa soft delete:** `Document.php:218-223`, `DocumentRequest.php:478,530-532,788`, `DocumentForm.php:27,32`; aturan unik di `CompanyForm.php:32`, `DocumentTypeForm.php:28`, `UserForm.php:21`.
- **Izin:** `PermissionSeeder.php:31-40`, `config/filament-shield.php:191-197`.
- **Policy:** `UserPolicy` dan `DocumentRequestPolicy` (`delete` saat ini selalu false).
- **Tes yang terdampak:**
  - `CompanyResourceTest:183`, `DocumentTypeResourceTest:150`, `DocumentTrashTest:64-195`, `DocumentResourceTest:223`;
  - `PublicRequestSigningTest:121` (memakai ulang `public_token` setelah `delete()`).
