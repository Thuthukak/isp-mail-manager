<?php

namespace App\Jobs;

use App\Models\MailBackup;
use App\Models\SyncLog;
use App\Services\MailBackupService;
use App\Services\MailServerService;
use App\Services\OneDriveService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ForceSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 7200; // 2 hours timeout
    public $tries = 2;
    public $backoff = [300, 900];

    protected array $mailPaths;
    protected array $options;

    public function __construct(array $mailPaths, array $options = [])
    {
        $this->mailPaths = $mailPaths;
        $this->options = array_merge([
            'verify_checksums' => true,
            'repair_missing' => true,
            'update_modified' => true,
            'chunk_size' => 50
        ], $options);
        
        // Use high priority for force sync operations
        $this->onQueue('high');
    }

    public function handle(
        MailBackupService $backupService,
        MailServerService $mailServerService,
        OneDriveService $oneDriveService
    ): void {
        $syncLog = SyncLog::create([
            'operation_type' => 'force_sync',
            'status' => 'processing',
            'details' => json_encode([
                'mail_paths' => $this->mailPaths,
                'options' => $this->options
            ]),
            'started_at' => now(),
        ]);

        try {
            Log::info('Starting force sync process', [
                'paths_count' => count($this->mailPaths),
                'options' => $this->options,
                'job_id' => $this->job->getJobId()
            ]);

            $totalFiles = 0;
            $processedFiles = 0;
            $repairedFiles = 0;
            $updatedFiles = 0;
            $failedFiles = 0;
            $verifiedFiles = 0;

            foreach ($this->mailPaths as $mailPath) {
                try {
                    Log::info('Processing mail path for force sync', ['path' => $mailPath]);
                    
                    // Get all files in the directory
                    $allFiles = $mailServerService->scanDirectory($mailPath, true);
                    $totalFiles += count($allFiles);

                    // Process files in chunks
                    $chunks = array_chunk($allFiles, $this->options['chunk_size']);
                    
                    foreach ($chunks as $chunkIndex => $chunk) {
                        Log::debug('Processing chunk for force sync', [
                            'path' => $mailPath,
                            'chunk' => $chunkIndex + 1,
                            'chunk_size' => count($chunk)
                        ]);

                        foreach ($chunk as $file) {
                            try {
                                $result = $this->processSingleFile(
                                    $file,
                                    $backupService,
                                    $oneDriveService
                                );

                                $processedFiles++;
                                
                                switch ($result['action']) {
                                    case 'verified':
                                        $verifiedFiles++;
                                        break;
                                    case 'repaired':
                                        $repairedFiles++;
                                        break;
                                    case 'updated':
                                        $updatedFiles++;
                                        break;
                                    case 'failed':
                                        $failedFiles++;
                                        break;
                                }

                                // Log progress every 100 files
                                if ($processedFiles % 100 === 0) {
                                    Log::info('Force sync progress', [
                                        'processed' => $processedFiles,
                                        'total' => $totalFiles,
                                        'verified' => $verifiedFiles,
                                        'repaired' => $repairedFiles,
                                        'updated' => $updatedFiles,
                                        'failed' => $failedFiles
                                    ]);
                                }

                            } catch (\Exception $e) {
                                $failedFiles++;
                                Log::error('Exception during force sync file processing', [
                                    'file' => $file['path'],
                                    'error' => $e->getMessage()
                                ]);
                            }
                        }
                    }

                } catch (\Exception $e) {
                    Log::error('Failed to process mail path during force sync', [
                        'path' => $mailPath,
                        'error' => $e->getMessage()
                    ]);
                }
            }

            // Update sync log with results
            $status = $failedFiles === 0 ? 'completed' : 'completed_with_errors';
            
            $syncLog->update([
                'status' => $status,
                'details' => json_encode([
                    'mail_paths' => $this->mailPaths,
                    'options' => $this->options,
                    'total_files' => $totalFiles,
                    'processed_files' => $processedFiles,
                    'verified_files' => $verifiedFiles,
                    'repaired_files' => $repairedFiles,
                    'updated_files' => $updatedFiles,
                    'failed_files' => $failedFiles
                ]),
                'completed_at' => now(),
            ]);

            Log::info('Force sync completed', [
                'total_files' => $totalFiles,
                'processed_files' => $processedFiles,
                'verified_files' => $verifiedFiles,
                'repaired_files' => $repairedFiles,
                'updated_files' => $updatedFiles,
                'failed_files' => $failedFiles
            ]);

        } catch (\Exception $e) {
            Log::error('Force sync job failed', [
                'mail_paths' => $this->mailPaths,
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

    private function processSingleFile(
        array $file,
        MailBackupService $backupService,
        OneDriveService $oneDriveService
    ): array {
        // Find existing backup record
        $backup = MailBackup::where('mail_path', $file['path'])->first();
        
        if (!$backup) {
            // File not backed up - create backup
            $result = $backupService->backupSingleFile($file);
            
            if ($result['success']) {
                MailBackup::create([
                    'mail_path' => $file['path'],
                    'onedrive_path' => $result['onedrive_path'],
                    'status' => 'completed',
                    'size' => $file['size'],
                ]);
                
                return ['action' => 'repaired', 'message' => 'Missing backup created'];
            } else {
                return ['action' => 'failed', 'message' => $result['error']];
            }
        }

        // Check if OneDrive file exists
        if (!$oneDriveService->fileExists($backup->onedrive_path)) {
            if ($this->options['repair_missing']) {
                // Re-upload missing file
                $result = $backupService->backupSingleFile($file);
                
                if ($result['success']) {
                    $backup->update([
                        'onedrive_path' => $result['onedrive_path'],
                        'status' => 'completed',
                        'size' => $file['size'],
                    ]);
                    
                    return ['action' => 'repaired', 'message' => 'Missing OneDrive file restored'];
                } else {
                    return ['action' => 'failed', 'message' => $result['error']];
                }
            } else {
                return ['action' => 'failed', 'message' => 'OneDrive file missing, repair disabled'];
            }
        }

        // Check if local file was modified
        if ($this->options['update_modified']) {
            $localModifiedTime = filemtime($file['path']);
            $backupModifiedTime = $backup->updated_at->timestamp;
            
            if ($localModifiedTime > $backupModifiedTime) {
                // File was modified - update backup
                $result = $backupService->backupSingleFile($file);
                
                if ($result['success']) {
                    $backup->update([
                        'size' => $file['size'],
                    ]);
                    
                    return ['action' => 'updated', 'message' => 'Modified file updated'];
                } else {
                    return ['action' => 'failed', 'message' => $result['error']];
                }
            }
        }

        // Verify checksums if enabled
        if ($this->options['verify_checksums']) {
            try {
                $localChecksum = $backupService->calculateFileChecksum($file['path']);
                $remoteChecksum = $oneDriveService->getFileChecksum($backup->onedrive_path);
                
                if ($localChecksum !== $remoteChecksum) {
                    // Checksums don't match - re-upload
                    $result = $backupService->backupSingleFile($file);
                    
                    if ($result['success']) {
                        return ['action' => 'repaired', 'message' => 'Checksum mismatch repaired'];
                    } else {
                        return ['action' => 'failed', 'message' => 'Checksum repair failed: ' . $result['error']];
                    }
                }
            } catch (\Exception $e) {
                Log::warning('Checksum verification failed', [
                    'file' => $file['path'],
                    'error' => $e->getMessage()
                ]);
                // Continue without failing the entire operation
            }
        }

        return ['action' => 'verified', 'message' => 'File verified successfully'];
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ForceSyncJob permanently failed', [
            'mail_paths' => $this->mailPaths,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts()
        ]);

        // Update any existing sync log
        SyncLog::where('operation_type', 'force_sync')
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