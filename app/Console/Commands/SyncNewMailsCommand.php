<?php

namespace App\Console\Commands;

use App\Jobs\SyncNewMailsJob;
use App\Models\SyncConfiguration;
use App\Models\SyncLog;
use App\Services\MailServerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncNewMailsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mail:sync-new
                            {--mailbox= : Specific mailbox to sync}
                            {--since= : Sync files modified since this date (Y-m-d H:i:s)}
                            {--batch-size=50 : Number of files to process per batch}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync new and modified mail files to OneDrive';

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
        $this->info('Starting New Mail Sync...');
        
        $syncLog = SyncLog::create([
            'operation_type' => 'sync_new',
            'status' => 'running',
            'details' => json_encode([
                'mailbox' => $this->option('mailbox'),
                'since' => $this->option('since'),
                'batch_size' => $this->option('batch-size')
            ]),
            'started_at' => now(),
        ]);

        try {
            $mailbox = $this->option('mailbox');
            $batchSize = (int) $this->option('batch-size');
            $since = $this->getSinceDate();

            $this->info("Syncing files modified since: {$since->format('Y-m-d H:i:s')}");

            if ($mailbox) {
                $this->info("Processing mailbox: {$mailbox}");
                $this->processMailbox($mailbox, $since, $batchSize);
            } else {
                $this->info('Processing all mailboxes...');
                $mailboxes = $this->mailServerService->getAllMailboxes();
                
                $this->withProgressBar($mailboxes, function ($mailbox) use ($since, $batchSize) {
                    $this->processMailbox($mailbox, $since, $batchSize);
                });
            }

            // Update last sync time
            SyncConfiguration::updateOrCreate(
                ['key' => 'last_sync_time'],
                ['value' => now()->toISOString()]
            );

            $syncLog->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);

            $this->newLine();
            $this->info('New mail sync completed successfully!');
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

            $this->error("New mail sync failed: {$e->getMessage()}");
            Log::error('New mail sync failed', ['exception' => $e]);
            return self::FAILURE;
        }
    }

    private function getSinceDate(): \Carbon\Carbon
    {
        if ($since = $this->option('since')) {
            return \Carbon\Carbon::parse($since);
        }

        // Get last sync time from configuration
        $lastSync = SyncConfiguration::where('key', 'last_sync_time')->first();
        
        if ($lastSync && $lastSync->value) {
            return \Carbon\Carbon::parse($lastSync->value);
        }

        // Default to 24 hours ago if no previous sync
        return now()->subDay();
    }

    private function processMailbox(string $mailbox, \Carbon\Carbon $since, int $batchSize): void
    {
        $files = $this->mailServerService->getModifiedMailFiles($mailbox, $since);
        
        if (empty($files)) {
            $this->warn("No new/modified files found for mailbox: {$mailbox}");
            return;
        }

        $chunks = array_chunk($files, $batchSize);
        
        foreach ($chunks as $chunk) {
            SyncNewMailsJob::dispatch($mailbox, $chunk);
        }

        $this->info("Queued " . count($chunks) . " batches for mailbox: {$mailbox}");
    }
}