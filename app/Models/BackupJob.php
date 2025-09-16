<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Carbon\Carbon;

class BackupJob extends Model
{
    use HasFactory;

    protected $fillable = [
        'email_account_id',
        'status',
        'emails_backed_up',
        'total_emails',
        'backup_path',
        'mailboxes_backed_up',
        'error_message',
        'started_at',
        'completed_at',
        'retry_count',
        'job_type',
        'total_size_bytes',
        'progress_percentage',
    ];

    protected $casts = [
        'mailboxes_backed_up' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'emails_backed_up' => 'integer',
        'total_emails' => 'integer',
        'retry_count' => 'integer',
        'total_size_bytes' => 'integer',
        'progress_percentage' => 'float',
        'email_account_id' => 'integer',
    ];

    // Status constants
    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    // Job type constants
    public const TYPE_MANUAL = 'manual';
    public const TYPE_SCHEDULED = 'scheduled';
    public const TYPE_RETRY = 'retry';

    // Relationships
    public function emailAccount(): BelongsTo
    {
        return $this->belongsTo(EmailAccount::class);
    }

    public function mailBackups(): HasMany
    {
        return $this->hasMany(MailBackup::class, 'email_account_id', 'email_account_id')
            ->whereBetween('created_at', [$this->started_at ?? $this->created_at, $this->completed_at ?? now()]);
    }

    // Static methods
    public static function getStatusOptions(): array
    {
        return [
            self::STATUS_PENDING => 'Pending',
            self::STATUS_RUNNING => 'Running',
            self::STATUS_COMPLETED => 'Completed',
            self::STATUS_FAILED => 'Failed',
            self::STATUS_CANCELLED => 'Cancelled',
        ];
    }

    public static function getJobTypeOptions(): array
    {
        return [
            self::TYPE_MANUAL => 'Manual',
            self::TYPE_SCHEDULED => 'Scheduled',
            self::TYPE_RETRY => 'Retry',
        ];
    }

    // Accessors
    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => 'warning',
            self::STATUS_RUNNING => 'info',
            self::STATUS_COMPLETED => 'success',
            self::STATUS_FAILED => 'danger',
            self::STATUS_CANCELLED => 'secondary',
            default => 'light',
        };
    }

    public function getDurationAttribute(): ?string
    {
        if (!$this->started_at) {
            return null;
        }

        $end = $this->completed_at ?? now();
        $duration = $this->started_at->diff($end);

        if ($duration->days > 0) {
            return $duration->format('%d days, %h hours, %i minutes');
        } elseif ($duration->h > 0) {
            return $duration->format('%h hours, %i minutes');
        } elseif ($duration->i > 0) {
            return $duration->format('%i minutes, %s seconds');
        } else {
            return $duration->format('%s seconds');
        }
    }

    public function getTotalSizeHumanAttribute(): string
    {
        if (!$this->total_size_bytes) {
            return 'Unknown';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = $this->total_size_bytes;
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, 2) . ' ' . $units[$i];
    }

    // Scopes
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeRunning($query)
    {
        return $query->where('status', self::STATUS_RUNNING);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    public function scopeFailed($query)
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    public function scopeCancelled($query)
    {
        return $query->where('status', self::STATUS_CANCELLED);
    }

    public function scopeForEmailAccount($query, int $emailAccountId)
    {
        return $query->where('email_account_id', $emailAccountId);
    }

    public function scopeByJobType($query, string $jobType)
    {
        return $query->where('job_type', $jobType);
    }

    public function scopeRecent($query, int $days = 30)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    public function scopeStuck($query, int $hours = 6)
    {
        return $query->where('status', self::STATUS_RUNNING)
            ->where('started_at', '<', now()->subHours($hours));
    }

    // Status check methods
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isRunning(): bool
    {
        return $this->status === self::STATUS_RUNNING;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_CANCELLED]);
    }

    public function canRetry(): bool
    {
        return $this->isFailed() && $this->retry_count < 3;
    }

    public function canCancel(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_RUNNING]);
    }

    // Action methods
    public function start(): void
    {
        $this->update([
            'status' => self::STATUS_RUNNING,
            'started_at' => now(),
            'error_message' => null,
        ]);
    }

    public function complete(): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'completed_at' => now(),
            'progress_percentage' => 100.00,
        ]);
    }

    public function fail(string $errorMessage): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'completed_at' => now(),
            'error_message' => $errorMessage,
        ]);
    }

    public function cancel(): void
    {
        $this->update([
            'status' => self::STATUS_CANCELLED,
            'completed_at' => now(),
        ]);
    }

    public function retry(): self
    {
        $retryJob = $this->replicate();
        $retryJob->fill([
            'status' => self::STATUS_PENDING,
            'job_type' => self::TYPE_RETRY,
            'retry_count' => $this->retry_count + 1,
            'started_at' => null,
            'completed_at' => null,
            'error_message' => null,
            'emails_backed_up' => 0,
            'progress_percentage' => 0.00,
        ]);
        $retryJob->save();

        return $retryJob;
    }

    public function updateProgress(int $emailsBackedUp, ?int $totalEmails = null, ?int $totalSizeBytes = null): void
    {
        $updates = ['emails_backed_up' => $emailsBackedUp];

        if ($totalEmails !== null) {
            $updates['total_emails'] = $totalEmails;
        }

        if ($totalSizeBytes !== null) {
            $updates['total_size_bytes'] = $totalSizeBytes;
        }

        // Calculate progress percentage
        if ($this->total_emails > 0) {
            $updates['progress_percentage'] = round(($emailsBackedUp / $this->total_emails) * 100, 2);
        }

        $this->update($updates);
    }

    public function addMailboxBackedUp(string $mailbox): void
    {
        $mailboxes = $this->mailboxes_backed_up ?? [];
        if (!in_array($mailbox, $mailboxes)) {
            $mailboxes[] = $mailbox;
            $this->update(['mailboxes_backed_up' => $mailboxes]);
        }
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (!$model->job_type) {
                $model->job_type = self::TYPE_MANUAL;
            }
        });
    }
}