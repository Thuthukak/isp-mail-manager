<?php

namespace Database\seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SyncConfigurationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $configurations = [
            // Backup Settings
            [
                'key' => 'backup.purge_age_months',
                'value' => '24',
                'type' => 'integer',
                'description' => 'Age in months after which mails should be purged (default: 24 months = 2 years)',
                'category' => 'backup',
                'is_system' => true,
            ],
            [
                'key' => 'backup.onedrive_folder',
                'value' => 'ISP_Mail_Backups',
                'type' => 'string',
                'description' => 'OneDrive folder name for storing mail backups',
                'category' => 'backup',
                'is_system' => true,
            ],
            [
                'key' => 'backup.chunk_size',
                'value' => '100',
                'type' => 'integer',
                'description' => 'Number of files to process in each batch',
                'category' => 'backup',
                'is_system' => true,
            ],
            
            // Alert Settings
            [
                'key' => 'alerts.mailbox_size_threshold_gb',
                'value' => '4',
                'type' => 'integer',
                'description' => 'Mailbox size threshold in GB for triggering alerts',
                'category' => 'alerts',
                'is_system' => true,
            ],
            [
                'key' => 'alerts.warning_threshold_percentage',
                'value' => '80',
                'type' => 'integer',
                'description' => 'Warning threshold as percentage of main threshold',
                'category' => 'alerts',
                'is_system' => true,
            ],
            [
                'key' => 'alerts.notification_email',
                'value' => 'admin@example.com',
                'type' => 'string',
                'description' => 'Email address for system notifications',
                'category' => 'alerts',
                'is_system' => false,
            ],
            
            // Sync Settings
            [
                'key' => 'sync.max_retry_attempts',
                'value' => '3',
                'type' => 'integer',
                'description' => 'Maximum number of retry attempts for failed operations',
                'category' => 'sync',
                'is_system' => true,
            ],
            [
                'key' => 'sync.force_sync_max_days',
                'value' => '7',
                'type' => 'integer',
                'description' => 'Maximum number of days allowed for force sync',
                'category' => 'sync',
                'is_system' => true,
            ],
            [
                'key' => 'sync.daily_sync_hour',
                'value' => '2',
                'type' => 'integer',
                'description' => 'Hour of day (0-23) for daily sync operations',
                'category' => 'sync',
                'is_system' => true,
            ],
            
            // Purge Settings
            [
                'key' => 'purge.require_confirmation',
                'value' => 'true',
                'type' => 'boolean',
                'description' => 'Require admin confirmation before purging mails',
                'category' => 'purge',
                'is_system' => true,
            ],
            [
                'key' => 'purge.keep_purge_history_months',
                'value' => '12',
                'type' => 'integer',
                'description' => 'Number of months to keep purge history for rollback',
                'category' => 'purge',
                'is_system' => true,
            ],
            [
                'key' => 'purge.weekly_schedule_day',
                'value' => '0',
                'type' => 'integer',
                'description' => 'Day of week for weekly purge (0=Sunday, 6=Saturday)',
                'category' => 'purge',
                'is_system' => true,
            ],
            
            // Mail Server Settings
            [
                'key' => 'mail_server.host',
                'value' => 'mail.example.com',
                'type' => 'string',
                'description' => 'Mail server hostname',
                'category' => 'mail_server',
                'is_system' => false,
            ],
            [
                'key' => 'mail_server.port',
                'value' => '993',
                'type' => 'integer',
                'description' => 'Mail server port',
                'category' => 'mail_server',
                'is_system' => false,
            ],
            [
                'key' => 'mail_server.protocol',
                'value' => 'IMAP',
                'type' => 'string',
                'description' => 'Mail server protocol (IMAP/POP3)',
                'category' => 'mail_server',
                'is_system' => false,
            ],
            [
                'key' => 'mail_server.use_ssl',
                'value' => 'true',
                'type' => 'boolean',
                'description' => 'Use SSL for mail server connection',
                'category' => 'mail_server',
                'is_system' => false,
            ],
            
            // OneDrive Settings
            [
                'key' => 'onedrive.tenant_id',
                'value' => '',
                'type' => 'string',
                'description' => 'Azure AD Tenant ID',
                'category' => 'onedrive',
                'is_system' => false,
            ],
            [
                'key' => 'onedrive.client_id',
                'value' => '',
                'type' => 'string',
                'description' => 'Azure AD Application Client ID',
                'category' => 'onedrive',
                'is_system' => false,
            ],
            [
                'key' => 'onedrive.client_secret',
                'value' => '',
                'type' => 'string',
                'description' => 'Azure AD Application Client Secret',
                'category' => 'onedrive',
                'is_system' => false,
            ],
            [
                'key' => 'onedrive.redirect_uri',
                'value' => 'http://localhost:8000/auth/callback',
                'type' => 'string',
                'description' => 'OAuth redirect URI',
                'category' => 'onedrive',
                'is_system' => false,
            ],
            [
                'key' => 'onedrive.upload_chunk_size',
                'value' => '10485760',
                'type' => 'integer',
                'description' => 'Upload chunk size in bytes (default: 10MB)',
                'category' => 'onedrive',
                'is_system' => true,
            ],
        ];

        foreach ($configurations as $config) {
            DB::table('sync_configurations')->updateOrInsert(
                ['key' => $config['key']],
                array_merge($config, [
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
            );
        }
    }
}
