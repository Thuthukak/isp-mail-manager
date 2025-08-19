<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MailRestoration extends Model
{
    use HasFactory;

    protected $fillable = [
        'email_address',
        'restoration_year',
        'restoration_month',
        'restoration_date',
        'status',
        'files_to_restore',
        'files_restored',
        'files_skipped',
        'total_size',
        'initiated_by',
        'filter_criteria',
        'error_message',
        'completed_at',
    ];

    protected $casts = [
        'restoration_date' => 'datetime',
        'completed_at' => 'datetime',
        'restoration_year' => 'integer',
        'restoration_month' => 'integer',
        'files_to_restore' => 'integer',
        'files_restored' => 'integer',
        'files_skipped' => 'integer',
        'total_size' => 'integer',
        'filter_criteria' => 'array',
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_PARTIAL = 'partial';

    public static function getStatusOptions(): array
    {
        return [
            self::STATUS_PENDING => 'Pending',
            self::STATUS_PROCESSING => 'Processing',
            self::STATUS_COMPLETED => 'Completed',
            self::STATUS_FAILED => 'Failed',
            self::STATUS_PARTIAL => 'Partial',
        ];
    }

    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => 'warning',
            self::STATUS_PROCESSING => 'info',
            self::STATUS_COMPLETED => 'success',
            self::STATUS_FAILED => 'danger',
            self::STATUS_PARTIAL => 'warning',
            default => 'secondary',
        };
    }

    public function getTotalSizeHumanAttribute(): string
    {
        if (!$this->total_size) {
            return 'Unknown';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = $this->total_size;
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, 2) . ' ' . $units[$i];
    }

    public function getProgressPercentageAttribute(): float
    {
        if ($this->files_to_restore === 0) {
            return 0;
        }
        
        return round(($this->files_restored / $this->files_to_restore) * 100, 2);
    }

    public function getRestorationPeriodAttribute(): string
    {
        return sprintf('%04d-%02d', $this->restoration_year, $this->restoration_month);
    }

    public function scopeForEmail($query, string $email)
    {
        return $query->where('email_address', $email);
    }

    public function scopeByYear($query, int $year)
    {
        return $query->where('restoration_year', $year);
    }

    public function scopeByMonth($query, int $month)
    {
        return $query->where('restoration_month', $month);
    }

    public function scopeByPeriod($query, int $year, int $month)
    {
        return $query->where('restoration_year', $year)
                    ->where('restoration_month', $month);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    public function scopeInProgress($query)
    {
        return $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_PROCESSING]);
    }

    public function syncLogs(): HasMany
    {
        return $this->hasMany(SyncLog::class, 'email_address', 'email_address')
                    ->where('operation_type', 'restoration');
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isInProgress(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_PROCESSING]);
    }

    public function canRetry(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function markAsCompleted(): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);
    }

    public function markAsFailed(string $errorMessage): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'error_message' => $errorMessage,
            'completed_at' => now(),
        ]);
    }

    public function markAsPartial(): void
    {
        $this->update([
            'status' => self::STATUS_PARTIAL,
            'completed_at' => now(),
        ]);
    }

    public function updateProgress(int $restored, int $skipped = 0): void
    {
        $this->update([
            'files_restored' => $restored,
            'files_skipped' => $skipped,
        ]);
    }
}
