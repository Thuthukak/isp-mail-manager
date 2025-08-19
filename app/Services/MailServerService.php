<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class MailServerService
{
    private string $mailRootPath;

    public function __construct()
    {
        $this->mailRootPath = config('mail-backup.mail_server_path', '/var/mail');
    }

    /**
     * Scan mail directories for files
     */
    public function scanMailDirectories(array $mailboxes = []): array
    {
        $files = [];
        
        if (empty($mailboxes)) {
            $mailboxes = $this->getAllMailboxes();
        }

        foreach ($mailboxes as $mailbox) {
            $mailboxPath = $this->mailRootPath . '/' . $mailbox;
            
            if (!File::isDirectory($mailboxPath)) {
                Log::warning("Mailbox directory not found", ['path' => $mailboxPath]);
                continue;
            }

            $mailboxFiles = $this->scanDirectory($mailboxPath, $mailbox);
            $files = array_merge($files, $mailboxFiles);
        }

        return $files;
    }

    /**
     * Get all available mailboxes
     */
    public function getAllMailboxes(): array
    {
        try {
            $directories = File::directories($this->mailRootPath);
            return array_map('basename', $directories);
        } catch (Exception $e) {
            Log::error("Failed to get mailboxes", ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Scan individual directory
     */
    private function scanDirectory(string $path, string $mailbox): array
    {
        $files = [];
        
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $files[] = [
                        'mailbox' => $mailbox,
                        'full_path' => $file->getPathname(),
                        'relative_path' => str_replace($this->mailRootPath . '/', '', $file->getPathname()),
                        'filename' => $file->getFilename(),
                        'size' => $file->getSize(),
                        'modified_at' => Carbon::createFromTimestamp($file->getMTime()),
                        'created_at' => Carbon::createFromTimestamp($file->getCTime()),
                    ];
                }
            }

        } catch (Exception $e) {
            Log::error("Failed to scan directory", [
                'path' => $path,
                'error' => $e->getMessage()
            ]);
        }

        return $files;
    }

    /**
     * Get mailbox size in MB
     */
    public function getMailboxSize(string $mailbox): float
    {
        $path = $this->mailRootPath . '/' . $mailbox;
        
        if (!File::isDirectory($path)) {
            return 0;
        }

        try {
            $size = 0;
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $size += $file->getSize();
                }
            }

            return round($size / (1024 * 1024), 2); // Convert to MB

        } catch (Exception $e) {
            Log::error("Failed to calculate mailbox size", [
                'mailbox' => $mailbox,
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    /**
     * Get new files since last sync
     */
    public function getNewFiles(Carbon $since): array
    {
        $allFiles = $this->scanMailDirectories();
        
        return array_filter($allFiles, function ($file) use ($since) {
            return $file['modified_at']->gt($since);
        });
    }

    /**
     * Get old files for purging
     */
    public function getOldFiles(int $daysOld): array
    {
        $cutoffDate = Carbon::now()->subDays($daysOld);
        $allFiles = $this->scanMailDirectories();
        
        return array_filter($allFiles, function ($file) use ($cutoffDate) {
            return $file['modified_at']->lt($cutoffDate);
        });
    }

    /**
     * Delete mail file
     */
    public function deleteFile(string $filePath): bool
    {
        try {
            if (File::exists($filePath)) {
                File::delete($filePath);
                Log::info("Mail file deleted", ['path' => $filePath]);
                return true;
            }
            return false;
        } catch (Exception $e) {
            Log::error("Failed to delete mail file", [
                'path' => $filePath,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Copy file to restoration location
     */
    public function restoreFile(string $sourcePath, string $destinationPath): bool
    {
        try {
            // Ensure destination directory exists
            $destinationDir = dirname($destinationPath);
            if (!File::isDirectory($destinationDir)) {
                File::makeDirectory($destinationDir, 0755, true);
            }

            File::copy($sourcePath, $destinationPath);
            Log::info("Mail file restored", [
                'source' => $sourcePath,
                'destination' => $destinationPath
            ]);
            return true;

        } catch (Exception $e) {
            Log::error("Failed to restore mail file", [
                'source' => $sourcePath,
                'destination' => $destinationPath,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
}