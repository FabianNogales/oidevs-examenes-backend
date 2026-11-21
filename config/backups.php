<?php

return [
    'enabled' => (bool) env('EIDA_BACKUPS_ENABLED', false),
    'executor' => env('EIDA_BACKUPS_EXECUTOR', 'github'),
    'callback_secret' => env('EIDA_BACKUPS_CALLBACK_SECRET', ''),
    'callback_window_seconds' => 300,
    'deadline_minutes' => 60,
    'drive' => [
        'client_id' => env('EIDA_BACKUPS_DRIVE_CLIENT_ID', ''),
        'client_secret' => env('EIDA_BACKUPS_DRIVE_CLIENT_SECRET', ''),
        'refresh_token' => env('EIDA_BACKUPS_DRIVE_REFRESH_TOKEN', ''),
        'folder_id' => env('EIDA_BACKUPS_DRIVE_FOLDER_ID', ''),
    ],
    'download' => [
        'enabled' => (bool) env('EIDA_BACKUPS_DOWNLOADS_ENABLED', false),
        'worker_url' => env('EIDA_BACKUPS_DOWNLOAD_WORKER_URL', ''),
        'signing_secret' => env('EIDA_BACKUPS_DOWNLOAD_SIGNING_SECRET', ''),
        'issuer' => env('EIDA_BACKUPS_TICKET_ISSUER', ''),
        'audience' => 'eida-backup-download',
        'key_id' => env('EIDA_BACKUPS_TICKET_KEY_ID', ''),
        'ttl_seconds' => 60,
    ],
    'requests_per_hour' => 3,
    'github' => [
        'repository' => env('EIDA_BACKUPS_GITHUB_REPOSITORY', ''),
        'workflow' => 'database-backup.yml',
        'ref' => env('EIDA_BACKUPS_GITHUB_REF', 'backup-execution'),
        'token' => env('EIDA_BACKUPS_GITHUB_TOKEN', ''),
    ],
];
