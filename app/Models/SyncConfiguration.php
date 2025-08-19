<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class SyncConfiguration extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'value',
        'type',
        'description',
        'category',
        'is_system',
    ];

    protected $casts = [
        'is_system' => 'boolean',
    ];

    public const TYPE_STRING = 'string';
    public const TYPE_INTEGER = 'integer';
    public const TYPE_BOOLEAN = 'boolean';
    public const TYPE_JSON = 'json';

    public const CATEGORY_GENERAL = 'general';
    public const CATEGORY_BACKUP = 'backup';
    public const CATEGORY_PURGE = 'purge';
    public const CATEGORY_ALERTS = 'alerts';
    public const CATEGORY_SYNC = 'sync';
    public const CATEGORY_MAIL_SERVER = 'mail_server';
    public const CATEGORY_ONEDRIVE = 'onedrive';

    public static function getTypeOptions(): array
    {
        return [
            self::TYPE_STRING => 'String',
            self::TYPE_INTEGER => 'Integer',
            self::TYPE_BOOLEAN => 'Boolean',
            self::TYPE_JSON => 'JSON',
        ];
    }

    public static function getCategoryOptions(): array
    {
        return [
            self::CATEGORY_GENERAL => 'General',
            self::CATEGORY_BACKUP => 'Backup',
            self::CATEGORY_PURGE => 'Purge',
            self::CATEGORY_ALERTS => 'Alerts',
            self::CATEGORY_SYNC => 'Sync',
            self::CATEGORY_MAIL_SERVER => 'Mail Server',
            self::CATEGORY_ONEDRIVE => 'OneDrive',
        ];
    }

    public function getTypedValueAttribute()
    {
        return match ($this->type) {
            self::TYPE_INTEGER => (int) $this->value,
            self::TYPE_BOOLEAN => filter_var($this->value, FILTER_VALIDATE_BOOLEAN),
            self::TYPE_JSON => json_decode($this->value, true),
            default => $this->value,
        };
    }

    public function setTypedValue($value): void
    {
        $this->value = match ($this->type) {
            self::TYPE_INTEGER => (string) intval($value),
            self::TYPE_BOOLEAN => $value ? 'true' : 'false',
            self::TYPE_JSON => json_encode($value),
            default => (string) $value,
        };
    }

    public function scopeByCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    public function scopeSystem($query)
    {
        return $query->where('is_system', true);
    }

    public function scopeUserConfigurable($query)
    {
        return $query->where('is_system', false);
    }

    protected static function boot()
    {
        parent::boot();

        // Clear cache when configuration is updated
        static::saved(function () {
            Cache::forget('sync_configurations');
        });

        static::deleted(function () {
            Cache::forget('sync_configurations');
        });
    }

    /**
     * Get a configuration value by key
     */
    public static function getValue(string $key, $default = null)
    {
        $configurations = Cache::remember('sync_configurations', 3600, function () {
            return static::all()->keyBy('key');
        });

        $config = $configurations->get($key);
        
        if (!$config) {
            return $default;
        }

        return $config->typed_value;
    }

    /**
     * Set a configuration value by key
     */
    public static function setValue(string $key, $value): void
    {
        $config = static::where('key', $key)->first();
        
        if (!$config) {
            throw new \Exception("Configuration key '{$key}' not found");
        }

        $config->setTypedValue($value);
        $config->save();
    }

    /**
     * Get all configurations by category
     */
    public static function getByCategory(string $category): array
    {
        return static::where('category', $category)
                    ->get()
                    ->mapWithKeys(function ($config) {
                        return [$config->key => $config->typed_value];
                    })
                    ->toArray();
    }

    /**
     * Bulk update configurations
     */
    public static function bulkUpdate(array $values): void
    {
        foreach ($values as $key => $value) {
            static::setValue($key, $value);
        }
    }

    /**
     * Get commonly used configuration values
     */
    public static function getBackupSettings(): array
    {
        return [
            'purge_age_months' => static::getValue('backup.purge_age_months', 24),
            'onedrive_folder' => static::getValue('backup.onedrive_folder', 'ISP_Mail_Backups'),
            'chunk_size' => static::getValue('backup.chunk_size', 100),
        ];
    }

    public static function getAlertSettings(): array
    {
        return [
            'mailbox_size_threshold_gb' => static::getValue('alerts.mailbox_size_threshold_gb', 4),
            'warning_threshold_percentage' => static::getValue('alerts.warning_threshold_percentage', 80),
            'notification_email' => static::getValue('alerts.notification_email', 'admin@example.com'),
        ];
    }

    public static function getSyncSettings(): array
    {
        return [
            'max_retry_attempts' => static::getValue('sync.max_retry_attempts', 3),
            'force_sync_max_days' => static::getValue('sync.force_sync_max_days', 7),
            'daily_sync_hour' => static::getValue('sync.daily_sync_hour', 2),
        ];
    }

    public static function getPurgeSettings(): array
    {
        return [
            'require_confirmation' => static::getValue('purge.require_confirmation', true),
            'keep_purge_history_months' => static::getValue('purge.keep_purge_history_months', 12),
            'weekly_schedule_day' => static::getValue('purge.weekly_schedule_day', 0),
        ];
    }

    public static function getMailServerSettings(): array
    {
        return [
            'host' => static::getValue('mail_server.host', 'mail.example.com'),
            'port' => static::getValue('mail_server.port', 993),
            'protocol' => static::getValue('mail_server.protocol', 'IMAP'),
            'use_ssl' => static::getValue('mail_server.use_ssl', true),
        ];
    }

    public static function getOneDriveSettings(): array
    {
        return [
            'tenant_id' => static::getValue('onedrive.tenant_id', ''),
            'client_id' => static::getValue('onedrive.client_id', ''),
            'client_secret' => static::getValue('onedrive.client_secret', ''),
            'redirect_uri' => static::getValue('onedrive.redirect_uri', 'http://localhost:8000/auth/callback'),
            'upload_chunk_size' => static::getValue('onedrive.upload_chunk_size', 10485760),
        ];
    }
}
