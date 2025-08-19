<?php

namespace App\Jobs;

use App\Models\MailBackup;
use App\Models\PurgeHistory;
use App\Models\SyncLog;
use App\Services\MailServerService;
use App\Services\OneDriveService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class PurgeOldMailsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 7200; // 2 hours timeout
    public $tries = 2;
    public $backoff = [300, 900];

    protected string $mailboxPath;
    protected int $retentionDays;
    protected bool $dryRun;

    public function __construct(string $mailboxPath, int $retentionDays, bool $dryRun = false)
    {
        $this->mailboxPath = $mailboxPath;
        $this->retentionDays = $retentionDays;
        $this->dryRun = $dryRun;
        
        // Use low priority queue for purge operations
        $this->onQueue('low');
    }

    public function handle(
        MailServerService $mailServerService,
        OneDriveService $oneDriveService
    ): void {
        $syncLog = SyncLog::create([
            'operation_type' => 'purge_old_mails',
            'status' => 'processing',
            'details' => json_encode([
                'mailbox_path' => $this->mailboxPath,
                'retention_days' => $this->retentionDays,
                'dry_run' => $this->dryRun
            ]),
            'started_at' => now(),
        ]);

        try {
            Log::info('Starting mail purge process', [
                'mailbox_path' => $this->mailboxPath,
                'retention_days' => $this->retentionDays,
                'dry_run' => $this->dryRun,
                'job_id' => $this->job->getJobId()
            ]);

            $cutoffDate = Carbon::now()->subDays($this->retentionDays);
            
            // Find files older than retention period
            $oldFiles = $mailServerService->getFilesOlderThan(
                $this->mailboxPath,
                $cutoffDate
            );

            if (empty($oldFiles)) {
                Log::info('No files found for purging', [
                    'mailbox_path' => $this->mailboxPath,
                    'cutoff_date' => $cutoffDate->toISOString()
                ]);

                $syncLog->update([
                    'status' => 'completed',
                    'details' => json_encode([
                        'message' => 'No files found for purging',
                        'cutoff_date' => $cutoffDate->toISOString(),
                        'files_purged' => 0
                    ]),
                    'completed_at' => now(),
                ]);

                return;
            }

            $totalFiles = count($oldFiles);
            $totalSize = array_sum(array_column($oldFiles, 'size'));
            $purgedFiles = 0;
            $purgedSize = 0;
            $failedFiles = 0;

            Log::info('Files eligible for purging', [
                'total_files' => $totalFiles,
                'total_size_mb' => round($totalSize / 1024 / 1024, 2),
                'cutoff_date' => $cutoffDate->toISOString()
            ]);

            if ($this->dryRun) {
                Log::info('DRY RUN: Would purge files', [
                    'files_count' => $totalFiles,
                    'total_size_mb' => round($totalSize / 1024 / 1024, 2)
                ]);

                $syncLog->update([
                    'status' => 'completed',
                    'details' => json_encode([
                        'dry_run' => true,
                        'files_that_would_be_purged' => $totalFiles,
                        'size_that_would_be_purged_mb' => round($totalSize / 1024 / 1024, 2),
                        'cutoff_date' => $cutoffDate->toISOString()
                    ]),
                    'completed_at' => now(),
                ]);

                return;
            }

            foreach ($oldFiles as $file) {
                try {
                    // Verify file is backed up before purging
                    $backup = MailBackup::where('mail_path', $file['path'])
                        ->where('status', 'completed')
                        ->first();

                    if (!$backup) {
                        Log::warning('Skipping purge - file not backed up', [
                            'file' => $file['path']
                        ]);
                        $failedFiles++;
                        continue;
                    }

                    // Verify backup exists on OneDrive
                    if (!$oneDriveService->fileExists($backup->onedrive_path)) {
                        Log::warning('Skipping purge - backup not found on OneDrive', [
                            'file' => $file['path'],
                            'onedrive_path' => $backup->onedrive_path
                        ]);
                        $failedFiles++;
                        continue;
                    }

                    // Delete the local file
                    if ($mailServerService->deleteFile($file['path'])) {
                        $purgedFiles++;
                        $purgedSize += $file['size'];
                        
                        // Update backup record
                        $backup->update([
                            'status' => 'purged',
                        ]);

                        Log::debug('Successfully purged file', [
                            'file' => $file['path'],
                            'size_mb' => round($file['size'] / 1024 / 1024, 2)
                        ]);
                    } else {
                        $failedFiles++;
                        Log::error('Failed to delete file', [
                            'file' => $file['path']
                        ]);
                    }
                } catch (\Exception $e) {
                    $failedFiles++;
                    Log::error('Exception during file purge', [
                        'file' => $file['path'],
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // Create purge history record
            PurgeHistory::create([
                'mailbox' => $this->mailboxPath,
                'purged_files_count' => $purgedFiles,
                'purged_size_mb' => round($purgedSize / 1024 / 1024, 2),
                'purged_at' => now(),
            ]);

            // Update sync log with results
            $syncLog->update([
                'status' => $failedFiles === 0 ? 'completed' : 'completed_with_errors',
                'details' => json_encode([
                    'mailbox_path' => $this->mailboxPath,
                    'retention_days' => $this->retentionDays,
                    'total_eligible_files' => $totalFiles,
                    'purged_files' => $purgedFiles,
                    'failed_files' => $failedFiles,
                    'purged_size_mb' => round($purgedSize / 1024 / 1024, 2),
                    'cutoff_date' => $cutoffDate->toISOString()
                ]),
                'completed_at' => now(),
            ]);

            Log::info('Mail purge completed', [
                'mailbox_path' => $this->mailboxPath,
                'purged_files' => $purgedFiles,
                'failed_files' => $failedFiles,
                'purged_size_mb' => round($purgedSize / 1024 / 1024, 2)
            ]);

        } catch (\Exception $e) {
            Log::error('Purge old mails job failed', [
                'mailbox_path' => $this->mailboxPath,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $syncLog->update([
                'status' => 'failed',
                'details' => json_encode([
                    'error' => $e->getMessage(),
                    'mailbox_path' => $this->mailboxPath
                ]),
                'completed_at' => now(),
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('PurgeOldMailsJob permanently failed', [
            'mailbox_path' => $this->mailboxPath,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts()
        ]);

        // Update any existing sync log
        SyncLog::where('operation_type', 'purge_old_mails')
            ->where('status', 'processing')
            ->where('details->mailbox_path', $this->mailboxPath)
            ->update([
                'status' => 'failed',
                'completed_at' => now(),
                'details' => json_encode([
                    'error' => 'Job permanently failed: ' . $exception->getMessage(),
                    'mailbox_path' => $this->mailboxPath
                ])
            ]);
    }
}