<?php

namespace App\Jobs;

use App\Models\EmailAccount;
use App\Models\BackupJob;
use App\Services\MailBackupService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class BackupEmailAccountJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 3600; // 1 hour timeout
    public $tries = 3; // Retry 3 times on failure

    /**
     * Create a new job instance.
     */
    public function __construct(
        public EmailAccount $emailAccount
    ) {
        // Set queue name if needed
        $this->onQueue('email-backup');
    }

    /**
     * Execute the job.
     */
    public function handle(MailBackupService $mailBackupService): void
    {
        try {
            Log::info("Starting backup for email account: {$this->emailAccount->email}");
            
            // Let MailBackupService handle the BackupJob creation and management
            $result = $mailBackupService->backupSingleAccount($this->emailAccount);
            
            // Check if backup was actually successful
            if (!$result['success']) {
                throw new \Exception("Backup failed: " . ($result['error'] ?? 'Unknown error'));
            }
            
            Log::info("Backup completed successfully for: {$this->emailAccount->email}", [
                'emails_backed_up' => $result['emails_backed_up'] ?? 0,
                'mailboxes_count' => $result['mailboxes_count'] ?? 0
            ]);
            
        } catch (\Exception $e) {
            Log::error("Backup failed for {$this->emailAccount->email}: " . $e->getMessage());
            
            // Re-throw the exception to mark the job as failed
            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("Backup job failed permanently for email account ID {$this->emailAccount->id}: " . $exception->getMessage());
        
        // Update the backup job status if it exists and is still running
        $backupJob = BackupJob::where('email_account_id', $this->emailAccount->id)
            ->where('status', 'running')
            ->latest()
            ->first();
            
        if ($backupJob) {
            $backupJob->update([
                'status' => 'failed',
                'completed_at' => now(),
                'error_message' => $exception->getMessage(),
            ]);
        }
    }
}