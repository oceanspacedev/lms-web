<?php

return [
    'temporary_file_upload' => [
        'disk' => 'local',
        'rules' => ['required', 'file', 'max:'.env('LMS_MAX_UPLOAD_SIZE_KB', 20480)],
    ],
];
