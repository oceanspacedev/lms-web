<?php

return [
    'reminder_timezone' => 'Asia/Jakarta',
    'reminder_time' => '08:00',
    'reminder_max_attempts' => (int) env('LMS_REMINDER_MAX_ATTEMPTS', 3),
    'max_upload_size_kb' => (int) env('LMS_MAX_UPLOAD_SIZE_KB', 20480),
    'signed_url_minutes' => 5,
    'allowed_extensions' => ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'],
    'allowed_mime_types' => [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'image/jpeg',
        'image/png',
    ],
];
