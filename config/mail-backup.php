<?php

return [
    'mail_server_path' => env('MAIL_SERVER_PATH', '/var/mail'),
    'onedrive_base_path' => env('ONEDRIVE_BASE_PATH', 'mail-backups'),
    'backup_retention_days' => env('BACKUP_RETENTION_DAYS', 90),
    'purge_threshold_days' => env('PURGE_THRESHOLD_DAYS', 30),
    'chunk_size' => env('BACKUP_CHUNK_SIZE', 100),
    'max_file_size_mb' => env('MAX_FILE_SIZE_MB', 100),
];