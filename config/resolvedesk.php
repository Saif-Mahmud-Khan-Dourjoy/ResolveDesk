<?php

/*
|--------------------------------------------------------------------------
| ResolveDesk business settings (V2)
|--------------------------------------------------------------------------
| Values that an administrator or interviewer may want to change live.
| Read them with config('resolvedesk.attachments.max_file_kb').
*/

return [

    'attachments' => [
        // Private disk: "local" stores files in storage/app/private (never public).
        // Switch to "s3" later without changing any code.
        'disk' => env('ATTACHMENT_DISK', 'local'),

        // Maximum size of ONE file, in kilobytes (5120 KB = 5 MB).
        'max_file_kb' => (int) env('ATTACHMENT_MAX_KB', 5120),

        // Maximum number of files per request (ticket creation or one comment).
        'max_files' => 5,

        // Allowed file extensions. The upload is checked on the server by
        // extension AND by detected MIME type (Laravel "mimes" rule).
        'allowed_extensions' => ['pdf', 'png', 'jpg', 'jpeg', 'txt', 'docx', 'xlsx'],
    ],

    'assignment_lock' => [
        // Cache::lock() name shared by manual and automatic assignment.
        'key' => 'ticket-assignment',
        // The lock expires automatically after this many seconds (safety net if PHP crashes).
        'seconds' => 10,
        // How long a second request waits for the lock before giving up.
        'wait_seconds' => 5,
    ],

];
