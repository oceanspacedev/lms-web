<?php

return [
    'temporary_file_upload' => [
        'disk' => 'local',
        // Batas global mengikuti unggahan terbesar (ZIP impor dokumen lama); setiap FileUpload tetap memakai maxSize() masing-masing.
        'rules' => ['required', 'file', 'max:'.max((int) env('LMS_MAX_UPLOAD_SIZE_KB', 20480), (int) env('LMS_IMPORT_MAX_ZIP_KB', 512000))],
        // ZIP arsip berukuran ratusan MB butuh waktu unggah lebih lama dari bawaan 5 menit.
        'max_upload_time' => 30,
    ],
];
