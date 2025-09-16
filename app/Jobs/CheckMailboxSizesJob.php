<?php

namespace App\Jobs;

use App\Models\MailboxAlert;
use App\Models\SyncLog;
use App\Services\MailServerService;
use App\Services\MailboxMonitorService;
use App\Notifications\MailboxSizeAlert;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class CheckMailboxSizesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600; // 10 minutes timeout
    public $tries = 2;
    public $backoff = [60, 300];

    protected array $mailboxPaths;
    protected array $thresholds;

    public function __construct(array $mailboxPaths = null, array $thresholds = null)
    {
        $this->mailboxPaths = $mailboxPaths ?? config('mail-backup.monitored_mailboxes', []);
        $this->thresholds = $thresholds ?? config('mail-backup.size_thresholds', []);
        
        // Use normal priority queue
        $this->onQueue('default');
    }

    public function handle(
        MailServerService $mailServerService,
        MailboxMonitorService $monitorService
    ): void {
        $syncLog = SyncLog::create([
            'operation_type' => 'check_mailbox_sizes',
            'status' => 'processing',
            'details' => json_encode([
                'mailbox_paths' => $this->mailboxPaths,
                'thresholds' => $this->thresholds
            ]),
            'started_at' => now(),
        ]);

        try {
            Log::info('Starting mailbox size check', [
                'mailboxes_count' => count($this->mailboxPaths),
                'job_id' => $this->job->getJobId()
            ]);

            $checkedMailboxes = 0;
            $alertsCreated = 0;
            $alertsResolved = 0;
            $errors = 0;

            foreach ($this->mailboxPaths as $mailboxPath) {
                try {
                    $checkedMailboxes++;
                    
                    // Get mailbox size
                    $sizeInfo = $mailServerService->getDirectorySize($mailboxPath);
                    $sizeBytes = $sizeInfo['size'];
                    $sizeMB = round($sizeBytes / 1024 / 1024, 2);
                    
                    // Get threshold for this mailbox
                    $thresholdMB = $this->getThresholdForMailbox($mailboxPath);
                    $thresholdBytes = $thresholdMB * 1024 * 1024;
                    
                    Log::debug('Mailbox size check', [
                        'mailbox' => $mailboxPath,
                        'size_mb' => $sizeMB,
                        'threshold_mb' => $thresholdMB,
                        'file_count' => $sizeInfo['file_count']
                    ]);

                    // Determine alert type based on usage percentage
                    $usagePercentage = ($sizeBytes / $thresholdBytes) * 100;
                    $alertType = $this->determineAlertType($usagePercentage);

                    // Check if threshold is exceeded
                    if ($sizeBytes > $thresholdBytes) {
                        // Check if there's already an active alert for this mailbox
                        $existingAlert = MailboxAlert::where('email_address', $mailboxPath)
                            ->whereIn('status', ['active', 'acknowledged'])
                            ->first();

                        if (!$existingAlert) {
                            // Create new alert
                            $alert = MailboxAlert::create([
                                'email_address' => $mailboxPath,
                                'current_size_bytes' => $sizeBytes,
                                'threshold_bytes' => $thresholdBytes,
                                'alert_type' => $alertType,
                                'alert_date' => now(),
                                'status' => 'active',
                            ]);

                            $alertsCreated++;

                            // Send notification
                            $this->sendSizeAlert($alert, $sizeInfo);

                            Log::warning('Mailbox size threshold exceeded', [
                                'mailbox' => $mailboxPath,
                                'size_mb' => $sizeMB,
                                'threshold_mb' => $thresholdMB,
                                'alert_type' => $alertType,
                                'usage_percentage' => round($usagePercentage, 1),
                                'alert_id' => $alert->id
                            ]);
                        } else {
                            // Update existing alert with current size and alert type
                            $existingAlert->update([
                                'current_size_bytes' => $sizeBytes,
                                'threshold_bytes' => $thresholdBytes,
                                'alert_type' => $alertType,
                                'alert_date' => now(), // Update alert date to show recent activity
                            ]);

                            Log::debug('Updated existing alert', [
                                'mailbox' => $mailboxPath,
                                'alert_id' => $existingAlert->id,
                                'new_size_mb' => $sizeMB,
                                'alert_type' => $alertType
                            ]);
                        }
                    } else {
                        // Check if we need to resolve any existing alerts
                        $activeAlerts = MailboxAlert::where('email_address', $mailboxPath)
                            ->whereIn('status', ['active', 'acknowledged'])
                            ->get();

                        foreach ($activeAlerts as $alert) {
                            $alert->update([
                                'status' => 'resolved',
                                'current_size_bytes' => $sizeBytes, // Update with current size
                            ]);
                            $alertsResolved++;
                            
                            Log::info('Mailbox size alert resolved', [
                                'mailbox' => $mailboxPath,
                                'current_size_mb' => $sizeMB,
                                'threshold_mb' => $thresholdMB,
                                'alert_id' => $alert->id,
                                'previous_alert_type' => $alert->alert_type
                            ]);
                        }
                    }

                    // Update monitoring metrics
                    $monitorService->updateMailboxMetrics($mailboxPath, [
                        'size_mb' => $sizeMB,
                        'size_bytes' => $sizeBytes,
                        'file_count' => $sizeInfo['file_count'],
                        'last_checked' => now(),
                        'threshold_mb' => $thresholdMB,
                        'threshold_bytes' => $thresholdBytes,
                        'usage_percentage' => $usagePercentage,
                        'alert_type' => $sizeBytes > $thresholdBytes ? $alertType : null,
                        'status' => $sizeBytes > $thresholdBytes ? 'over_threshold' : 'normal'
                    ]);

                } catch (\Exception $e) {
                    $errors++;
                    Log::error('Error checking mailbox size', [
                        'mailbox' => $mailboxPath,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                }
            }

            // Update sync log with results
            $syncLog->update([
                'status' => $errors === 0 ? 'completed' : 'completed_with_errors',
                'details' => json_encode([
                    'checked_mailboxes' => $checkedMailboxes,
                    'alerts_created' => $alertsCreated,
                    'alerts_resolved' => $alertsResolved,
                    'errors' => $errors,
                    'mailbox_paths' => $this->mailboxPaths
                ]),
                'completed_at' => now(),
            ]);

            Log::info('Mailbox size check completed', [
                'checked_mailboxes' => $checkedMailboxes,
                'alerts_created' => $alertsCreated,
                'alerts_resolved' => $alertsResolved,
                'errors' => $errors
            ]);

        } catch (\Exception $e) {
            Log::error('Check mailbox sizes job failed', [
                'mailbox_paths' => $this->mailboxPaths,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $syncLog->update([
                'status' => 'failed',
                'details' => json_encode([
                    'error' => $e->getMessage(),
                    'mailbox_paths' => $this->mailboxPaths
                ]),
                'completed_at' => now(),
            ]);

            throw $e;
        }
    }

    private function getThresholdForMailbox(string $mailboxPath): int
    {
        // Check for mailbox-specific threshold
        foreach ($this->thresholds as $pattern => $threshold) {
            if (fnmatch($pattern, $mailboxPath)) {
                return $threshold;
            }
        }
        
        // Return default threshold
        return config('mail-backup.default_size_threshold_mb', 1000);
    }

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

    private function sendSizeAlert(MailboxAlert $alert, array $sizeInfo): void
    {
        try {
            $recipients = config('mail-backup.alert_recipients', []);
            
            if (!empty($recipients)) {
                Notification::route('mail', $recipients)
                    ->notify(new MailboxSizeAlert($alert, $sizeInfo));
            }
        } catch (\Exception $e) {
            Log::error('Failed to send mailbox size alert notification', [
                'alert_id' => $alert->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('CheckMailboxSizesJob permanently failed', [
            'mailbox_paths' => $this->mailboxPaths,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts()
        ]);

        // Update any existing sync log
        SyncLog::where('operation_type', 'check_mailbox_sizes')
            ->where('status', 'processing')
            ->latest()
            ->first()
            ?->update([
                'status' => 'failed',
                'completed_at' => now(),
                'details' => json_encode([
                    'error' => 'Job permanently failed: ' . $exception->getMessage(),
                    'mailbox_paths' => $this->mailboxPaths
                ])
            ]);
    }
}