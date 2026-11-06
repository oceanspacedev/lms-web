# Rancangan: Alur Draft, Tanda Tangan, dan Penyerahan Dokumen Fisik

Tanggal: 4 November 2026
Sumber: catatan briefing hasil demo LMS (3 November 2026) dan diskusi lanjutan dengan pengembang.

## 1. Latar belakang dan tujuan

Setelah pengajuan disetujui, aplikasi saat ini hanya berhenti di status **Menunggu Tanda Tangan** sampai legal menekan **Unggah Dokumen Final**. Tidak ada catatan apa pun di antaranya: draft sudah keluar atau belum, sudah ditandatangani atau belum, dan dokumen fisik aslinya masih di pemohon atau sudah di legal. Akibatnya tim legal harus menanyakan dan mengejar satu per satu.

Tujuan perubahan ini:

- Draft dikeluarkan dari aplikasi, lalu pemohon langsung diberi tahu.
- Pemohon mengunggah scan dokumen yang sudah ditandatangani dan dicap.
- Legal bisa memantau dari satu tempat dokumen fisik mana yang **sudah diterima** dan mana yang **masih di pemohon**.
- Kalau penyerahan fisik lewat dari 1 minggu, sistem menandai **Terlambat**, meminta **alasan** dari pemohon, dan mengirim pengingat bertahap. Dengan begitu legal tidak perlu memantau setiap saat.

**Ukuran keberhasilan:** legal tidak lagi perlu menanyakan status dokumen satu per satu. Cukup buka satu daftar untuk melihat mana yang sudah diterima, mana yang masih ditunggu, mana yang terlambat, dan apa alasannya.

## 2. Orang yang terlibat

| Peran | Siapa (saat ini) | Tugas |
|---|---|---|
| **Pemohon** | Karyawan yang login di aplikasi, **atau** pengaju lewat form publik `/pengajuan` tanpa login | Mengajukan, mengurus tanda tangan dan cap di luar aplikasi, mengunggah scan bertanda tangan, menyerahkan dokumen fisik, menulis alasan bila terlambat |
| **Staff Legal (PIC)** | Rama | Memeriksa pengajuan, minta revisi atau menolak, mengeluarkan draft, mengunggah scan atas nama pemohon bila perlu, menandai dokumen fisik sudah diterima |
| **Koordinator Legal (PIC)** | Pak Agus | Memantau semuanya, dan menerima notifikasi eskalasi di minggu ke-4 |

Di briefing, "PIC" berarti **penanggung jawab dari sisi legal**, yaitu Staff Legal dan Koordinator Legal, bukan atasan pemohon.

**Keputusan pengembang:** peran diatur lewat **role dan izin** di menu Hak Akses, bukan nama orang. Kalau staff legal bertambah atau berganti, cukup atur role user di menu User. Cara ini sesuai permintaan bahwa yang ikut serta bisa berubah-ubah.

- **Staff Legal** = user yang punya izin **Periksa pengajuan** (`Review:DocumentRequest`, sudah ada). Aksi legal di alur ini adalah Keluarkan/Ganti Draft, Unggah Scan atas nama pemohon, Fisik Diterima, dan Batalkan.
  - **Keputusan pengembang:** aksi-aksi itu boleh dilakukan **semua** pemegang izin ini, bukan hanya pemeriksa yang ditugaskan (`reviewer_id`). Tujuannya supaya pekerjaan tidak macet kalau Rama berhalangan. Pembatasan per pemeriksa yang ada sekarang untuk tahap Diperiksa tidak diubah.
  - **Fisik Diterima** membuat dokumen arsip, jadi tetap mensyaratkan izin **Tambah Dokumen** (`Create:Document`), sama seperti **Unggah Dokumen Final** sekarang. Role Staff Legal harus punya kedua izin itu.
- **Koordinator Legal** = user yang punya izin baru, misalnya **"Terima eskalasi pengajuan"**. Izin ini dipakai untuk menentukan penerima notifikasi minggu ke-4.
- **Keputusan pengembang, izin lama "Setujui pengajuan"** (`Approve:DocumentRequest`):
  - Izin ini tidak dipakai lagi di alur.
  - Saat fitur dipasang, semua user yang sekarang memegangnya, baik langsung maupun lewat role, otomatis diberi **"Terima eskalasi pengajuan"** dan **"Lihat semua pengajuan"** (`ViewAll:DocumentRequest`). Dengan begitu koordinator langsung menerima eskalasi dan tetap bisa melihat semua pengajuan.
  - Izin lamanya boleh dihapus dari daftar izin setelah itu.

Kalau sebuah pengajuan sudah punya pemeriksa tertentu (`reviewer_id`, diisi dari Jenis Dokumen atau dari penugasan otomatis yang sudah ada), notifikasi "ke Staff Legal" dikirim ke orang itu. Kalau belum ada, dikirim ke semua user yang punya izin Periksa pengajuan dan nomor WhatsApp valid.

## 3. Alur baru

```
Draf ─► Diperiksa ─┬─► Perlu Revisi ─► (pemohon kirim ulang) ─► Diperiksa
                   ├─► Ditolak (berhenti)
                   └─► Menunggu Tanda Tangan ─► Menunggu Dokumen Fisik ─► Selesai
                        (draft sudah keluar)    (scan sudah diunggah;
                              │                  "Terlambat" bila > 7 hari)
                              │                         │
                              └──────────┬──────────────┘
                                         ▼
                                    Dibatalkan (berhenti)
```

### 3.1 Pengajuan dan pemeriksaan (tidak berubah)

- Pemohon mengajukan lewat dua jalur yang sudah ada: dari dalam aplikasi atau form publik.
- Staff Legal memeriksa, lalu bisa **Revisi** (dengan catatan) atau **Tolak** (dengan alasan). Perilakunya sama seperti sekarang.

### 3.2 Tahap persetujuan dihapus

- Status **Menunggu Persetujuan** (`review`) dan tombol **Lanjutkan** dan **Setujui** dihapus dari alur.
- **Draft keluar berarti pengajuan sudah disetujui.** Koordinasi antara Staff Legal dan Koordinator terjadi di luar sistem, dan aplikasi tidak perlu mencatatnya.
- Kolom **Pemberi persetujuan** di form Jenis Dokumen tidak dipakai lagi dan disembunyikan. Kolom database boleh tetap ada.

### 3.3 Mengeluarkan draft

- Di halaman pengajuan berstatus **Diperiksa**, Staff Legal punya aksi **Keluarkan Draft** untuk mengunggah satu file draft (PDF, DOC, DOCX, JPG, atau PNG; ketentuan ukuran sama dengan lampiran lain).
- Status berubah ke **Menunggu Tanda Tangan**.
- Pemohon mendapat WhatsApp **"dokumen sudah jadi"** beserta link ke halaman pengajuannya:
  - pemohon publik: link status bertoken (`/pengajuan/{token}`), yang sudah dikirim selama ini;
  - pemohon internal: link halaman pengajuan di admin.
- Pemohon bisa **mengunduh draft** dari halaman itu.
- Selama scan bertanda tangan belum diunggah, Staff Legal boleh **mengganti draft**, misalnya karena ada salah ketik. Pemohon mendapat WhatsApp lagi bahwa draft diperbarui.
- **Pengingat kalau scan tak kunjung diunggah** (diputuskan pengguna: perlu ada pengingat):
  - Lewat **7 hari kalender** sejak draft pertama dikeluarkan dan scan belum diunggah, pemohon mendapat **satu** WhatsApp pengingat untuk mengunggah scan bertanda tangan.
  - Di daftar pantauan, pengajuan ini ditandai **"Belum unggah scan > 7 hari"**.
  - **Keputusan pengembang:** pengingatnya cukup sekali, tidak ada eskalasi bertingkat, dan tidak memakai tanda **Terlambat**. Tanda Terlambat tetap khusus untuk penyerahan fisik (§3.5). Mengganti draft tidak mengulang hitungan 7 hari ini.

### 3.4 Mengunggah scan bertanda tangan

- Pemohon mencetak, menandatangani, dan memberi cap **di luar aplikasi**.
- Pemohon mengunggah scan-nya dari:
  - **pemohon publik:** halaman status bertoken. Halaman cek status tanpa token (`/pengajuan/cek`) tetap hanya untuk melihat status;
  - **pemohon internal:** halaman pengajuannya di admin.
- **Staff Legal juga bisa mengunggah scan atas nama pemohon**, misalnya kalau pemohon mengirimnya lewat chat.
- Setelah scan diunggah:
  - status berubah ke **Menunggu Dokumen Fisik**;
  - **hitung mundur 7 hari dimulai** sejak waktu unggah scan pertama;
  - Staff Legal mendapat WhatsApp bahwa scan sudah masuk dan dokumen fisik akan diserahkan.
- Scan boleh diganti (oleh pemohon atau Staff Legal) selama dokumen fisik belum diterima. Mengganti scan **tidak** mengulang hitung mundur 7 hari. Setiap penggantian tercatat di riwayat.

### 3.5 Batas waktu penyerahan fisik dan pengingat

Batas waktunya **7 hari kalender** sejak scan pertama diunggah, dihitung dengan zona waktu Asia/Jakarta. Pengingat dikirim oleh penjadwal harian yang sudah ada (jam 08:00 WIB).

| Sejak scan diunggah | Yang terjadi | Penerima WhatsApp |
|---|---|---|
| Hari 0–7 | Batas waktu normal. Halaman pengajuan menampilkan sisa hari. | — |
| **Lewat hari ke-7** | Pengajuan ditandai **Terlambat**. Pemohon diminta menulis alasan keterlambatan. | Pemohon |
| **Lewat hari ke-14** | Pengingat kedua. Pemohon bisa menulis alasan baru. | Pemohon dan Staff Legal |
| **Lewat hari ke-28** | Pengingat terakhir. Pemohon bisa menulis alasan baru. | Pemohon, Staff Legal, dan Koordinator Legal |
| Setelah itu | Tidak ada pengingat otomatis lagi. Tanda **Terlambat** tetap tampil sampai fisik diterima. | — |

- **Terlambat bukan status tersendiri**, melainkan tanda pada pengajuan berstatus Menunggu Dokumen Fisik yang sudah lewat 7 hari. Statusnya tetap Menunggu Dokumen Fisik.
- Setiap pengingat dikirim **sekali** per tahap. Kalau penjadwal sempat mati dan baru jalan lagi beberapa hari kemudian, yang dikirim hanya pengingat untuk tahap terakhir yang sudah tercapai, bukan semua tahap yang terlewat.
- Isi pesan setiap pengingat bisa diubah di **Pengaturan WhatsApp**, sama seperti template pengajuan lain. Teks bawaannya ditulis oleh pengembang.
- **Keputusan pengembang:** jadwal 7, 14, dan 28 hari **tetap** (tidak bisa diatur), dan pengingat keterlambatan **selalu aktif**. Pengingat ini tidak ikut sakelar "Pengingat pengajuan" yang sudah ada, karena sakelar itu untuk pengajuan yang lama menunggu pemeriksaan. Pengingat "pengajuan menunggu pemeriksaan" yang sudah ada tetap berjalan untuk status Diperiksa saja.

### 3.6 Alasan keterlambatan

- Alasan **ditulis oleh pemohon**, dari halaman yang sama dengan tempat dia mengunggah scan (halaman bertoken atau halaman admin).
- Kolom alasan baru muncul setelah pengajuan terlambat. Kolom ini tampil menonjol dengan pesan bahwa alasan wajib diisi.
- "Wajib" berarti **ditagih dan terlihat**, bukan memblokir: legal bisa melihat pengajuan yang terlambat tapi **belum ada alasannya**.
- Pemohon boleh menulis alasan **lebih dari sekali**, misalnya setelah pengingat minggu ke-2 dan ke-4. Semua alasan disimpan sebagai **riwayat** lengkap dengan waktunya, dan tidak saling menimpa.
- Alasan **tidak perlu disetujui** dan **tidak memperpanjang** batas waktu. Alasan hanya dicatat.
- Staff Legal bisa membaca semua alasan di halaman pengajuan dan di daftar pantauan.

### 3.7 Dokumen fisik diterima, pengajuan selesai

- Saat dokumen asli sampai, Staff Legal menekan aksi **Fisik Diterima** di halaman pengajuan.
- Aksi ini menggantikan **Unggah Dokumen Final** yang sekarang. **Keputusan pengguna (6 Nov 2026):** **Fisik Diterima** cukup konfirmasi tanpa isian, dan tombolnya ada di baris daftar pengajuan maupun di halaman detail (keputusan pengguna, 6 Nov 2026, revisi kedua):
  - **File arsipnya adalah scan bertanda tangan.** Kalau scan sudah masuk, itu yang dipakai (scan kurang jelas? ganti dulu lewat **Ganti Scan**). Kalau scan belum masuk, jendela Fisik Diterima meminta unggah scan sekaligus: scan disimpan sebagai scan pengajuan dan langsung diarsipkan dalam satu langkah.
  - Unggah sekaligus tidak mengirim WhatsApp "menunggu dokumen fisik" ke pemohon, karena pengajuan langsung Selesai; pemohon hanya mendapat pesan Selesai. Riwayat tetap mencatat scan masuk lalu Selesai.
  - Tanggal terbit dan tanggal berakhir diambil dari pengajuan. Nomor dokumen dikosongkan dan dilengkapi nanti di menu Dokumen.
  - Isian hanya muncul untuk data yang memang tidak ada di pengajuan: **badan usaha** (pengajuan publik dengan badan usaha lain) dan **tanggal berakhir** (jenis dokumen yang punya masa berlaku, tetapi pengajuan tidak mencantumkannya).
  - PIC dokumen arsip: PIC pengajuan; bila kosong, pemeriksa; bila kosong juga, Staff Legal yang menekan tombol.
- Daftar pengajuan dan Ringkasan di halaman detail menampilkan badge tambahan di samping status: **"Belum scan"** (Menunggu Tanda Tangan, scan belum masuk) dan **"Fisik belum diterima"** (Menunggu Dokumen Fisik; merah bila sudah terlambat).
- Dokumen otomatis masuk arsip Dokumen, seperti alur **Unggah Dokumen Final** yang sekarang.
- Status berubah ke **Selesai**. Pemohon mendapat WhatsApp bahwa dokumen fisik sudah diterima legal.
- Tanggal fisik diterima dicatat. Kalau terlambat, berapa hari keterlambatannya juga tercatat.
- Dokumen asli yang diserahkan langsung ke legal tanpa scan, atau pengajuan lama hasil penyesuaian (§6): Staff Legal menekan **Fisik Diterima** dan mengunggah scan-nya di jendela yang sama. (Sempat diputuskan scan wajib diunggah dulu lewat aksi terpisah; pada hari yang sama pengguna memilih unggah sekaligus.)

### 3.8 Membatalkan pengajuan setelah draft keluar

Diputuskan pengguna: legal bisa membatalkan.

- Di status **Menunggu Tanda Tangan** atau **Menunggu Dokumen Fisik**, termasuk yang sedang Terlambat, Staff Legal punya aksi **Batalkan** dengan **alasan wajib**. Contohnya, kerja sama tidak jadi, atau draft salah data dan pengajuan harus diulang dari awal.
- Status berubah ke **Dibatalkan**, yang merupakan status akhir seperti Ditolak. Semua pengingat untuk pengajuan itu berhenti, dan pengajuan keluar dari kelompok Menunggu, Terlambat, maupun Menunggu tanda tangan di daftar pantauan.
- Pemohon mendapat WhatsApp bahwa pengajuan dibatalkan, beserta alasannya. Halaman status publik menampilkan alasan itu, sama seperti alasan penolakan.
- Tidak ada aksi "kembalikan ke revisi" setelah draft keluar. Kalau draft perlu diulang dengan data baru, pengajuan dibatalkan dan pemohon mengajukan lagi.
- Pembatalan tidak menghapus draft maupun scan yang sudah diunggah. Keduanya tetap terlihat di halaman pengajuan sebagai catatan.

## 4. Pemantauan untuk legal

Di menu **Pengajuan Dokumen**, legal bisa melihat dengan cepat:

| Kelompok | Arti |
|---|---|
| **Perlu diperiksa** | Pengajuan berstatus Diperiksa |
| **Menunggu tanda tangan** | Draft sudah keluar, scan belum diunggah. Yang sudah lewat 7 hari sejak draft keluar ditandai "Belum unggah scan > 7 hari" |
| **Menunggu fisik** | Scan sudah ada, masih dalam 7 hari. Tampilkan sisa hari |
| **Terlambat** | Lewat 7 hari. Tampilkan berapa hari terlambat, alasan terakhir, atau tulisan "belum ada alasan" |
| **Selesai** | Fisik sudah diterima |
| **Dibatalkan** | Dibatalkan legal setelah draft keluar. Tidak termasuk kelompok yang perlu dipantau |

Bentuk tampilannya (tab, filter, atau kartu) diserahkan ke pengembang. Yang wajib ada: setiap kelompok bisa dibuka menjadi daftar, dan baris yang terlambat langsung terlihat beda.

Widget **Pengajuan Menunggu Tindakan** di dashboard disesuaikan: kartu "Menunggu Persetujuan" diganti kartu **Menunggu Fisik** dan **Terlambat**.

## 5. Halaman pemohon publik (`/pengajuan/{token}`)

- Langkah progres disesuaikan menjadi: **Diperiksa → Menunggu Tanda Tangan → Menunggu Dokumen Fisik → Selesai**.
- Menunggu Tanda Tangan: tombol **Unduh Draft** dan form **Unggah Scan Bertanda Tangan**.
- Menunggu Dokumen Fisik: tampilkan batas waktu (tanggal) dan sisa hari, dan masih boleh mengganti scan.
- Terlambat: pemberitahuan menonjol bahwa batas waktu terlewat, form **Alasan Keterlambatan**, dan daftar alasan yang sudah dikirim.
- Riwayat (timeline) memuat peristiwa baru: draft dikeluarkan atau diganti, scan diunggah atau diganti, alasan dikirim, fisik diterima, dibatalkan.
- Dibatalkan: tampilkan alasan pembatalan, sama seperti alasan penolakan. Progres ditampilkan berhenti di tahap terakhir yang dicapai.

## 6. Pengajuan yang sudah berjalan

**Keputusan pengembang**, disesuaikan sekali saat fitur dipasang:

| Status lama | Menjadi |
|---|---|
| Menunggu Persetujuan (`review`) | **Diperiksa**. Legal tinggal mengeluarkan draft |
| Menunggu Tanda Tangan (`approved`), belum ada draft | **Menunggu Tanda Tangan** tanpa file draft. Legal boleh mengunggah draft, atau langsung mengunggah scan atas nama pemohon |
| Status lain | Tidak berubah |

Riwayat lama tetap disimpan apa adanya, dan label lama tetap bisa dibaca di timeline.

## 7. Bukan bagian dari pekerjaan ini

- **Pengajuan yang tidak butuh tanda tangan.** Briefing menyebutnya, tapi itu untuk kasus lain. **Untuk sekarang semua pengajuan wajib tanda tangan, dan dokumen fisiknya harus sampai di legal.**
- Persetujuan atau penolakan alasan keterlambatan, dan perpanjangan batas waktu.
- Tanda tangan elektronik di dalam aplikasi.
- Tahap persetujuan berjenjang di dalam sistem.
- Perubahan pada fitur arsip Dokumen, impor, dan pengingat masa berlaku dokumen.

## 8. Yang harus dihindari

- Alur yang memaksa legal tetap mengecek satu per satu, misalnya status terlambat yang hanya terlihat setelah membuka tiap pengajuan.
- Menambah langkah atau persetujuan yang tidak diminta. Pengguna meminta sistem yang sederhana.
- Pemohon publik terpaksa membuat akun untuk mengunggah scan atau menulis alasan.

## 9. Diserahkan ke pengembang

- Nama kunci status di kode, struktur tabel dan kolom baru, serta cara menyimpan draft dan scan. Disarankan disk lokal seperti lampiran pengajuan sekarang, lalu masuk S3 saat diarsipkan.
- Teks bawaan pesan WhatsApp dan label tombol, mengikuti gaya bahasa aplikasi.
- Bentuk tampilan daftar pantauan (§4).
- Pembaruan video atau halaman Panduan, yang dikerjakan terpisah setelah alur ini jadi.
