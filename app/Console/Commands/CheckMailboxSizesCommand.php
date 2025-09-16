<?php

namespace App\Console\Commands;

use App\Jobs\CheckMailboxSizesJob;
use App\Models\MailboxAlert;
use App\Models\SyncConfiguration;
use App\Models\SyncLog;
use App\Services\MailServerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class CheckMailboxSizesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mail:check-sizes
                            {--mailbox= : Specific mailbox to check}
                            {--threshold= : Size threshold in MB (overrides config)}
                            {--alert : Send alerts for mailboxes over threshold}
                            {--resolve-alerts : Mark existing alerts as resolved if under threshold}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check mailbox sizes and optionally send alerts for large mailboxes';

    public function __construct(
        private MailServerService $mailServerService
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting Mailbox Size Check...');
        
        $syncLog = SyncLog::create([
            'operation_type' => 'size_check',
            'status' => 'started',
            'details' => json_encode([
                'mailbox' => $this->option('mailbox'),
                'threshold' => $this->option('threshold'),
                'alert' => $this->option('alert'),
                'resolve_alerts' => $this->option('resolve-alerts')
            ]),
            'started_at' => now(),
        ]);

        try {
            $mailbox = $this->option('mailbox');
            $threshold = $this->getSizeThreshold();
            $sendAlerts = $this->option('alert');
            $resolveAlerts = $this->option('resolve-alerts');

            $this->info("Checking against threshold: {$threshold} MB");

            $results = [];

            if ($mailbox) {
                $this->info("Checking mailbox: {$mailbox}");
                $results = [$this->checkMailbox($mailbox, $threshold, $sendAlerts, $resolveAlerts)];
            } else {
                $this->info('Checking all mailboxes...');
                $mailboxes = $this->mailServerService->getAllMailboxes();
                
                $results = [];
                $this->withProgressBar($mailboxes, function ($mailbox) use ($threshold, $sendAlerts, $resolveAlerts, &$results) {
                    $results[] = $this->checkMailbox($mailbox, $threshold, $sendAlerts, $resolveAlerts);
                });
            }

            // Process results in background job for alerts/notifications
            if ($sendAlerts || $resolveAlerts) {
                CheckMailboxSizesJob::dispatch($results, $threshold, $sendAlerts, $resolveAlerts);
            }

            $this->displayResults($results, $threshold);

            $syncLog->update([
                'status' => 'completed',
                'details' => json_encode([
                    'total_mailboxes_checked' => count($results),
                    'over_threshold' => count(array_filter($results, fn($r) => $r['over_threshold'])),
                    'threshold_mb' => $threshold
                ]),
                'completed_at' => now(),
            ]);

            $this->info('Mailbox size check completed successfully!');
            return self::SUCCESS;

        } catch (\Exception $e) {
            $syncLog->update([
                'status' => 'failed',
                'details' => json_encode([
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]),
                'completed_at' => now(),
            ]);

            $this->error("Mailbox size check failed: {$e->getMessage()}");
            Log::error('Mailbox size check failed', ['exception' => $e]);
            return self::FAILURE;
        }
    }

    private function getSizeThreshold(): int
    {
        if ($threshold = $this->option('threshold')) {
            return (int) $threshold;
        }

        $config = SyncConfiguration::where('key', 'mailbox_size_threshold_mb')->first();
        return $config ? (int) $config->value : 1000; // Default 1GB
    }

    private function checkMailbox(string $mailbox, int $threshold, bool $sendAlerts, bool $resolveAlerts): array
    {
        $sizeInfo = $this->mailServerService->getMailboxSize($mailbox);
        $sizeMB = round($sizeInfo['size'] / 1024 / 1024, 2);
        $overThreshold = $sizeMB > $threshold;

        $result = [
            'mailbox' => $mailbox,
            'size_mb' => $sizeMB,
            'file_count' => $sizeInfo['file_count'],
            'threshold_mb' => $threshold,
            'over_threshold' => $overThreshold,
            'checked_at' => now()
        ];

        // Handle existing alerts
        if ($resolveAlerts && !$overThreshold) {
            $this->resolveExistingAlerts($mailbox);
        }

        if ($sendAlerts && $overThreshold) {
            $this->createOrUpdateAlert($mailbox, $sizeMB, $threshold);
        }

        return $result;
    }

    private function resolveExistingAlerts(string $mailbox): void
    {
        MailboxAlert::where('email_address', $mailbox)
            ->whereIn('status', ['active', 'acknowledged'])
            ->update(['status' => 'resolved']);
    }

    private function createOrUpdateAlert(string $mailbox, float $sizeMB, int $threshold): void
    {
        $sizeBytes = $sizeMB * 1024 * 1024;
        $thresholdBytes = $threshold * 1024 * 1024;

        $existingAlert = MailboxAlert::where('email_address', $mailbox)
            ->whereIn('status', ['active', 'acknowledged'])
            ->first();

        if (!$existingAlert) {
            // Determine alert type based on usage percentage
            $usagePercentage = ($sizeBytes / $thresholdBytes) * 100;
            $alertType = match (true) {
                $usagePercentage >= 95 => 'size_critical',
                $usagePercentage >= 80 => 'size_warning',
                default => 'size_warning'
            };

            MailboxAlert::create([
                'email_address' => $mailbox,
                'current_size_bytes' => $sizeBytes,
                'threshold_bytes' => $thresholdBytes,
                'alert_type' => $alertType,
                'alert_date' => now(),
                'status' => 'active',
            ]);
        } else {
            // Update existing alert with current size
            $usagePercentage = ($sizeBytes / $thresholdBytes) * 100;
            $alertType = match (true) {
                $usagePercentage >= 95 => 'size_critical',
                $usagePercentage >= 80 => 'size_warning',
                default => 'size_warning'
            };

            $existingAlert->update([
                'current_size_bytes' => $sizeBytes,
                'threshold_bytes' => $thresholdBytes,
                'alert_type' => $alertType,
            ]);
        }
    }

    private function displayResults(array $results, int $threshold): void
    {
        $this->newLine();
        
        // Summary
        $totalMailboxes = count($results);
        $overThreshold = array_filter($results, fn($r) => $r['over_threshold']);
        $overThresholdCount = count($overThreshold);
        
        $this->info("=== Mailbox Size Check Summary ===");
        $this->info("Total mailboxes checked: {$totalMailboxes}");
        $this->info("Mailboxes over threshold ({$threshold} MB): {$overThresholdCount}");
        
        if ($overThresholdCount > 0) {
            $this->newLine();
            $this->warn("Mailboxes exceeding threshold:");
            
            $headers = ['Mailbox', 'Size (MB)', 'Files', 'Over by (MB)'];
            $tableData = [];
            
            foreach ($overThreshold as $result) {
                $tableData[] = [
                    $result['mailbox'],
                    number_format($result['size_mb'], 2),
                    number_format($result['file_count']),
                    number_format($result['size_mb'] - $threshold, 2)
                ];
            }
            
            $this->table($headers, $tableData);
        }

        // Show largest mailboxes regardless of threshold
        $this->newLine();
        $this->info("Top 5 largest mailboxes:");
        
        usort($results, fn($a, $b) => $b['size_mb'] <=> $a['size_mb']);
        $top5 = array_slice($results, 0, 5);
        
        $headers = ['Rank', 'Mailbox', 'Size (MB)', 'Files', 'Status'];
        $tableData = [];
        
        foreach ($top5 as $index => $result) {
            $status = $result['over_threshold'] ? '⚠️  Over' : '✅ OK';
            $tableData[] = [
                $index + 1,
                $result['mailbox'],
                number_format($result['size_mb'], 2),
                number_format($result['file_count']),
                $status
            ];
        }
        
        $this->table($headers, $tableData);
    }
}