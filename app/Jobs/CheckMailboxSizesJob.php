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
                    $sizeMB = round($sizeInfo['size'] / 1024 / 1024, 2);
                    
                    // Get threshold for this mailbox
                    $threshold = $this->getThresholdForMailbox($mailboxPath);
                    
                    Log::debug('Mailbox size check', [
                        'mailbox' => $mailboxPath,
                        'size_mb' => $sizeMB,
                        'threshold_mb' => $threshold,
                        'file_count' => $sizeInfo['file_count']
                    ]);

                    // Check if threshold is exceeded
                    if ($sizeMB > $threshold) {
                        // Check if there's already an active alert
                        $existingAlert = MailboxAlert::where('mailbox', $mailboxPath)
                            ->whereNull('resolved_at')
                            ->first();

                        if (!$existingAlert) {
                            // Create new alert
                            $alert = MailboxAlert::create([
                                'mailbox' => $mailboxPath,
                                'size_mb' => $sizeMB,
                                'threshold_mb' => $threshold,
                                'alerted_at' => now(),
                            ]);

                            $alertsCreated++;

                            // Send notification
                            $this->sendSizeAlert($alert, $sizeInfo);

                            Log::warning('Mailbox size threshold exceeded', [
                                'mailbox' => $mailboxPath,
                                'size_mb' => $sizeMB,
                                'threshold_mb' => $threshold,
                                'alert_id' => $alert->id
                            ]);
                        } else {
                            // Update existing alert with current size
                            $existingAlert->update([
                                'size_mb' => $sizeMB,
                            ]);
                        }
                    } else {
                        // Check if we need to resolve any existing alerts
                        $activeAlerts = MailboxAlert::where('mailbox', $mailboxPath)
                            ->whereNull('resolved_at')
                            ->get();

                        foreach ($activeAlerts as $alert) {
                            $alert->update(['resolved_at' => now()]);
                            $alertsResolved++;
                            
                            Log::info('Mailbox size alert resolved', [
                                'mailbox' => $mailboxPath,
                                'current_size_mb' => $sizeMB,
                                'threshold_mb' => $threshold,
                                'alert_id' => $alert->id
                            ]);
                        }
                    }

                    // Update monitoring metrics
                    $monitorService->updateMailboxMetrics($mailboxPath, [
                        'size_mb' => $sizeMB,
                        'file_count' => $sizeInfo['file_count'],
                        'last_checked' => now(),
                        'threshold_mb' => $threshold,
                        'status' => $sizeMB > $threshold ? 'over_threshold' : 'normal'
                    ]);

                } catch (\Exception $e) {
                    $errors++;
                    Log::error('Error checking mailbox size', [
                        'mailbox' => $mailboxPath,
                        'error' => $e->getMessage()
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