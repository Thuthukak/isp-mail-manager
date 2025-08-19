<?php

namespace App\Jobs;

use App\Models\MailBackup;
use App\Models\MailRestoration;
use App\Models\SyncLog;
use App\Services\MailRestorationService;
use App\Services\OneDriveService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RestoreMailsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 3600; // 1 hour timeout
    public $tries = 3;
    public $backoff = [60, 300, 900];

    protected int $restorationId;
    protected array $mailPaths;
    protected string $targetPath;
    protected array $options;

    public function __construct(
        int $restorationId,
        array $mailPaths,
        string $targetPath,
        array $options = []
    ) {
        $this->restorationId = $restorationId;
        $this->mailPaths = $mailPaths;
        $this->targetPath = $targetPath;
        $this->options = $options;
        
        // Use high priority for restoration requests
        $this->onQueue('high');
    }

    public function handle(
        MailRestorationService $restorationService,
        OneDriveService $oneDriveService
    ): void {
        $restoration = MailRestoration::findOrFail($this->restorationId);
        
        $syncLog = SyncLog::create([
            'operation_type' => 'restore_mails',
            'status' => 'processing',
            'details' => json_encode([
                'restoration_id' => $this->restorationId,
                'mail_paths' => $this->mailPaths,
                'target_path' => $this->targetPath,
                'options' => $this->options
            ]),
            'started_at' => now(),
        ]);

        try {
            Log::info('Starting mail restoration process', [
                'restoration_id' => $this->restorationId,
                'files_count' => count($this->mailPaths),
                'target_path' => $this->targetPath,
                'job_id' => $this->job->getJobId()
            ]);

            $restoration->update(['status' => 'processing']);

            $totalFiles = count($this->mailPaths);
            $restoredFiles = 0;
            $failedFiles = 0;
            $skippedFiles = 0;
            $totalSize = 0;

            foreach ($this->mailPaths as $mailPath) {
                try {
                    // Find the backup record
                    $backup = MailBackup::where('mail_path', $mailPath)
                        ->whereIn('status', ['completed', 'purged'])
                        ->first();

                    if (!$backup) {
                        Log::warning('No backup found for restoration', [
                            'mail_path' => $mailPath
                        ]);
                        $failedFiles++;
                        continue;
                    }

                    // Check if target file already exists
                    $targetFilePath = $this->getTargetFilePath($mailPath);
                    
                    if (file_exists($targetFilePath) && !($this->options['overwrite'] ?? false)) {
                        Log::info('Skipping restoration - file already exists', [
                            'target_path' => $targetFilePath
                        ]);
                        $skippedFiles++;
                        continue;
                    }

                    // Download from OneDrive and restore
                    $result = $restorationService->restoreFile(
                        $backup->onedrive_path,
                        $targetFilePath,
                        $this->options
                    );

                    if ($result['success']) {
                        $restoredFiles++;
                        $totalSize += $result['size'] ?? 0;
                        
                        // Update backup status if it was purged
                        if ($backup->status === 'purged') {
                            $backup->update(['status' => 'completed']);
                        }

                        Log::debug('Successfully restored file', [
                            'mail_path' => $mailPath,
                            'target_path' => $targetFilePath,
                            'size_mb' => round(($result['size'] ?? 0) / 1024 / 1024, 2)
                        ]);
                    } else {
                        $failedFiles++;
                        Log::error('Failed to restore file', [
                            'mail_path' => $mailPath,
                            'onedrive_path' => $backup->onedrive_path,
                            'error' => $result['error']
                        ]);
                    }
                } catch (\Exception $e) {
                    $failedFiles++;
                    Log::error('Exception during file restoration', [
                        'mail_path' => $mailPath,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // Determine final status
            $finalStatus = 'completed';
            if ($failedFiles > 0) {
                $finalStatus = 'completed_with_errors';
            }
            if ($restoredFiles === 0 && $failedFiles > 0) {
                $finalStatus = 'failed';
            }

            // Update restoration record
            $restoration->update([
                'status' => $finalStatus,
                'completed_at' => now(),
            ]);

            // Update sync log
            $syncLog->update([
                'status' => $finalStatus,
                'details' => json_encode([
                    'restoration_id' => $this->restorationId,
                    'total_files' => $totalFiles,
                    'restored_files' => $restoredFiles,
                    'failed_files' => $failedFiles,
                    'skipped_files' => $skippedFiles,
                    'total_size_mb' => round($totalSize / 1024 / 1024, 2),
                    'target_path' => $this->targetPath
                ]),
                'completed_at' => now(),
            ]);

            Log::info('Mail restoration completed', [
                'restoration_id' => $this->restorationId,
                'total_files' => $totalFiles,
                'restored_files' => $restoredFiles,
                'failed_files' => $failedFiles,
                'skipped_files' => $skippedFiles,
                'total_size_mb' => round($totalSize / 1024 / 1024, 2)
            ]);

        } catch (\Exception $e) {
            Log::error('Restore mails job failed', [
                'restoration_id' => $this->restorationId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $restoration->update([
                'status' => 'failed',
                'completed_at' => now(),
            ]);

            $syncLog->update([
                'status' => 'failed',
                'details' => json_encode([
                    'error' => $e->getMessage(),
                    'restoration_id' => $this->restorationId
                ]),
                'completed_at' => now(),
            ]);

            throw $e;
        }
    }

    private function getTargetFilePath(string $originalPath): string
    {
        // Generate target file path based on original path and target directory
        $relativePath = str_replace($this->options['original_base_path'] ?? '', '', $originalPath);
        return rtrim($this->targetPath, '/') . '/' . ltrim($relativePath, '/');
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('RestoreMailsJob permanently failed', [
            'restoration_id' => $this->restorationId,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts()
        ]);

        // Update restoration record
        $restoration = MailRestoration::find($this->restorationId);
        $restoration?->update([
            'status' => 'failed',
            'completed_at' => now(),
        ]);

        // Update any existing sync log
        SyncLog::where('operation_type', 'restore_mails')
            ->where('status', 'processing')
            ->where('details->restoration_id', $this->restorationId)
            ->update([
                'status' => 'failed',
                'completed_at' => now(),
                'details' => json_encode([
                    'error' => 'Job permanently failed: ' . $exception->getMessage(),
                    'restoration_id' => $this->restorationId
                ])
            ]);
    }
}