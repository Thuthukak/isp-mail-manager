<?php

namespace App\Services;

use App\Models\MailBackup;
use App\Models\SyncConfiguration;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class MailBackupService
{
    private OneDriveService $oneDriveService;
    private MailServerService $mailServerService;

    public function __construct(OneDriveService $oneDriveService, MailServerService $mailServerService)
    {
        $this->oneDriveService = $oneDriveService;
        $this->mailServerService = $mailServerService;
    }

    /**
     * Perform initial backup of all mail files
     */
    public function performInitialBackup(array $mailboxes = []): array
    {
        Log::info("Starting initial backup process");
        
        $files = $this->mailServerService->scanMailDirectories($mailboxes);
        $results = ['success' => 0, 'failed' => 0, 'total' => count($files)];

        foreach ($files as $file) {
            if ($this->backupSingleFile($file)) {
                $results['success']++;
            } else {
                $results['failed']++;
            }
        }

        Log::info("Initial backup completed", $results);
        return $results;
    }

    /**
     * Sync new mail files since last sync
     */
    public function syncNewMails(): array
    {
        $lastSync = SyncConfiguration::getValue('last_sync_time');
        $since = $lastSync ? Carbon::parse($lastSync) : Carbon::now()->subHour();
        
        Log::info("Starting sync of new mails", ['since' => $since]);

        $newFiles = $this->mailServerService->getNewFiles($since);
        $results = ['success' => 0, 'failed' => 0, 'total' => count($newFiles)];

        foreach ($newFiles as $file) {
            if ($this->backupSingleFile($file)) {
                $results['success']++;
            } else {
                $results['failed']++;
            }
        }

        // Update last sync time
        SyncConfiguration::setValue('last_sync_time', Carbon::now());
        
        Log::info("New mail sync completed", $results);
        return $results;
    }

    /**
     * Backup a single file
     */
    public function backupSingleFile(array $fileInfo): bool
    {
        try {
            // Check if already backed up
            $existingBackup = MailBackup::where('mail_path', $fileInfo['full_path'])->first();
            
            if ($existingBackup && $existingBackup->status === 'completed') {
                return true; // Already backed up
            }

            // Create or update backup record
            $backup = MailBackup::updateOrCreate(
                ['mail_path' => $fileInfo['full_path']],
                [
                    'onedrive_path' => $this->generateOneDrivePath($fileInfo),
                    'status' => 'processing',
                    'size' => $fileInfo['size']
                ]
            );

            // Upload to OneDrive
            $uploadSuccess = $this->oneDriveService->uploadFile(
                $fileInfo['full_path'],
                $backup->onedrive_path
            );

            // Update backup status
            $backup->update([
                'status' => $uploadSuccess ? 'completed' : 'failed'
            ]);

            return $uploadSuccess;

        } catch (Exception $e) {
            Log::error("Failed to backup file", [
                'file' => $fileInfo['full_path'],
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Generate OneDrive path for mail file
     */
    private function generateOneDrivePath(array $fileInfo): string
    {
        $basePath = config('mail-backup.onedrive_base_path', 'mail-backups');
        $datePath = Carbon::now()->format('Y/m/d');
        
        return "{$basePath}/{$datePath}/{$fileInfo['mailbox']}/{$fileInfo['filename']}";
    }

    /**
     * Get backup statistics
     */
    public function getBackupStats(): array
    {
        return [
            'total_backups' => MailBackup::count(),
            'completed_backups' => MailBackup::where('status', 'completed')->count(),
            'failed_backups' => MailBackup::where('status', 'failed')->count(),
            'processing_backups' => MailBackup::where('status', 'processing')->count(),
            'total_size_mb' => round(MailBackup::where('status', 'completed')->sum('size') / (1024 * 1024), 2)
        ];
    }
}