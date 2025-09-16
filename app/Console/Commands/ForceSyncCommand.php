<?php

namespace App\Console\Commands;

use App\Jobs\ForceSyncJob;
use App\Models\SyncLog;
use App\Services\MailServerService;
use App\Services\OneDrivePersonalService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ForceSyncCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mail:force-sync
                            {--mailbox= : Specific mailbox to force sync}
                            {--verify : Verify existing backups and re-upload if corrupted}
                            {--batch-size=50 : Number of files to process per batch}
                            {--skip-confirmation : Skip confirmation prompts}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Force synchronization of all mail files, ignoring previous sync status';

    public function __construct(
        private MailServerService $mailServerService,
        private OneDrivePersonalService $oneDriveService
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting Force Sync Operation...');
        
        $syncLog = SyncLog::create([
            'operation_type' => 'force_sync',
            'status' => 'running',
            'details' => json_encode([
                'mailbox' => $this->option('mailbox'),
                'verify' => $this->option('verify'),
                'batch_size' => $this->option('batch-size')
            ]),
            'started_at' => now(),
        ]);

        try {
            // Connection test
            if (!$this->testConnections()) {
                throw new \Exception('Connection test failed. Cannot proceed with force sync.');
            }

            $mailbox = $this->option('mailbox');
            $verify = $this->option('verify');
            $batchSize = (int) $this->option('batch-size');
            $skipConfirmation = $this->option('skip-confirmation');

            if (!$skipConfirmation) {
                $message = $verify 
                    ? 'This will verify and re-upload all corrupted files. This may take a long time.'
                    : 'This will force sync ALL files, potentially uploading duplicates. This may take a long time.';
                
                if (!$this->confirm($message . ' Continue?')) {
                    $this->info('Force sync cancelled.');
                    return self::SUCCESS;
                }
            }

            if ($verify) {
                $this->info('Running in verification mode - will check file integrity');
            }

            $totalFiles = 0;

            if ($mailbox) {
                $this->info("Processing mailbox: {$mailbox}");
                $totalFiles += $this->processMailbox($mailbox, $verify, $batchSize);
            } else {
                $this->info('Processing all mailboxes...');
                $mailboxes = $this->mailServerService->getAllMailboxes();
                
                $this->withProgressBar($mailboxes, function ($mailbox) use ($verify, $batchSize, &$totalFiles) {
                    $totalFiles += $this->processMailbox($mailbox, $verify, $batchSize);
                });
            }

            $syncLog->update([
                'status' => 'completed',
                'details' => json_encode([
                    'total_files_processed' => $totalFiles,
                    'verify_mode' => $verify
                ]),
                'completed_at' => now(),
            ]);

            $this->newLine();
            $this->info("Force sync completed! Processed {$totalFiles} files.");
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

            $this->error("Force sync failed: {$e->getMessage()}");
            Log::error('Force sync failed', ['exception' => $e]);
            return self::FAILURE;
        }
    }

    private function testConnections(): bool
    {
        $this->info('Testing connections...');

        try {
            // Test mail server access
            $this->line('  Testing mail server access...');
            $mailboxes = $this->mailServerService->getAllMailboxes();
            $this->info('  ✓ Mail server accessible (' . count($mailboxes) . ' mailboxes found)');

            // Test OneDrive connection
            $this->line('  Testing OneDrive connection...');
            $this->oneDriveService->testConnection();
            $this->info('  ✓ OneDrive connection successful');

            return true;
        } catch (\Exception $e) {
            $this->error("  ✗ Connection test failed: {$e->getMessage()}");
            return false;
        }
    }

    private function processMailbox(string $mailbox, bool $verify, int $batchSize): int
    {
        $files = $this->mailServerService->getAllMailFiles($mailbox);
        
        if (empty($files)) {
            $this->warn("No files found for mailbox: {$mailbox}");
            return 0;
        }

        $chunks = array_chunk($files, $batchSize);
        
        foreach ($chunks as $chunk) {
            ForceSyncJob::dispatch($mailbox, $chunk, $verify);
        }

        $this->info("Queued " . count($chunks) . " batches for mailbox: {$mailbox}");
        
        return count($files);
    }
}