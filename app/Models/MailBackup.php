<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\EmailAccount;

class MailBackup extends Model
{
    use HasFactory;

    protected $fillable = [
        'email_account_id',
        'message_id',
        'mailbox_folder',
        'email_address',
        'original_file_path',
        'onedrive_path',
        'backup_date',
        'status',
        'file_size',
        'file_hash',
        'last_verified_at',
        'metadata',
        'error_message',
        'retry_count',
    ];

    protected $casts = [
        'backup_date' => 'datetime',
        'last_verified_at' => 'datetime',
        'metadata' => 'array',
        'file_size' => 'integer',
        'retry_count' => 'integer',
        'email_account_id' => 'integer',
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    // Fixed: Should be BelongsTo, not HasMany
    public function emailAccount(): BelongsTo
    {
        return $this->belongsTo(EmailAccount::class);
    }

    public static function getStatusOptions(): array
    {
        return [
            self::STATUS_PENDING => 'Pending',
            self::STATUS_PROCESSING => 'Processing',
            self::STATUS_COMPLETED => 'Completed',
            self::STATUS_FAILED => 'Failed',
        ];
    }

    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => 'warning',
            self::STATUS_PROCESSING => 'info',
            self::STATUS_COMPLETED => 'success',
            self::STATUS_FAILED => 'danger',
            default => 'secondary',
        };
    }

    public function getFileSizeHumanAttribute(): string
    {
        if (!$this->file_size) {
            return 'Unknown';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = $this->file_size;
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, 2) . ' ' . $units[$i];
    }

    public function scopeForEmail($query, string $email)
    {
        return $query->where('email_address', $email);
    }

    public function scopeForEmailAccount($query, int $emailAccountId)
    {
        return $query->where('email_account_id', $emailAccountId);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    public function scopeFailed($query)
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeProcessing($query)
    {
        return $query->where('status', self::STATUS_PROCESSING);
    }

    public function scopeByDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('backup_date', [$startDate, $endDate]);
    }

    public function scopeByMessageId($query, string $messageId)
    {
        return $query->where('message_id', $messageId);
    }

    public function scopeByMailboxFolder($query, string $folder)
    {
        return $query->where('mailbox_folder', $folder);
    }

    // Remove or fix this relationship - it doesn't seem to make sense
    // as it's linking by email_address which could match multiple records
    /*
    public function syncLogs(): HasMany
    {
        return $this->hasMany(SyncLog::class, 'email_address', 'email_address');
    }
    */

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isProcessing(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function canRetry(): bool
    {
        return $this->status === self::STATUS_FAILED && $this->retry_count < 3;
    }

    public function incrementRetryCount(): void
    {
        $this->increment('retry_count');
    }

    public function markAsProcessing(): void
    {
        $this->update(['status' => self::STATUS_PROCESSING]);
    }

    public function markAsCompleted(): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'last_verified_at' => now(),
        ]);
    }

    public function markAsFailed(string $errorMessage): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'error_message' => $errorMessage,
        ]);
    }

    public function resetForRetry(): void
    {
        $this->update([
            'status' => self::STATUS_PENDING,
            'error_message' => null,
        ]);
        $this->incrementRetryCount();
    }

    public function verifyIntegrity(): bool
    {
        // Add logic to verify file integrity using file_hash
        // This is a placeholder - implement according to your needs
        return true;
    }

    protected static function boot()
    {
        parent::boot();

        // Automatically set backup_date when creating if not provided
        static::creating(function ($model) {
            if (!$model->backup_date) {
                $model->backup_date = now();
            }
        });
    }
}