<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\Attribute;

class MailboxAlert extends Model
{
    use HasFactory;

    protected $fillable = [
        'email_address',
        'current_size_bytes',
        'threshold_bytes',
        'alert_type',
        'alert_date',
        'status',
        'acknowledged_by',
        'acknowledged_at',
        'admin_notes',
        'purge_approved',
        'purge_approved_by',
        'purge_approved_at',
    ];

    protected $casts = [
        'alert_date' => 'datetime',
        'acknowledged_at' => 'datetime',
        'purge_approved_at' => 'datetime',
        'current_size_bytes' => 'integer',
        'threshold_bytes' => 'integer',
        'purge_approved' => 'boolean',
    ];

    // Enum values for alert_type
    const ALERT_SIZE_WARNING = 'size_warning';
    const ALERT_SIZE_CRITICAL = 'size_critical';
    const ALERT_PURGE_REQUIRED = 'purge_required';

    // Enum values for status
    const STATUS_ACTIVE = 'active';
    const STATUS_ACKNOWLEDGED = 'acknowledged';
    const STATUS_RESOLVED = 'resolved';
    const STATUS_IGNORED = 'ignored';

    /**
     * Get all available alert types
     */
    public static function getAlertTypes(): array
    {
        return [
            self::ALERT_SIZE_WARNING => 'Size Warning',
            self::ALERT_SIZE_CRITICAL => 'Size Critical',
            self::ALERT_PURGE_REQUIRED => 'Purge Required',
        ];
    }

    /**
     * Get all available statuses
     */
    public static function getStatuses(): array
    {
        return [
            self::STATUS_ACTIVE => 'Active',
            self::STATUS_ACKNOWLEDGED => 'Acknowledged',
            self::STATUS_RESOLVED => 'Resolved',
            self::STATUS_IGNORED => 'Ignored',
        ];
    }

    /**
     * Get the current size in MB
     */
    protected function sizeMb(): Attribute
    {
        return Attribute::make(
            get: fn () => round($this->current_size_bytes / 1024 / 1024, 2)
        );
    }

    /**
     * Get the threshold size in MB
     */
    protected function thresholdMb(): Attribute
    {
        return Attribute::make(
            get: fn () => round($this->threshold_bytes / 1024 / 1024, 2)
        );
    }

    /**
     * Get the usage percentage
     */
    protected function usagePercentage(): Attribute
    {
        return Attribute::make(
            get: function () {
                if ($this->threshold_bytes === 0) {
                    return 0;
                }
                return round(($this->current_size_bytes / $this->threshold_bytes) * 100, 2);
            }
        );
    }

    /**
     * Get formatted current size
     */
    protected function formattedCurrentSize(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->formatBytes($this->current_size_bytes)
        );
    }

    /**
     * Get formatted threshold size
     */
    protected function formattedThresholdSize(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->formatBytes($this->threshold_bytes)
        );
    }

    /**
     * Get the severity level based on usage percentage
     */
    protected function severityLevel(): Attribute
    {
        return Attribute::make(
            get: function () {
                $percentage = $this->usage_percentage;
                
                if ($percentage >= 95) {
                    return 'critical';
                } elseif ($percentage >= 80) {
                    return 'warning';
                } else {
                    return 'normal';
                }
            }
        );
    }

    /**
     * Get the color based on severity
     */
    protected function severityColor(): Attribute
    {
        return Attribute::make(
            get: function () {
                return match ($this->severity_level) {
                    'critical' => 'danger',
                    'warning' => 'warning',
                    'normal' => 'success',
                    default => 'gray',
                };
            }
        );
    }

    /**
     * Get resolved at timestamp (for compatibility with existing resource)
     */
    protected function resolvedAt(): Attribute
    {
        return Attribute::make(
            get: function () {
                return $this->status === self::STATUS_RESOLVED ? $this->updated_at : null;
            }
        );
    }

    /**
     * Check if alert is active
     */
    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Check if alert is acknowledged
     */
    public function isAcknowledged(): bool
    {
        return $this->status === self::STATUS_ACKNOWLEDGED;
    }

    /**
     * Check if alert is resolved
     */
    public function isResolved(): bool
    {
        return $this->status === self::STATUS_RESOLVED;
    }

    /**
     * Check if alert is ignored
     */
    public function isIgnored(): bool
    {
        return $this->status === self::STATUS_IGNORED;
    }

    /**
     * Check if purge is approved
     */
    public function isPurgeApproved(): bool
    {
        return $this->purge_approved;
    }

    /**
     * Check if alert is critical
     */
    public function isCritical(): bool
    {
        return $this->alert_type === self::ALERT_SIZE_CRITICAL || 
               $this->alert_type === self::ALERT_PURGE_REQUIRED;
    }

    /**
     * Acknowledge the alert
     */
    public function acknowledge($acknowledgedBy = null): void
    {
        $this->update([
            'status' => self::STATUS_ACKNOWLEDGED,
            'acknowledged_by' => $acknowledgedBy,
            'acknowledged_at' => now(),
        ]);
    }

    /**
     * Resolve the alert
     */
    public function resolve(): void
    {
        $this->update([
            'status' => self::STATUS_RESOLVED,
        ]);
    }

    /**
     * Ignore the alert
     */
    public function ignore(): void
    {
        $this->update([
            'status' => self::STATUS_IGNORED,
        ]);
    }

    /**
     * Approve purge
     */
    public function approvePurge($approvedBy = null): void
    {
        $this->update([
            'purge_approved' => true,
            'purge_approved_by' => $approvedBy,
            'purge_approved_at' => now(),
        ]);
    }

    /**
     * Scope for active alerts
     */
    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * Scope for acknowledged alerts
     */
    public function scopeAcknowledged($query)
    {
        return $query->where('status', self::STATUS_ACKNOWLEDGED);
    }

    /**
     * Scope for resolved alerts
     */
    public function scopeResolved($query)
    {
        return $query->where('status', self::STATUS_RESOLVED);
    }

    /**
     * Scope for ignored alerts
     */
    public function scopeIgnored($query)
    {
        return $query->where('status', self::STATUS_IGNORED);
    }

    /**
     * Scope for unresolved alerts (active or acknowledged)
     */
    public function scopeUnresolved($query)
    {
        return $query->whereIn('status', [self::STATUS_ACTIVE, self::STATUS_ACKNOWLEDGED]);
    }

    /**
     * Scope for critical alerts
     */
    public function scopeCritical($query)
    {
        return $query->whereIn('alert_type', [self::ALERT_SIZE_CRITICAL, self::ALERT_PURGE_REQUIRED]);
    }

    /**
     * Scope for alerts by email address
     */
    public function scopeByEmail($query, $email)
    {
        return $query->where('email_address', $email);
    }

    /**
     * Scope for alerts by alert type
     */
    public function scopeByType($query, $type)
    {
        return $query->where('alert_type', $type);
    }

    /**
     * Scope for alerts above usage percentage
     */
    public function scopeAboveUsage($query, $percentage)
    {
        return $query->whereRaw('(current_size_bytes / threshold_bytes) * 100 >= ?', [$percentage]);
    }

    /**
     * Scope for purge approved alerts
     */
    public function scopePurgeApproved($query)
    {
        return $query->where('purge_approved', true);
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