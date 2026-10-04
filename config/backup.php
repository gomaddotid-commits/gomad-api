<?php

return [
    'directory' => env('BACKUP_DIRECTORY', storage_path('app/private/backups')),
    'disk' => env('BACKUP_DISK', 's3'),
    'prefix' => env('BACKUP_PREFIX', 'backups'),
    'retention_days' => 30,
];
