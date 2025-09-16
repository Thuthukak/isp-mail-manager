<?php

namespace App\Services;

use App\Models\EmailAccount;
use App\Models\MailBackup;
use App\Models\BackupJob;
use App\Models\SyncConfiguration;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;
use Exception;
use Webklex\PHPIMAP\ClientManager;

class MailBackupService
{
    private OneDrivePersonalService $oneDriveService;
    private MaildirService $maildirService;

    public function __construct(OneDrivePersonalService $oneDriveService, MaildirService $maildirService)
    {
        $this->oneDriveService = $oneDriveService;
        $this->maildirService = $maildirService;
    }

    /**
     * Get the user to use for OneDrive operations
     * Priority: 1) Current auth user (if admin/super_admin), 2) Any admin user, 3) First user
     */
    private function getOneDriveUser(): ?User
    {
        // First, try to use the currently authenticated user if they have the right role
        $currentUser = Auth::user();
        if ($currentUser && $this->userCanPerformBackups($currentUser)) {
            Log::info("Using authenticated user for OneDrive operations", ['user_id' => $currentUser->id, 'email' => $currentUser->email]);
            return $currentUser;
        }

        // Fallback to finding any admin user
        $adminUser = User::whereHas('roles', function($q) {
            $q->where('name', 'super_admin')->orWhere('name', 'admin');
        })->first();

        if ($adminUser) {
            Log::info("Using fallback admin user for OneDrive operations", ['user_id' => $adminUser->id, 'email' => $adminUser->email]);
            return $adminUser;
        }

        // Last resort - any user
        $anyUser = User::first();
        if ($anyUser) {
            Log::warning("Using fallback user for OneDrive operations - no admin found", ['user_id' => $anyUser->id, 'email' => $anyUser->email]);
            return $anyUser;
        }

        Log::error('No user found for OneDrive operations');
        return null;
    }

    /**
     * Check if user can perform backup operations
     */
    private function userCanPerformBackups(User $user): bool
    {
        return $user->hasRole(['super_admin', 'admin']);
    }

    /**
     * Backup all active email accounts in the organization
     */
    public function backupAllAccounts(array $accountIds = []): array
    {
        Log::info("Starting organization-wide backup process");
        
        $oneDriveUser = $this->getOneDriveUser();
        if (!$oneDriveUser) {
            return [
                'error' => 'No valid user found for OneDrive operations',
                'total_accounts' => 0,
                'successful_accounts' => 0,
                'failed_accounts' => 0,
                'total_emails' => 0,
                'account_results' => []
            ];
        }
        
        $query = EmailAccount::where('active', true);
        if (!empty($accountIds)) {
            $query->whereIn('id', $accountIds);
        }
        
        $accounts = $query->get();
        $results = [
            'total_accounts' => $accounts->count(),
            'successful_accounts' => 0,
            'failed_accounts' => 0,
            'total_emails' => 0,
            'account_results' => [],
            'onedrive_user' => $oneDriveUser->email
        ];

        foreach ($accounts as $account) {
            $accountResult = $this->backupSingleAccount($account, $oneDriveUser);
            $results['account_results'][$account->email] = $accountResult;
            
            if ($accountResult['success']) {
                $results['successful_accounts']++;
                $results['total_emails'] += $accountResult['emails_backed_up'];
            } else {
                $results['failed_accounts']++;
            }
        }

        Log::info("Organization backup completed", $results);
        return $results;
    }


    /**
     * Backup a single email account
     */
    public function backupSingleAccount(EmailAccount $account, User $oneDriveUser = null): array
    {
        if (!$oneDriveUser) {
            $oneDriveUser = $this->getOneDriveUser();
            if (!$oneDriveUser) {
                return [
                    'success' => false,
                    'error' => 'No valid user found for OneDrive operations'
                ];
            }
        }

        $backupJob = BackupJob::create([
            'email_account_id' => $account->id,
            'status' => 'running',
            'started_at' => Carbon::now(),
            'onedrive_user_id' => $oneDriveUser->id // Track which user is doing the backup
        ]);

        try {
            Log::info("Starting backup for account: {$account->email}", [
                'onedrive_user' => $oneDriveUser->email
            ]);

            // Connect to IMAP
            $connectionResult = $this->connectToIMAP($account);
            if (!$connectionResult['success']) {
                throw new Exception("Failed to connect to IMAP server: " . $connectionResult['error']);
            }
            
            $client = $connectionResult['client'];

            // Get all folders/mailboxes
            $folders = $client->getFolders();
            $totalEmails = 0;
            $backedUpMailboxes = [];
            $maildirBasePath = $this->createAccountMaildirStructure($account);

            foreach ($folders as $folder) {
                $folderResult = $this->backupMailboxFolder($account, $folder, $maildirBasePath);
                $totalEmails += $folderResult['email_count'];
                $backedUpMailboxes[] = [
                    'name' => $folder->name,
                    'email_count' => $folderResult['email_count'],
                    'size_mb' => $folderResult['size_mb']
                ];
            }

            // Compress and upload to OneDrive
            $uploadResult = $this->uploadAccountBackupToOneDrive($account, $maildirBasePath, $oneDriveUser);
            if (!$uploadResult['success']) {
                throw new Exception("Failed to upload to OneDrive: " . $uploadResult['error']);
            }
            
            $oneDrivePath = $uploadResult['path'];

            // Update account last backup time
            $account->update(['last_backup' => Carbon::now()]);

            // Complete backup job
            $backupJob->update([
                'status' => 'completed',
                'emails_backed_up' => $totalEmails,
                'backup_path' => $oneDrivePath,
                'mailboxes_backed_up' => $backedUpMailboxes,
                'completed_at' => Carbon::now()
            ]);

            Log::info("Account backup completed: {$account->email}", [
                'emails' => $totalEmails,
                'mailboxes' => count($backedUpMailboxes),
                'onedrive_user' => $oneDriveUser->email
            ]);

            return [
                'success' => true,
                'emails_backed_up' => $totalEmails,
                'mailboxes_count' => count($backedUpMailboxes),
                'onedrive_path' => $oneDrivePath,
                'onedrive_user' => $oneDriveUser->email
            ];

        } catch (Exception $e) {
            Log::error("Account backup failed: {$account->email}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'onedrive_user' => $oneDriveUser->email
            ]);

            $backupJob->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at' => Carbon::now()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Sync new emails for all accounts since last sync
     */
    public function syncNewEmailsForAllAccounts(): array
    {
        $oneDriveUser = $this->getOneDriveUser();
        if (!$oneDriveUser) {
            return [
                'error' => 'No valid user found for OneDrive operations',
                'total_accounts' => 0,
                'successful_syncs' => 0,
                'failed_syncs' => 0,
                'total_new_emails' => 0
            ];
        }

        $lastSync = SyncConfiguration::getValue('last_organization_sync');
        $since = $lastSync ? Carbon::parse($lastSync) : Carbon::now()->subHours(24);
        
        Log::info("Starting organization-wide email sync", [
            'since' => $since,
            'onedrive_user' => $oneDriveUser->email
        ]);

        $accounts = EmailAccount::where('active', true)->get();
        $results = [
            'total_accounts' => $accounts->count(),
            'successful_syncs' => 0,
            'failed_syncs' => 0,
            'total_new_emails' => 0,
            'onedrive_user' => $oneDriveUser->email
        ];

        foreach ($accounts as $account) {
            $syncResult = $this->syncAccountNewEmails($account, $since, $oneDriveUser);
            if ($syncResult['success']) {
                $results['successful_syncs']++;
                $results['total_new_emails'] += $syncResult['new_emails'];
            } else {
                $results['failed_syncs']++;
            }
        }

        // Update last sync time
        SyncConfiguration::setValue('last_organization_sync', Carbon::now());
        
        Log::info("Organization email sync completed", $results);
        return $results;
    }

    /**
     * Sync new emails for a single account
     */
    public function syncAccountNewEmails(EmailAccount $account, Carbon $since, User $oneDriveUser = null): array
    {
        if (!$oneDriveUser) {
            $oneDriveUser = $this->getOneDriveUser();
            if (!$oneDriveUser) {
                return [
                    'success' => false,
                    'error' => 'No valid user found for OneDrive operations'
                ];
            }
        }

        try {
            $connectionResult = $this->connectToIMAP($account);
            if (!$connectionResult['success']) {
                throw new Exception("Failed to connect to IMAP server: " . $connectionResult['error']);
            }
            
            $client = $connectionResult['client'];
            $folders = $client->getFolders();
            $totalNewEmails = 0;

            foreach ($folders as $folder) {
                // Get emails since last sync
                $messages = $folder->messages()->since($since)->get();
                
                foreach ($messages as $message) {
                    if ($this->backupSingleEmail($account, $folder->name, $message, $oneDriveUser)) {
                        $totalNewEmails++;
                    }
                }
            }

            return [
                'success' => true,
                'new_emails' => $totalNewEmails
            ];

        } catch (Exception $e) {
            Log::error("Account sync failed: {$account->email}", [
                'error' => $e->getMessage(),
                'onedrive_user' => $oneDriveUser->email
            ]);
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Connect to IMAP server for specific account
     */
    public function connectToIMAP(EmailAccount $account): array
    {
        try {
            $cm = new ClientManager();
            
            // Determine encryption based on port and SSL setting
            $encryption = $this->determineEncryption($account);
            
            $config = [
                'host' => $account->imap_host,
                'port' => $account->imap_port,
                'encryption' => $encryption,
                'validate_cert' => $account->validate_cert ?? true,
                'username' => $account->username,
                'password' => decrypt($account->password),
                'protocol' => 'imap',
                'timeout' => 30,
            ];
            
            Log::info("Attempting IMAP connection", [
                'host' => $account->imap_host,
                'port' => $account->imap_port,
                'encryption' => $encryption,
                'username' => $account->username,
            ]);
            
            $client = $cm->make($config);
            $client->connect();
            
            // Test if we can actually use the connection
            $folders = $client->getFolders();
            
            Log::info("IMAP connection successful for {$account->email}");
            
            return [
                'success' => true, 
                'client' => $client,
                'folders_count' => count($folders)
            ];
            
        } catch (\Webklex\PHPIMAP\Exceptions\ConnectionFailedException $e) {
            $error = "Connection failed: " . $e->getMessage();
            Log::error("IMAP connection failed for {$account->email}: " . $error);
            return ['success' => false, 'error' => $error];
            
        } catch (\Webklex\PHPIMAP\Exceptions\AuthFailedException $e) {
            $error = "Authentication failed: Check username/password";
            Log::error("IMAP auth failed for {$account->email}: " . $e->getMessage());
            return ['success' => false, 'error' => $error];
            
        } catch (\Exception $e) {
            $error = "General error: " . $e->getMessage();
            Log::error("IMAP connection error for {$account->email}: " . $error);
            return ['success' => false, 'error' => $error];
        }
    }

    /**
     * Determine the correct encryption method based on port and SSL setting
     */
    private function determineEncryption(EmailAccount $account): ?string
    {
        // If SSL is explicitly set
        if ($account->imap_ssl) {
            return 'ssl';
        }
        
        // Determine by port
        switch ($account->imap_port) {
            case 993:
                return 'ssl';
            case 143:
                return 'tls'; // or null for plain
            default:
                return $account->imap_ssl ? 'ssl' : null;
        }
    }

    /**
     * Test OneDrive connectivity
     */
    public function testOneDriveConnection(): array
    {
        try {
            $oneDriveUser = $this->getOneDriveUser();
            if (!$oneDriveUser) {
                return ['success' => false, 'error' => 'No valid user found for OneDrive operations'];
            }
            
            $connected = $this->oneDriveService->testConnection($oneDriveUser);
            
            if (!$connected) {
                return [
                    'success' => false, 
                    'error' => 'OneDrive connection failed - user needs to authenticate',
                    'user' => $oneDriveUser->email
                ];
            }
            
            // Test creating the root folder
            $rootFolder = $this->oneDriveService->ensureRootFolder($oneDriveUser);
            
            return [
                'success' => true,
                'user' => $oneDriveUser->email,
                'root_folder' => $rootFolder['name'] ?? 'Created'
            ];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Create Maildir directory structure for account
     */
    private function createAccountMaildirStructure(EmailAccount $account): string
    {
        $basePath = storage_path('app/maildir-backups');
        $accountPath = $basePath . '/' . $account->email . '/' . Carbon::now()->format('Y-m-d_H-i-s');
        
        if (!is_dir($accountPath)) {
            mkdir($accountPath, 0755, true);
        }

        return $accountPath;
    }

    /**
     * Backup specific mailbox folder to Maildir format
     */
    private function backupMailboxFolder(EmailAccount $account, $folder, string $maildirBasePath): array
    {
        $folderPath = $maildirBasePath . '/' . $this->sanitizeFolderName($folder->name);
        $this->maildirService->createMaildirStructure($folderPath);

        $messages = $folder->messages()->all()->get();
        $emailCount = 0;
        $totalSize = 0;

        foreach ($messages as $message) {
            if ($this->maildirService->saveEmailToMaildir($message, $folderPath)) {
                $emailCount++;
                $totalSize += strlen($message->getRawBody());
            }
        }

        return [
            'email_count' => $emailCount,
            'size_mb' => round($totalSize / (1024 * 1024), 2)
        ];
    }

    /**
     * Backup single email to existing Maildir structure
     */
    private function backupSingleEmail(EmailAccount $account, string $folderName, $message, User $oneDriveUser = null): bool
    {
        if (!$oneDriveUser) {
            $oneDriveUser = $this->getOneDriveUser();
            if (!$oneDriveUser) {
                Log::error("No valid user found for OneDrive operations");
                return false;
            }
        }

        try {
            // Check if already backed up
            $messageId = $message->getMessageId();
            $existingBackup = MailBackup::where([
                'email_account_id' => $account->id,
                'message_id' => $messageId
            ])->first();

            if ($existingBackup && $existingBackup->status === 'completed') {
                return true;
            }

            // Create temporary Maildir structure for this single email
            $tempPath = storage_path('app/temp-maildir/' . $account->email . '/' . $folderName);
            $this->maildirService->createMaildirStructure($tempPath);
            
            // Save email to Maildir
            if ($this->maildirService->saveEmailToMaildir($message, $tempPath)) {
                // Upload to OneDrive
                $oneDrivePath = $this->generateOneDrivePath($account, $folderName, $message);
                $success = $this->uploadEmailToOneDrive($tempPath, $oneDrivePath, $oneDriveUser);

                // Record backup
                MailBackup::updateOrCreate(
                    [
                        'email_account_id' => $account->id,
                        'message_id' => $messageId
                    ],
                    [
                        'mailbox_folder' => $folderName,
                        'onedrive_path' => $oneDrivePath,
                        'status' => $success ? 'completed' : 'failed',
                        'size' => strlen($message->getRawBody())
                    ]
                );

                // Cleanup temp files
                $this->cleanupTempFiles($tempPath);
                
                return $success;
            }

            return false;

        } catch (Exception $e) {
            Log::error("Failed to backup single email", [
                'account' => $account->email,
                'folder' => $folderName,
                'error' => $e->getMessage(),
                'onedrive_user' => $oneDriveUser->email
            ]);
            return false;
        }
    }

    /**
     * Upload account backup to OneDrive
     */
    private function uploadAccountBackupToOneDrive(EmailAccount $account, string $maildirPath, User $oneDriveUser): array
    {
        try {
            $zipFile = $maildirPath . '.zip';
            $this->createZipFromDirectory($maildirPath, $zipFile);

            $oneDrivePath = $this->generateAccountOneDrivePath($account);
            
            // Determine upload method based on file size
            $fileSize = filesize($zipFile);
            if ($fileSize > 4 * 1024 * 1024) { // > 4MB
                $uploadResult = $this->oneDriveService->uploadLargeFile($zipFile, $oneDrivePath, $oneDriveUser);
            } else {
                $uploadResult = $this->oneDriveService->uploadSmallFile($zipFile, $oneDrivePath, $oneDriveUser);
            }
            
            if (!$uploadResult || !isset($uploadResult['id'])) {
                throw new Exception("OneDrive upload failed - no file ID returned");
            }

            // Cleanup local files
            if (file_exists($zipFile)) {
                unlink($zipFile);
            }
            $this->recursiveDelete($maildirPath);

            return [
                'success' => true,
                'path' => $oneDrivePath,
                'file_id' => $uploadResult['id']
            ];
            
        } catch (Exception $e) {
            Log::error("Failed to upload to OneDrive", [
                'account' => $account->email,
                'error' => $e->getMessage(),
                'onedrive_user' => $oneDriveUser->email
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Upload single email to OneDrive
     */
    private function uploadEmailToOneDrive(string $localPath, string $oneDrivePath, User $oneDriveUser): bool
    {
        try {
            // Create the directory structure first
            $parentDir = dirname($oneDrivePath);
            $this->ensureOneDriveDirectory($parentDir, $oneDriveUser);

            // Find the email file in the Maildir structure
            $emailFiles = array_merge(
                glob($localPath . '/cur/*'),
                glob($localPath . '/new/*')
            );

            if (empty($emailFiles)) {
                Log::error("No email files found in Maildir structure", ['path' => $localPath]);
                return false;
            }

            $emailFile = $emailFiles[0]; // Take the first (should be only one)
            
            $fileSize = filesize($emailFile);
            if ($fileSize > 4 * 1024 * 1024) { // > 4MB
                $result = $this->oneDriveService->uploadLargeFile($emailFile, $oneDrivePath, $oneDriveUser);
            } else {
                $result = $this->oneDriveService->uploadSmallFile($emailFile, $oneDrivePath, $oneDriveUser);
            }

            return $result && isset($result['id']);

        } catch (Exception $e) {
            Log::error("Failed to upload email to OneDrive", [
                'local_path' => $localPath,
                'onedrive_path' => $oneDrivePath,
                'error' => $e->getMessage(),
                'onedrive_user' => $oneDriveUser->email
            ]);
            return false;
        }
    }

    /**
     * Ensure OneDrive directory exists
     */
   private function ensureOneDriveDirectory(string $path, User $oneDriveUser): bool
    {
        try {
            // Check if directory already exists
            if ($this->oneDriveService->fileExists($path, $oneDriveUser)) {
                return true;
            }

            // Create directory structure
            $pathParts = explode('/', trim($path, '/'));
            $currentPath = '';

            foreach ($pathParts as $part) {
                $currentPath = $currentPath ? $currentPath . '/' . $part : $part;
                
                if (!$this->oneDriveService->fileExists($currentPath, $oneDriveUser)) {
                    $parentPath = dirname($currentPath);
                    $parentPath = $parentPath === '.' ? null : $parentPath;
                    
                    $this->oneDriveService->createFolder($part, $parentPath, $oneDriveUser);
                }
            }

            return true;

        } catch (Exception $e) {
            Log::error("Failed to ensure OneDrive directory", [
                'path' => $path,
                'error' => $e->getMessage(),
                'onedrive_user' => $oneDriveUser->email
            ]);
            return false;
        }
    }

    /**
     * Generate OneDrive path for account backup
     */
    private function generateAccountOneDrivePath(EmailAccount $account): string
    {
        $basePath = config('mail-backup.onedrive_base_path', 'ISP-Email-Backups');
        $datePath = Carbon::now()->format('Y-m-d');
        
        return "{$basePath}/{$datePath}/{$account->email}/maildir-backup.zip";
    }

    /**
     * Generate OneDrive path for individual email
     */
    private function generateOneDrivePath(EmailAccount $account, string $folder, $message): string
    {
        $basePath = config('mail-backup.onedrive_base_path', 'ISP-Email-Backups');
        $datePath = Carbon::now()->format('Y-m-d');
        $messageId = preg_replace('/[^a-zA-Z0-9\-_]/', '_', $message->getMessageId());
        
        return "{$basePath}/{$datePath}/{$account->email}/{$folder}/{$messageId}.eml";
    }

    /**
     * Get comprehensive backup statistics for organization
     */
    public function getOrganizationBackupStats(): array
    {
        $totalAccounts = EmailAccount::where('active', true)->count();
        $recentJobs = BackupJob::where('created_at', '>=', Carbon::now()->subDays(7))->get();
        
        return [
            'total_active_accounts' => $totalAccounts,
            'accounts_backed_up_today' => BackupJob::whereDate('created_at', Carbon::today())
                ->where('status', 'completed')
                ->distinct('email_account_id')
                ->count(),
            'total_emails_backed_up' => MailBackup::where('status', 'completed')->count(),
            'total_backup_size_mb' => round(MailBackup::where('status', 'completed')->sum('size') / (1024 * 1024), 2),
            'recent_job_stats' => [
                'completed' => $recentJobs->where('status', 'completed')->count(),
                'failed' => $recentJobs->where('status', 'failed')->count(),
                'running' => $recentJobs->where('status', 'running')->count()
            ],
            'department_breakdown' => $this->getDepartmentBackupStats(),
            'onedrive_user' => $this->defaultUser?->email ?? 'Not configured'
        ];
    }

    /**
     * Get backup statistics by department
     */
    private function getDepartmentBackupStats(): array
    {
        return EmailAccount::selectRaw('department, COUNT(*) as account_count, 
                                        SUM(CASE WHEN last_backup >= CURDATE() THEN 1 ELSE 0 END) as backed_up_today')
            ->where('active', true)
            ->groupBy('department')
            ->get()
            ->keyBy('department')
            ->toArray();
    }

    // Helper methods
    private function sanitizeFolderName(string $folderName): string
    {
        return preg_replace('/[^a-zA-Z0-9\-_]/', '_', $folderName);
    }

    private function createZipFromDirectory(string $source, string $destination): void
    {
        $zip = new \ZipArchive();
        $zip->open($destination, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $name => $file) {
            if (!$file->isDir()) {
                $filePath = $file->getRealPath();
                $relativePath = substr($filePath, strlen($source) + 1);
                $zip->addFile($filePath, $relativePath);
            }
        }
        
        $zip->close();
    }

    private function recursiveDelete(string $dir): void
    {
        if (is_dir($dir)) {
            $objects = scandir($dir);
            foreach ($objects as $object) {
                if ($object != "." && $object != "..") {
                    if (is_dir($dir . "/" . $object) && !is_link($dir . "/" . $object)) {
                        $this->recursiveDelete($dir . "/" . $object);
                    } else {
                        unlink($dir . "/" . $object);
                    }
                }
            }
            rmdir($dir);
        }
    }

    private function cleanupTempFiles(string $path): void
    {
        if (is_dir($path)) {
            $this->recursiveDelete($path);
        }
    }
}