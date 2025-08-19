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

class ProcessInitialBackupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 3600; // 1 hour timeout
    public $tries = 3;
    public $backoff = [60, 300, 900]; // Backoff in seconds

    protected string $mailPath;
    protected array $options;

    public function __construct(string $mailPath, array $options = [])
    {
        $this->mailPath = $mailPath;
        $this->options = $options;
        
        // Set queue priority
        $this->onQueue('high');
    }

    public function handle(
        MailBackupService $backupService,
        MailServerService $mailServerService
    ): void {
        $syncLog = SyncLog::create([
            'operation_type' => 'initial_backup',
            'status' => 'processing',
            'details' => json_encode([
                'mail_path' => $this->mailPath,
                'options' => $this->options
            ]),
            'started_at' => now(),
        ]);

        try {
            Log::info('Starting initial backup process', [
                'mail_path' => $this->mailPath,
                'job_id' => $this->job->getJobId()
            ]);

            // Scan the mail directory
            $mailFiles = $mailServerService->scanDirectory($this->mailPath);
            
            if (empty($mailFiles)) {
                Log::warning('No mail files found in directory', [
                    'mail_path' => $this->mailPath
                ]);
                
                $syncLog->update([
                    'status' => 'completed',
                    'details' => json_encode([
                        'message' => 'No mail files found',
                        'files_processed' => 0
                    ]),
                    'completed_at' => now(),
                ]);
                
                return;
            }

            $totalFiles = count($mailFiles);
            $processedFiles = 0;
            $failedFiles = 0;

            // Process files in chunks to avoid memory issues
            $chunks = array_chunk($mailFiles, $this->options['chunk_size'] ?? 100);
            
            foreach ($chunks as $chunk) {
                foreach ($chunk as $file) {
                    try {
                        $result = $backupService->backupSingleFile($file);
                        
                        if ($result['success']) {
                            $processedFiles++;
                            
                            // Create backup record
                            MailBackup::create([
                                'mail_path' => $file['path'],
                                'onedrive_path' => $result['onedrive_path'],
                                'status' => 'completed',
                                'size' => $file['size'],
                            ]);
                        } else {
                            $failedFiles++;
                            Log::error('Failed to backup file during initial backup', [
                                'file' => $file['path'],
                                'error' => $result['error']
                            ]);
                        }
                    } catch (\Exception $e) {
                        $failedFiles++;
                        Log::error('Exception during file backup', [
                            'file' => $file['path'],
                            'error' => $e->getMessage()
                        ]);
                    }
                }
                
                // Update progress
                Log::info('Initial backup progress', [
                    'processed' => $processedFiles,
                    'failed' => $failedFiles,
                    'total' => $totalFiles
                ]);
            }

            // Update sync log with results
            $syncLog->update([
                'status' => $failedFiles === 0 ? 'completed' : 'completed_with_errors',
                'details' => json_encode([
                    'total_files' => $totalFiles,
                    'processed_files' => $processedFiles,
                    'failed_files' => $failedFiles,
                    'mail_path' => $this->mailPath
                ]),
                'completed_at' => now(),
            ]);

            Log::info('Initial backup completed', [
                'total_files' => $totalFiles,
                'processed_files' => $processedFiles,
                'failed_files' => $failedFiles
            ]);

        } catch (\Exception $e) {
            Log::error('Initial backup job failed', [
                'mail_path' => $this->mailPath,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $syncLog->update([
                'status' => 'failed',
                'details' => json_encode([
                    'error' => $e->getMessage(),
                    'mail_path' => $this->mailPath
                ]),
                'completed_at' => now(),
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessInitialBackupJob permanently failed', [
            'mail_path' => $this->mailPath,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts()
        ]);

        // Update any existing sync log
        SyncLog::where('operation_type', 'initial_backup')
            ->where('status', 'processing')
            ->where('details->mail_path', $this->mailPath)
            ->update([
                'status' => 'failed',
                'completed_at' => now(),
                'details' => json_encode([
                    'error' => 'Job permanently failed: ' . $exception->getMessage(),
                    'mail_path' => $this->mailPath
                ])
            ]);
    }
}