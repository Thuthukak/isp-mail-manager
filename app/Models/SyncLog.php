<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\Attribute;

class SyncLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'operation_type',
        'email_address',
        'status',
        'started_at',
        'completed_at',
        'files_processed',
        'files_success',
        'files_failed',
        'total_size_processed',
        'details',
        'error_message',
        'job_id',
        'progress_percentage',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'details' => 'array',
        'files_processed' => 'integer',
        'files_success' => 'integer',
        'files_failed' => 'integer',
        'total_size_processed' => 'integer',
        'progress_percentage' => 'float',
    ];

    // Enum values for operation_type
    const OPERATION_INITIAL_BACKUP = 'initial_backup';
    const OPERATION_DAILY_SYNC = 'daily_sync';
    const OPERATION_FORCE_SYNC = 'force_sync';
    const OPERATION_RESTORATION = 'restoration';
    const OPERATION_PURGE = 'purge';
    const OPERATION_SIZE_CHECK = 'size_check';

    // Enum values for status
    const STATUS_STARTED = 'started';
    const STATUS_PROCESSING = 'processing';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';
    const STATUS_CANCELLED = 'cancelled';

    /**
     * Get all available operation types
     */
    public static function getOperationTypes(): array
    {
        return [
            self::OPERATION_INITIAL_BACKUP => 'Initial Backup',
            self::OPERATION_DAILY_SYNC => 'Daily Sync',
            self::OPERATION_FORCE_SYNC => 'Force Sync',
            self::OPERATION_RESTORATION => 'Restoration',
            self::OPERATION_PURGE => 'Purge',
            self::OPERATION_SIZE_CHECK => 'Size Check',
        ];
    }

    /**
     * Get all available statuses
     */
    public static function getStatuses(): array
    {
        return [
            self::STATUS_STARTED => 'Started',
            self::STATUS_PROCESSING => 'Processing',
            self::STATUS_COMPLETED => 'Completed',
            self::STATUS_FAILED => 'Failed',
            self::STATUS_CANCELLED => 'Cancelled',
        ];
    }

    /**
     * Get the duration of the sync operation
     */
    protected function duration(): Attribute
    {
        return Attribute::make(
            get: function () {
                if (!$this->started_at || !$this->completed_at) {
                    return null;
                }
                return $this->completed_at->diffInSeconds($this->started_at);
            }
        );
    }

    /**
     * Get formatted duration
     */
    protected function formattedDuration(): Attribute
    {
        return Attribute::make(
            get: function () {
                $duration = $this->duration;
                if ($duration === null) {
                    return null;
                }
                return gmdate('H:i:s', $duration);
            }
        );
    }

    /**
     * Get the success rate percentage
     */
    protected function successRate(): Attribute
    {
        return Attribute::make(
            get: function () {
                if ($this->files_processed === 0) {
                    return 0;
                }
                return round(($this->files_success / $this->files_processed) * 100, 2);
            }
        );
    }

    /**
     * Get formatted file size
     */
    protected function formattedTotalSize(): Attribute
    {
        return Attribute::make(
            get: function () {
                return $this->formatBytes($this->total_size_processed);
            }
        );
    }

    /**
     * Check if the sync is currently running
     */
    public function isRunning(): bool
    {
        return in_array($this->status, [self::STATUS_STARTED, self::STATUS_PROCESSING]);
    }

    /**
     * Check if the sync is completed successfully
     */
    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Check if the sync failed
     */
    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * Check if the sync was cancelled
     */
    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    /**
     * Scope for filtering by operation type
     */
    public function scopeByOperationType($query, $operationType)
    {
        return $query->where('operation_type', $operationType);
    }

    /**
     * Scope for filtering by status
     */
    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope for running operations
     */
    public function scopeRunning($query)
    {
        return $query->whereIn('status', [self::STATUS_STARTED, self::STATUS_PROCESSING]);
    }

    /**
     * Scope for completed operations
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    /**
     * Scope for failed operations
     */
    public function scopeFailed($query)
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    /**
     * Scope for operations by email address
     */
    public function scopeByEmail($query, $email)
    {
        return $query->where('email_address', $email);
    }

    /**
     * Format bytes to human readable format
     */
    private function formatBytes($bytes, $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, $precision) . ' ' . $units[$i];
    }
}