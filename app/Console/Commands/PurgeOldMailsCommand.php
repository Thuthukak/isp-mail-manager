<?php

namespace App\Console\Commands;

use App\Jobs\PurgeOldMailsJob;
use App\Models\SyncConfiguration;
use App\Models\SyncLog;
use App\Services\MailServerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PurgeOldMailsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mail:purge-old
                            {--mailbox= : Specific mailbox to purge}
                            {--days= : Days old to purge (overrides config)}
                            {--dry-run : Show what would be purged without actually purging}
                            {--batch-size=100 : Number of files to process per batch}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Purge old mail files that have been backed up to OneDrive';

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
        $dryRun = $this->option('dry-run');
        $operation = $dryRun ? 'Dry Run - Purge Old Mails' : 'Purging Old Mails';
        
        $this->info("Starting {$operation}...");
        
        $syncLog = SyncLog::create([
            'operation_type' => $dryRun ? 'purge_old_dry_run' : 'purge_old',
            'status' => 'running',
            'details' => json_encode([
                'mailbox' => $this->option('mailbox'),
                'days' => $this->option('days'),
                'dry_run' => $dryRun,
                'batch_size' => $this->option('batch-size')
            ]),
            'started_at' => now(),
        ]);

        try {
            $mailbox = $this->option('mailbox');
            $batchSize = (int) $this->option('batch-size');
            $days = $this->getPurgeDays();

            $this->info("Purging files older than {$days} days");
            
            if ($dryRun) {
                $this->warn('DRY RUN MODE - No files will actually be deleted');
            }

            if (!$dryRun && !$this->confirm('Are you sure you want to purge old mail files? This action cannot be undone.')) {
                $this->info('Purge operation cancelled.');
                return self::SUCCESS;
            }

            $totalFiles = 0;
            $totalSize = 0;

            if ($mailbox) {
                $this->info("Processing mailbox: {$mailbox}");
                [$files, $size] = $this->processMailbox($mailbox, $days, $batchSize, $dryRun);
                $totalFiles += $files;
                $totalSize += $size;
            } else {
                $this->info('Processing all mailboxes...');
                $mailboxes = $this->mailServerService->getAllMailboxes();
                
                $this->withProgressBar($mailboxes, function ($mailbox) use ($days, $batchSize, $dryRun, &$totalFiles, &$totalSize) {
                    [$files, $size] = $this->processMailbox($mailbox, $days, $batchSize, $dryRun);
                    $totalFiles += $files;
                    $totalSize += $size;
                });
            }

            $syncLog->update([
                'status' => 'completed',
                'details' => json_encode([
                    'total_files' => $totalFiles,
                    'total_size_mb' => round($totalSize / 1024 / 1024, 2),
                    'dry_run' => $dryRun
                ]),
                'completed_at' => now(),
            ]);

            $this->newLine();
            $action = $dryRun ? 'would be purged' : 'purged';
            $this->info("Total files {$action}: {$totalFiles}");
            $this->info("Total size {$action}: " . $this->formatBytes($totalSize));
            $this->info('Purge operation completed successfully!');
            
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

            $this->error("Purge operation failed: {$e->getMessage()}");
            Log::error('Purge operation failed', ['exception' => $e]);
            return self::FAILURE;
        }
    }

    private function getPurgeDays(): int
    {
        if ($days = $this->option('days')) {
            return (int) $days;
        }

        $config = SyncConfiguration::where('key', 'purge_after_days')->first();
        return $config ? (int) $config->value : 30; // Default 30 days
    }

    private function processMailbox(string $mailbox, int $days, int $batchSize, bool $dryRun): array
    {
        $cutoffDate = now()->subDays($days);
        $files = $this->mailServerService->getOldBackedUpFiles($mailbox, $cutoffDate);
        
        if (empty($files)) {
            $this->warn("No old files found for mailbox: {$mailbox}");
            return [0, 0];
        }

        $totalSize = array_sum(array_column($files, 'size'));
        $chunks = array_chunk($files, $batchSize);
        
        foreach ($chunks as $chunk) {
            PurgeOldMailsJob::dispatch($mailbox, $chunk, $dryRun);
        }

        $action = $dryRun ? 'would queue' : 'queued';
        $this->info("Found " . count($files) . " old files, {$action} " . count($chunks) . " batches for mailbox: {$mailbox}");
        
        return [count($files), $totalSize];
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));
        return round($bytes, 2) . ' ' . $units[$pow];
    }
}