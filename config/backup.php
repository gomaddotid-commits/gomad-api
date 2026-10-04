<?php

return [
    'directory' => env('BACKUP_DIRECTORY', storage_path('app/private/backups')),
    'retention_days' => 30,
];
