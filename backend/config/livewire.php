<?php

// Only what LUGHA changes; everything else comes from Livewire's own config (merged per top-level key).
return [
    'temporary_file_upload' => [
        'disk' => env('LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK'),
        // Translation files for a whole game run to tens of MB (Livewire's default cap is 12 MB). Keep under PHP's
        // upload_max_filesize / post_max_size (256M on the host).
        'rules' => ['required', 'file', 'max:'.(int) env('LUGHA_MAX_UPLOAD_KB', 204800)],
        'directory' => null,
        'middleware' => null,
        'preview_mimes' => ['png', 'gif', 'bmp', 'svg', 'wav', 'mp4', 'mov', 'avi', 'wmv', 'mp3', 'm4a', 'jpg', 'jpeg', 'mpga', 'webp', 'wma'],
        'max_upload_time' => 30, // minutes: a big file on a slow connection
        'cleanup' => true,
    ],
];
