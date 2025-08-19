<?php

namespace App\Services;

use App\Models\MailBackup;
use App\Models\MailRestoration;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File;

class MailRestorationService
{
    private OneDriveService $oneDriveService;
    private MailServerService $mailServerService;

    public function __construct(OneDriveService $oneDriveService, MailServerService $mailServerService)
    {
        $this->oneDriveService = $oneDriveService;
        $this->mailServerService = $mailServerService;
    }

    /**
     * Restore mail from backup
     */
    public function restoreMail(string $mailPath, string $restorePath = null): bool
    {
        try {
            // Find backup record
            $backup = MailBackup::where('mail_path', $mailPath)
                ->where('status', 'completed')
                ->first();

            if (!$backup) {
                Log::error("No backup found for mail file", ['path' => $mailPath]);
                return false;
            }

            // Create restoration record
            $restoration = MailRestoration::create([
                'mail_path' => $mailPath,
                'restored_from' => $backup->onedrive_path,
                'status' => 'processing',
                'requested_at' => now()
            ]);

            // Determine restoration path
            $targetPath = $restorePath ?? $mailPath;
            $tempPath = storage_path('app/temp/restore_' . basename($targetPath));

            // Download from OneDrive
            $downloadSuccess = $this->oneDriveService->downloadFile(
                $backup->onedrive_path,
                $tempPath
            );

            if (!$downloadSuccess) {
                $restoration->update(['status' => 'failed']);
                return false;
            }

            // Restore to mail server
            $restoreSuccess = $this->mailServerService->restoreFile($tempPath, $targetPath);

            // Clean up temp file
            if (File::exists($tempPath)) {
                File::delete($tempPath);
            }

            // Update restoration status
            $restoration->update([
                'status' => $restoreSuccess ? 'completed' : 'failed',
                'completed_at' => $restoreSuccess ? now() : null
            ]);

            Log::info("Mail restoration completed", [
                'mail_path' => $mailPath,
                'success' => $restoreSuccess
            ]);

            return $restoreSuccess;

        } catch (Exception $e) {
            Log::error("Mail restoration failed", [
                'mail_path' => $mailPath,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Restore multiple files
     */
    public function restoreMultipleFiles(array $mailPaths): array
    {
        $results = ['success' => 0, 'failed' => 0, 'total' => count($mailPaths)];

        foreach ($mailPaths as $mailPath) {
            if ($this->restoreMail($mailPath)) {
                $results['success']++;
            } else {
                $results['failed']++;
            }
        }

        return $results;
    }

    /**
     * Get restoration history
     */
    public function getRestorationHistory(int $limit = 50): array
    {
        return MailRestoration::orderBy('requested_at', 'desc')
            ->limit($limit)
            ->get()
            ->toArray();
    }
}