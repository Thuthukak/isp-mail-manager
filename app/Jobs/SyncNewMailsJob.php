<?php

namespace App\Jobs;

use App\Models\MailBackup;
use App\Models\SyncLog;
use App\Services\MailBackupService;
use App\Services\MailServerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class SyncNewMailsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 1800; // 30 minutes timeout
    public $tries = 3;
    public $backoff = [30, 120, 300];

    protected array $mailPaths;
    protected ?Carbon $lastSyncTime;

    public function __construct(array $mailPaths = null, Carbon $lastSyncTime = null)
    {
        $this->mailPaths = $mailPaths ?? config('mail-backup.default_paths', []);
        $this->lastSyncTime = $lastSyncTime;
        
        // Use normal priority queue
        $this->onQueue('default');
    }

    public function handle(
        MailBackupService $backupService,
        MailServerService $mailServerService
    ): void {
        $syncLog = SyncLog::create([
            'operation_type' => 'sync_new_mails',
            'status' => 'processing',
            'details' => json_encode([
                'mail_paths' => $this->mailPaths,
                'last_sync_time' => $this->lastSyncTime?->toISOString()
            ]),
            'started_at' => now(),
        ]);

        try {
            Log::info('Starting new mail sync process', [
                'paths_count' => count($this->mailPaths),
                'last_sync' => $this->lastSyncTime?->toISOString(),
                'job_id' => $this->job->getJobId()
            ]);

            $totalNewFiles = 0;
            $totalProcessed = 0;
            $totalFailed = 0;

            foreach ($this->mailPaths as $mailPath) {
                try {
                    // Get new/modified files since last sync
                    $newFiles = $mailServerService->getNewFiles(
                        $mailPath,
                        $this->lastSyncTime
                    );

                    if (empty($newFiles)) {
                        Log::debug('No new files found in path', ['path' => $mailPath]);
                        continue;
                    }

                    $totalNewFiles += count($newFiles);
                    Log::info('Found new mail files', [
                        'path' => $mailPath,
                        'count' => count($newFiles)
                    ]);

                    foreach ($newFiles as $file) {
                        try {
                            // Check if file is already backed up
                            $existingBackup = MailBackup::where('mail_path', $file['path'])
                                ->where('status', 'completed')
                                ->first();

                            if ($existingBackup) {
                                // Check if file was modified
                                if ($file['modified_time'] <= $existingBackup->updated_at) {
                                    continue; // Skip unchanged file
                                }
                            }

                            $result = $backupService->backupSingleFile($file);
                            
                            if ($result['success']) {
                                $totalProcessed++;
                                
                                // Update or create backup record
                                MailBackup::updateOrCreate(
                                    ['mail_path' => $file['path']],
                                    [
                                        'onedrive_path' => $result['onedrive_path'],
                                        'status' => 'completed',
                                        'size' => $file['size'],
                                    ]
                                );
                            } else {
                                $totalFailed++;
                                Log::error('Failed to sync new mail file', [
                                    'file' => $file['path'],
                                    'error' => $result['error']
                                ]);
                            }
                        } catch (\Exception $e) {
                            $totalFailed++;
                            Log::error('Exception during new mail sync', [
                                'file' => $file['path'],
                                'error' => $e->getMessage()
                            ]);
                        }
                    }
                } catch (\Exception $e) {
                    Log::error('Failed to process mail path during sync', [
                        'path' => $mailPath,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // Update sync log with results
            $syncLog->update([
                'status' => $totalFailed === 0 ? 'completed' : 'completed_with_errors',
                'details' => json_encode([
                    'mail_paths' => $this->mailPaths,
                    'total_new_files' => $totalNewFiles,
                    'processed_files' => $totalProcessed,
                    'failed_files' => $totalFailed,
                    'last_sync_time' => $this->lastSyncTime?->toISOString()
                ]),
                'completed_at' => now(),
            ]);

            // Update last sync time in configuration
            if ($totalProcessed > 0 || $totalNewFiles === 0) {
                config(['mail-backup.last_sync_time' => now()]);
                // You might want to persist this to database via SyncConfiguration model
            }

            Log::info('New mail sync completed', [
                'total_new_files' => $totalNewFiles,
                'processed_files' => $totalProcessed,
                'failed_files' => $totalFailed
            ]);

        } catch (\Exception $e) {
            Log::error('Sync new mails job failed', [
                'paths' => $this->mailPaths,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $syncLog->update([
                'status' => 'failed',
                'details' => json_encode([
                    'error' => $e->getMessage(),
                    'mail_paths' => $this->mailPaths
                ]),
                'completed_at' => now(),
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SyncNewMailsJob permanently failed', [
            'mail_paths' => $this->mailPaths,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts()
        ]);

        // Update any existing sync log
        SyncLog::where('operation_type', 'sync_new_mails')
            ->where('status', 'processing')
            ->latest()
            ->first()
            ?->update([
                'status' => 'failed',
                'completed_at' => now(),
                'details' => json_encode([
                    'error' => 'Job permanently failed: ' . $exception->getMessage(),
                    'mail_paths' => $this->mailPaths
                ])
            ]);
    }
}