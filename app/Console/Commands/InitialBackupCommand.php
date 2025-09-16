<?php

namespace App\Console\Commands;

use App\Jobs\ProcessInitialBackupJob;
use App\Models\SyncLog;
use App\Services\MailServerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class InitialBackupCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mail:initial-backup 
                            {--mailbox= : Specific mailbox to backup}
                            {--batch-size=100 : Number of files to process per batch}
                            {--force : Force backup even if already exists}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Perform initial backup of mail server files to OneDrive';

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
        $this->info('Starting Initial Mail Backup...');
        
        $syncLog = SyncLog::create([
            'operation_type' => 'initial_backup',
            'status' => 'running',
            'details' => json_encode([
                'mailbox' => $this->option('mailbox'),
                'batch_size' => $this->option('batch-size'),
                'force' => $this->option('force')
            ]),
            'started_at' => now(),
        ]);

        try {
            $mailbox = $this->option('mailbox');
            $batchSize = (int) $this->option('batch-size');
            $force = $this->option('force');

            if ($mailbox) {
                $this->info("Processing mailbox: {$mailbox}");
                $this->processMailbox($mailbox, $batchSize, $force);
            } else {
                $this->info('Processing all mailboxes...');
                $mailboxes = $this->mailServerService->getAllMailboxes();
                
                $this->withProgressBar($mailboxes, function ($mailbox) use ($batchSize, $force) {
                    $this->processMailbox($mailbox, $batchSize, $force);
                });
            }

            $syncLog->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);

            $this->newLine();
            $this->info('Initial backup completed successfully!');
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

            $this->error("Initial backup failed: {$e->getMessage()}");
            Log::error('Initial backup failed', ['exception' => $e]);
            return self::FAILURE;
        }
    }

    private function processMailbox(string $mailbox, int $batchSize, bool $force): void
    {
        $files = $this->mailServerService->getMailFiles($mailbox);
        
        if (empty($files)) {
            $this->warn("No files found for mailbox: {$mailbox}");
            return;
        }

        $chunks = array_chunk($files, $batchSize);
        
        foreach ($chunks as $chunk) {
            ProcessInitialBackupJob::dispatch($mailbox, $chunk, $force);
        }

        $this->info("Queued " . count($chunks) . " batches for mailbox: {$mailbox}");
    }
}