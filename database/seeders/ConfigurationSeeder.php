<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\ConfigurationGroup;
use App\Models\Configuration;

class ConfigurationSeeder extends Seeder
{
    public function run(): void
    {
        // OneDrive Configuration Group
        $oneDriveGroup = ConfigurationGroup::create([
            'name' => 'onedrive',
            'display_name' => 'OneDrive Settings',
            'description' => 'Microsoft OneDrive integration configuration',
            'icon' => 'heroicon-o-cloud-arrow-up',
            'sort_order' => 1,
        ]);

        $oneDriveConfigs = [
            [
                'key' => 'MICROSOFT_GRAPH_CLIENT_ID',
                'display_name' => 'Client ID',
                'description' => 'Microsoft Graph API Client ID',
                'type' => 'text',
                'is_required' => true,
                'sort_order' => 1,
            ],
            [
                'key' => 'MICROSOFT_GRAPH_CLIENT_SECRET',
                'display_name' => 'Client Secret',
                'description' => 'Microsoft Graph API Client Secret',
                'type' => 'password',
                'is_required' => true,
                'is_encrypted' => true,
                'sort_order' => 2,
            ],
            [
                'key' => 'MICROSOFT_GRAPH_TENANT_ID',
                'display_name' => 'Tenant ID',
                'description' => 'Microsoft Graph Tenant ID (use "common" for personal accounts)',
                'type' => 'text',
                'value' => 'common',
                'sort_order' => 3,
            ],
            [
                'key' => 'MICROSOFT_GRAPH_REDIRECT_URI',
                'display_name' => 'Redirect URI',
                'description' => 'OAuth redirect URI configured in Azure',
                'type' => 'text',
                'is_required' => true,
                'sort_order' => 4,
            ],
            [
                'key' => 'ONEDRIVE_USER_ID',
                'display_name' => 'User ID',
                'description' => 'OneDrive User ID (optional)',
                'type' => 'text',
                'sort_order' => 5,
            ],
            [
                'key' => 'ONEDRIVE_ROOT_FOLDER',
                'display_name' => 'Root Folder',
                'description' => 'Root folder name for file storage',
                'type' => 'text',
                'value' => 'ISP_Mail_Backups',
                'sort_order' => 6,
            ],
            [
                'key' => 'ONEDRIVE_UPLOAD_CHUNK_SIZE',
                'display_name' => 'Upload Chunk Size',
                'description' => 'File upload chunk size in bytes',
                'type' => 'number',
                'value' => '10485760',
                'sort_order' => 7,
            ],
            [
                'key' => 'ONEDRIVE_MAX_RETRY_ATTEMPTS',
                'display_name' => 'Max Retry Attempts',
                'description' => 'Maximum retry attempts for failed uploads',
                'type' => 'number',
                'value' => '3',
                'sort_order' => 8,
            ],
            [
                'key' => 'ONEDRIVE_RETRY_DELAY',
                'display_name' => 'Retry Delay',
                'description' => 'Delay between retry attempts (seconds)',
                'type' => 'number',
                'value' => '5',
                'sort_order' => 9,
            ],
            [
                'key' => 'MICROSOFT_SCOPES',
                'display_name' => 'Microsoft Scopes',
                'description' => 'Space-separated list of Microsoft Graph scopes',
                'type' => 'textarea',
                'value' => 'https://graph.microsoft.com/Files.ReadWrite https://graph.microsoft.com/User.Read offline_access',
                'sort_order' => 10,
            ],
            [
                'key' => 'ONEDRIVE_TYPE',
                'display_name' => 'OneDrive Type',
                'description' => 'Type of OneDrive account',
                'type' => 'select',
                'options' => ['personal' => 'Personal', 'business' => 'Business'],
                'value' => 'personal',
                'sort_order' => 11,
            ],
            [
                'key' => 'ONEDRIVE_DRIVE_ID',
                'display_name' => 'Drive ID',
                'description' => 'OneDrive Drive identifier',
                'type' => 'text',
                'value' => 'me/drive',
                'sort_order' => 12,
            ],
        ];

        foreach ($oneDriveConfigs as $config) {
            $config['configuration_group_id'] = $oneDriveGroup->id;
            Configuration::create($config);
        }

        // Application Configuration Group
        $appGroup = ConfigurationGroup::create([
            'name' => 'application',
            'display_name' => 'Application Settings',
            'description' => 'General application configuration',
            'icon' => 'heroicon-o-cog-6-tooth',
            'sort_order' => 2,
        ]);

        $appConfigs = [
            [
                'key' => 'APP_NAME',
                'display_name' => 'Application Name',
                'description' => 'The name of your application',
                'type' => 'text',
                'value' => 'Laravel',
                'is_required' => true,
                'sort_order' => 1,
            ],
            [
                'key' => 'APP_ENV',
                'display_name' => 'Environment',
                'description' => 'Application environment',
                'type' => 'select',
                'options' => ['local' => 'Local', 'staging' => 'Staging', 'production' => 'Production'],
                'value' => 'production',
                'is_required' => true,
                'sort_order' => 2,
            ],
            [
                'key' => 'APP_DEBUG',
                'display_name' => 'Debug Mode',
                'description' => 'Enable debug mode for detailed error messages',
                'type' => 'boolean',
                'value' => 'false',
                'sort_order' => 3,
            ],
            [
                'key' => 'APP_URL',
                'display_name' => 'Application URL',
                'description' => 'The base URL of your application',
                'type' => 'text',
                'value' => 'http://localhost',
                'is_required' => true,
                'validation_rules' => 'url',
                'sort_order' => 4,
            ],
            [
                'key' => 'APP_LOCALE',
                'display_name' => 'Default Locale',
                'description' => 'Default application locale',
                'type' => 'select',
                'options' => ['en' => 'English', 'es' => 'Spanish', 'fr' => 'French', 'de' => 'German'],
                'value' => 'en',
                'sort_order' => 5,
            ],
        ];

        foreach ($appConfigs as $config) {
            $config['configuration_group_id'] = $appGroup->id;
            Configuration::create($config);
        }

        // Email Configuration Group
        $emailGroup = ConfigurationGroup::create([
            'name' => 'email',
            'display_name' => 'Email Settings',
            'description' => 'Email service configuration',
            'icon' => 'heroicon-o-envelope',
            'sort_order' => 3,
        ]);

        $emailConfigs = [
            [
                'key' => 'MAIL_MAILER',
                'display_name' => 'Mail Driver',
                'description' => 'Mail service driver',
                'type' => 'select',
                'options' => ['smtp' => 'SMTP', 'sendmail' => 'Sendmail', 'mailgun' => 'Mailgun', 'ses' => 'Amazon SES'],
                'value' => 'smtp',
                'is_required' => true,
                'sort_order' => 1,
            ],
            [
                'key' => 'MAIL_HOST',
                'display_name' => 'SMTP Host',
                'description' => 'SMTP server hostname',
                'type' => 'text',
                'value' => 'smtp.mailgun.org',
                'sort_order' => 2,
            ],
            [
                'key' => 'MAIL_PORT',
                'display_name' => 'SMTP Port',
                'description' => 'SMTP server port',
                'type' => 'number',
                'value' => '587',
                'sort_order' => 3,
            ],
            [
                'key' => 'MAIL_USERNAME',
                'display_name' => 'SMTP Username',
                'description' => 'SMTP authentication username',
                'type' => 'text',
                'sort_order' => 4,
            ],
            [
                'key' => 'MAIL_PASSWORD',
                'display_name' => 'SMTP Password',
                'description' => 'SMTP authentication password',
                'type' => 'password',
                'is_encrypted' => true,
                'sort_order' => 5,
            ],
            [
                'key' => 'MAIL_ENCRYPTION',
                'display_name' => 'Encryption',
                'description' => 'Email encryption method',
                'type' => 'select',
                'options' => ['tls' => 'TLS', 'ssl' => 'SSL', '' => 'None'],
                'value' => 'tls',
                'sort_order' => 6,
            ],
            [
                'key' => 'MAIL_FROM_ADDRESS',
                'display_name' => 'From Address',
                'description' => 'Default sender email address',
                'type' => 'text',
                'validation_rules' => 'email',
                'sort_order' => 7,
            ],
            [
                'key' => 'MAIL_FROM_NAME',
                'display_name' => 'From Name',
                'description' => 'Default sender name',
                'type' => 'text',
                'sort_order' => 8,
            ],
        ];

        foreach ($emailConfigs as $config) {
            $config['configuration_group_id'] = $emailGroup->id;
            Configuration::create($config);
        }
    }
}

