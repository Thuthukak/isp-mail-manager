<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class MailManagementCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mail:manage
                            {operation : Operation to perform (all|backup|sync|purge|check|force-sync)}
                            {--mailbox= : Specific mailbox to process}
                            {--dry-run : Perform dry run for applicable operations}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Master command for mail management operations';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $operation = $this->argument('operation');
        $mailbox = $this->option('mailbox');
        $dryRun = $this->option('dry-run');

        $this->info("Starting mail management operation: {$operation}");

        switch ($operation) {
            case 'all':
                return $this->runAllOperations($mailbox, $dryRun);
            
            case 'backup':
                return $this->runInitialBackup($mailbox);
            
            case 'sync':
                return $this->runSyncNew($mailbox);
            
            case 'purge':
                return $this->runPurge($mailbox, $dryRun);
            
            case 'check':
                return $this->runSizeCheck($mailbox);
            
            case 'force-sync':
                return $this->runForceSync($mailbox);
            
            default:
                $this->error("Unknown operation: {$operation}");
                $this->info('Available operations: all, backup, sync, purge, check, force-sync');
                return self::FAILURE;
        }
    }

    private function runAllOperations(?string $mailbox, bool $dryRun): int
    {
        $this->info('Running complete mail management cycle...');
        
        $operations = [
            ['name' => 'Size Check', 'method' => 'runSizeCheck'],
            ['name' => 'Sync New Mails', 'method' => 'runSyncNew'],
            ['name' => 'Purge Old Mails', 'method' => 'runPurge']
        ];

        foreach ($operations as $operation) {
            $this->info("Running {$operation['name']}...");
            
            $result = $this->{$operation['method']}($mailbox, $dryRun ?? false);
            
            if ($result !== self::SUCCESS) {
                $this->error("{$operation['name']} failed!");
                return $result;
            }
            
            $this->info("{$operation['name']} completed successfully.");
            $this->newLine();
        }

        $this->info('All mail management operations completed successfully!');
        return self::SUCCESS;
    }

    private function runInitialBackup(?string $mailbox): int
    {
        $params = ['--batch-size' => '100'];
        if ($mailbox) {
            $params['--mailbox'] = $mailbox;
        }

        return Artisan::call('mail:initial-backup', $params);
    }

    private function runSyncNew(?string $mailbox): int
    {
        $params = ['--batch-size' => '50'];
        if ($mailbox) {
            $params['--mailbox'] = $mailbox;
        }

        return Artisan::call('mail:sync-new', $params);
    }

    private function runPurge(?string $mailbox, bool $dryRun = false): int
    {
        $params = ['--batch-size' => '100'];
        if ($mailbox) {
            $params['--mailbox'] = $mailbox;
        }
        if ($dryRun) {
            $params['--dry-run'] = true;
        }

        return Artisan::call('mail:purge-old', $params);
    }

    private function runSizeCheck(?string $mailbox): int
    {
        $params = ['--alert' => true, '--resolve-alerts' => true];
        if ($mailbox) {
            $params['--mailbox'] = $mailbox;
        }

        return Artisan::call('mail:check-sizes', $params);
    }

    private function runForceSync(?string $mailbox): int
    {
        $params = ['--batch-size' => '50', '--skip-confirmation' => true];
        if ($mailbox) {
            $params['--mailbox'] = $mailbox;
        }

        return Artisan::call('mail:force-sync', $params);
    }
}