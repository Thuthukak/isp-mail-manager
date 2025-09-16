<?php

namespace App\Services;

use App\Models\MailboxAlert;
use App\Models\SyncConfiguration;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class MailboxMonitorService
{
    private MailServerService $mailServerService;

    public function __construct(MailServerService $mailServerService)
    {
        $this->mailServerService = $mailServerService;
    }

    /**
     * Check all mailbox sizes and create alerts if needed
     */
    public function checkMailboxSizes(): array
    {
        $defaultThreshold = SyncConfiguration::getValue('mailbox_size_threshold_mb', 1000);
        $mailboxes = $this->mailServerService->getAllMailboxes();
        $alerts = [];

        foreach ($mailboxes as $mailbox) {
            $sizeBytes = $this->mailServerService->getMailboxSize($mailbox);
            $sizeMB = round($sizeBytes / 1024 / 1024, 2);
            $thresholdMB = $this->getMailboxThreshold($mailbox, $defaultThreshold);
            $thresholdBytes = $thresholdMB * 1024 * 1024;

            if ($sizeBytes > $thresholdBytes) {
                $alert = $this->createOrUpdateAlert($mailbox, $sizeBytes, $thresholdBytes);
                $alerts[] = $alert;
            } else {
                $this->resolveAlert($mailbox);
            }
        }

        Log::info("Mailbox size check completed", [
            'checked' => count($mailboxes),
            'alerts' => count($alerts)
        ]);

        return $alerts;
    }

    /**
     * Get threshold for specific mailbox
     */
    private function getMailboxThreshold(string $mailbox, float $default): float
    {
        return SyncConfiguration::getValue("mailbox_threshold_{$mailbox}", $default);
    }

    /**
     * Create or update alert for oversized mailbox
     */
    private function createOrUpdateAlert(string $mailbox, int $sizeBytes, int $thresholdBytes): MailboxAlert
    {
        $existingAlert = MailboxAlert::where('email_address', $mailbox)
            ->whereIn('status', ['active', 'acknowledged'])
            ->first();

        // Determine alert type based on usage percentage
        $usagePercentage = ($sizeBytes / $thresholdBytes) * 100;
        $alertType = $this->determineAlertType($usagePercentage);

        if ($existingAlert) {
            $existingAlert->update([
                'current_size_bytes' => $sizeBytes,
                'threshold_bytes' => $thresholdBytes,
                'alert_type' => $alertType,
                'alert_date' => now() // Update alert date to show recent activity
            ]);
            
            Log::debug('Updated existing mailbox alert', [
                'mailbox' => $mailbox,
                'alert_id' => $existingAlert->id,
                'size_mb' => round($sizeBytes / 1024 / 1024, 2),
                'alert_type' => $alertType,
                'usage_percentage' => round($usagePercentage, 1)
            ]);
            
            return $existingAlert;
        }

        $alert = MailboxAlert::create([
            'email_address' => $mailbox,
            'current_size_bytes' => $sizeBytes,
            'threshold_bytes' => $thresholdBytes,
            'alert_type' => $alertType,
            'alert_date' => now(),
            'status' => 'active'
        ]);

        // Send notification
        $this->sendSizeAlert($alert);

        Log::warning('New mailbox size alert created', [
            'mailbox' => $mailbox,
            'alert_id' => $alert->id,
            'size_mb' => round($sizeBytes / 1024 / 1024, 2),
            'threshold_mb' => round($thresholdBytes / 1024 / 1024, 2),
            'alert_type' => $alertType,
            'usage_percentage' => round($usagePercentage, 1)
        ]);

        return $alert;
    }

    /**
     * Determine alert type based on usage percentage
     */
    private function determineAlertType(float $usagePercentage): string
    {
        if ($usagePercentage >= 100) {
            return 'purge_required';
        } elseif ($usagePercentage >= 95) {
            return 'size_critical';
        } else {
            return 'size_warning';
        }
    }

    /**
     * Resolve alert when mailbox size is back to normal
     */
    private function resolveAlert(string $mailbox): void
    {
        $resolvedCount = MailboxAlert::where('email_address', $mailbox)
            ->whereIn('status', ['active', 'acknowledged'])
            ->update(['status' => 'resolved']);

        if ($resolvedCount > 0) {
            Log::info('Resolved mailbox alerts', [
                'mailbox' => $mailbox,
                'resolved_count' => $resolvedCount
            ]);
        }
    }

    /**
     * Send size alert notification
     */
    private function sendSizeAlert(MailboxAlert $alert): void
    {
        // Get human-readable values for logging and notifications
        $sizeMB = round($alert->current_size_bytes / 1024 / 1024, 2);
        $thresholdMB = round($alert->threshold_bytes / 1024 / 1024, 2);
        $usagePercentage = round(($alert->current_size_bytes / $alert->threshold_bytes) * 100, 1);

        // Implement notification logic here
        // This could be email, Slack, etc.
        Log::warning("Mailbox size alert notification", [
            'mailbox' => $alert->email_address,
            'size_mb' => $sizeMB,
            'threshold_mb' => $thresholdMB,
            'alert_type' => $alert->alert_type,
            'usage_percentage' => $usagePercentage,
            'alert_id' => $alert->id
        ]);

        // You can extend this to send actual notifications:
        // - Email notifications
        // - Slack/Teams notifications
        // - SMS alerts for critical alerts
        // - Dashboard notifications
    }

    /**
     * Get active alerts
     */
    public function getActiveAlerts(): array
    {
        return MailboxAlert::whereIn('status', ['active', 'acknowledged'])
            ->orderBy('alert_date', 'desc')
            ->get()
            ->map(function ($alert) {
                return [
                    'id' => $alert->id,
                    'email_address' => $alert->email_address,
                    'current_size_mb' => round($alert->current_size_bytes / 1024 / 1024, 2),
                    'threshold_mb' => round($alert->threshold_bytes / 1024 / 1024, 2),
                    'usage_percentage' => round(($alert->current_size_bytes / $alert->threshold_bytes) * 100, 1),
                    'alert_type' => $alert->alert_type,
                    'status' => $alert->status,
                    'alert_date' => $alert->alert_date,
                    'acknowledged_by' => $alert->acknowledged_by,
                    'acknowledged_at' => $alert->acknowledged_at,
                ];
            })
            ->toArray();
    }

    /**
     * Get mailbox size summary
     */
    public function getMailboxSizeSummary(): array
    {
        $mailboxes = $this->mailServerService->getAllMailboxes();
        $summary = [];

        foreach ($mailboxes as $mailbox) {
            $sizeBytes = $this->mailServerService->getMailboxSize($mailbox);
            $sizeMB = round($sizeBytes / 1024 / 1024, 2);
            $thresholdMB = $this->getMailboxThreshold($mailbox, 1000);
            $thresholdBytes = $thresholdMB * 1024 * 1024;
            $usagePercentage = round(($sizeBytes / $thresholdBytes) * 100, 1);
            
            // Check if there's an active alert for this mailbox
            $activeAlert = MailboxAlert::where('email_address', $mailbox)
                ->whereIn('status', ['active', 'acknowledged'])
                ->first();

            $status = 'normal';
            $alertType = null;
            
            if ($sizeBytes > $thresholdBytes) {
                $status = 'alert';
                $alertType = $this->determineAlertType($usagePercentage);
            }
            
            $summary[] = [
                'mailbox' => $mailbox,
                'size_mb' => $sizeMB,
                'size_bytes' => $sizeBytes,
                'threshold_mb' => $thresholdMB,
                'threshold_bytes' => $thresholdBytes,
                'percentage_used' => $usagePercentage,
                'status' => $status,
                'alert_type' => $alertType,
                'has_active_alert' => $activeAlert !== null,
                'alert_id' => $activeAlert?->id,
                'alert_status' => $activeAlert?->status,
                'last_alert_date' => $activeAlert?->alert_date,
            ];
        }

        // Sort by usage percentage (highest first)
        usort($summary, function ($a, $b) {
            return $b['percentage_used'] <=> $a['percentage_used'];
        });

        return $summary;
    }

    /**
     * Update mailbox metrics (called from monitoring job)
     */
    public function updateMailboxMetrics(string $mailbox, array $metrics): void
    {
        // This method can be used to store additional metrics
        // in a separate monitoring table or cache for dashboard display
        Log::debug('Mailbox metrics updated', array_merge([
            'mailbox' => $mailbox
        ], $metrics));

        // You could implement:
        // - Store metrics in Redis for quick dashboard access
        // - Update a mailbox_metrics table
        // - Send metrics to monitoring services (Prometheus, etc.)
    }

    /**
     * Get mailbox statistics for dashboard
     */
    public function getMailboxStatistics(): array
    {
        $activeAlerts = MailboxAlert::whereIn('status', ['active', 'acknowledged'])->count();
        $criticalAlerts = MailboxAlert::where('alert_type', 'size_critical')
            ->whereIn('status', ['active', 'acknowledged'])
            ->count();
        $purgeRequiredAlerts = MailboxAlert::where('alert_type', 'purge_required')
            ->whereIn('status', ['active', 'acknowledged'])
            ->count();
        $acknowledgedAlerts = MailboxAlert::where('status', 'acknowledged')->count();

        return [
            'total_active_alerts' => $activeAlerts,
            'critical_alerts' => $criticalAlerts,
            'purge_required_alerts' => $purgeRequiredAlerts,
            'acknowledged_alerts' => $acknowledgedAlerts,
            'warning_alerts' => $activeAlerts - $criticalAlerts - $purgeRequiredAlerts,
        ];
    }

    /**
     * Bulk acknowledge alerts
     */
    public function acknowledgeAlerts(array $alertIds, string $acknowledgedBy, ?string $notes = null): int
    {
        return MailboxAlert::whereIn('id', $alertIds)
            ->where('status', 'active')
            ->update([
                'status' => 'acknowledged',
                'acknowledged_by' => $acknowledgedBy,
                'acknowledged_at' => now(),
                'admin_notes' => $notes
            ]);
    }

    /**
     * Bulk resolve alerts
     */
    public function resolveAlerts(array $alertIds): int
    {
        return MailboxAlert::whereIn('id', $alertIds)
            ->whereIn('status', ['active', 'acknowledged'])
            ->update(['status' => 'resolved']);
    }
}