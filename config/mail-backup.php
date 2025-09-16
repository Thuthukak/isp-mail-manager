<?php

// config/mail-backup.php  
return [

    'onedrive_base_path' => env('MAIL_BACKUP_ONEDRIVE_PATH', 'ISP-Email-Backups'),

    // Default IMAP settings for organization
    'default_imap_settings' => [
        'port' => env('DEFAULT_IMAP_PORT', 993),
        'encryption' => env('DEFAULT_IMAP_ENCRYPTION', 'ssl'),
        'validate_cert' => env('IMAP_VALIDATE_CERT', true),
    ],

    // Mail Server Configuration
    'mail_server' => [
        'type' => env('MAIL_SERVER_TYPE', 'imap'),
        'host' => env('MAIL_SERVER_HOST'),
        'port' => env('MAIL_SERVER_PORT', 993),
        'encryption' => env('MAIL_SERVER_ENCRYPTION', 'ssl'),
        'username' => env('MAIL_SERVER_USERNAME'),
        'password' => env('MAIL_SERVER_PASSWORD'),
    ],
    
    // Storage Configuration
    'storage' => [
        'local_path' => env('MAIL_STORAGE_PATH', storage_path('mail')),
        'chunk_size' => env('MAIL_BACKUP_CHUNK_SIZE', 50),
        'max_file_size_mb' => env('MAX_FILE_SIZE_MB', 100),
    ],
    
    // Backup Settings
    'backup' => [
        'retention_days' => env('BACKUP_RETENTION_DAYS', 90),
        'max_concurrent_backups' => env('MAX_CONCURRENT_BACKUPS', 3),
        'temp_storage_path' => storage_path('app/maildir-backups'),
        'purge_threshold_days' => env('PURGE_THRESHOLD_DAYS', 30),
    ],
    
    // Processing Settings
    'processing' => [
        'batch_size' => env('MAIL_PROCESSING_BATCH_SIZE', 50),
        'timeout' => env('MAIL_PROCESSING_TIMEOUT', 300),
        'max_execution_time' => env('SYSTEM_MAX_EXECUTION_TIME', 3600),
        'memory_limit' => env('SYSTEM_MEMORY_LIMIT', '512M'),
    ],
    
    // Sync Settings
    'sync' => [
        'max_retry_attempts' => env('SYNC_MAX_RETRY_ATTEMPTS', 3),
        'daily_hour' => env('SYNC_DAILY_HOUR', 2),
    ],
    
    // Monitoring
    'monitoring' => [
        'enabled' => env('MONITORING_ENABLED', true),
        'disk_usage_threshold' => env('MONITORING_DISK_USAGE_THRESHOLD', 85),
        'memory_usage_threshold' => env('MONITORING_MEMORY_USAGE_THRESHOLD', 80),
    ],
    
    // Alerts
    'alerts' => [
        'email_enabled' => env('ALERT_EMAIL_ENABLED', true),
    ],
];