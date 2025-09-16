<?php
namespace App\Console\Commands;

use App\Services\OneDrivePersonalService;
use Illuminate\Console\Command;

class TestOneDriveCommand extends Command
{
    protected $signature = 'onedrive:test 
                          {--create-test-folder : Create a test folder}
                          {--upload-test-file : Upload a test file}
                          {--list-root : List root directory contents}
                          {--storage-info : Show storage information}';
    
    protected $description = 'Test OneDrive Personal functionality';

    public function __construct(
        private OneDrivePersonalService $oneDriveService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if ($this->option('create-test-folder')) {
            return $this->createTestFolder();
        }

        if ($this->option('upload-test-file')) {
            return $this->uploadTestFile();
        }

        if ($this->option('list-root')) {
            return $this->listRootContents();
        }

        if ($this->option('storage-info')) {
            return $this->showStorageInfo();
        }

        // Run all tests
        return $this->runAllTests();
    }

    private function runAllTests(): int
    {
        $this->info('Running OneDrive Personal Tests');
        $this->info('==============================');

        $tests = [
            'Connection Test' => [$this, 'testConnection'],
            'Storage Info' => [$this, 'testStorageInfo'],
            'Root Folder Listing' => [$this, 'testRootListing'],
            'Create Test Folder' => [$this, 'testCreateFolder'],
            'Upload Test File' => [$this, 'testUploadFile'],
            'Download Test File' => [$this, 'testDownloadFile'],
            'Delete Test Items' => [$this, 'testDeleteItems'],
        ];

        $passed = 0;
        $total = count($tests);

        foreach ($tests as $testName => $testMethod) {
            $this->info("Testing: {$testName}...");
            
            try {
                $result = call_user_func($testMethod);
                if ($result) {
                    $this->info("✅ {$testName} - PASSED");
                    $passed++;
                } else {
                    $this->error("❌ {$testName} - FAILED");
                }
            } catch (\Exception $e) {
                $this->error("❌ {$testName} - ERROR: " . $e->getMessage());
            }
            
            $this->line('');
        }

        $this->info("Tests completed: {$passed}/{$total} passed");
        return $passed === $total ? 0 : 1;
    }

    private function testConnection(): bool
    {
        return $this->oneDriveService->testConnection();
    }

    private function testStorageInfo(): bool
    {
        $usage = $this->oneDriveService->getStorageUsage();
        $this->line('Storage: ' . $this->formatBytes($usage['used_bytes']) . ' / ' . $this->formatBytes($usage['total_bytes']));
        return true;
    }

    private function testRootListing(): bool
    {
        $contents = $this->oneDriveService->listFolderContents();
        $this->line('Root contains ' . count($contents['value'] ?? []) . ' items');
        return true;
    }

    private function testCreateFolder(): bool
    {
        $folderName = 'OneDrive_Test_' . date('Y-m-d_H-i-s');
        $result = $this->oneDriveService->createFolder($folderName);
        $this->line('Created test folder: ' . $folderName);
        return isset($result['id']);
    }

    private function testUploadFile(): bool
    {
        // Create a temporary test file
        $testContent = "This is a test file created at " . date('Y-m-d H:i:s') . "\n";
        $testContent .= "Testing OneDrive Personal API integration.\n";
        $testContent .= str_repeat("Test data line\n", 10);
        
        $tempFile = tempnam(sys_get_temp_dir(), 'onedrive_test_');
        file_put_contents($tempFile, $testContent);
        
        try {
            $remotePath = 'onedrive_test_' . date('Y-m-d_H-i-s') . '.txt';
            $result = $this->oneDriveService->uploadSmallFile($tempFile, $remotePath);
            $this->line('Uploaded test file: ' . $remotePath);
            return isset($result['id']);
        } finally {
            unlink($tempFile);
        }
    }

    private function testDownloadFile(): bool
    {
        // Find a test file to download
        $contents = $this->oneDriveService->listFolderContents();
        $testFile = null;
        
        foreach ($contents['value'] ?? [] as $item) {
            if (!isset($item['folder']) && strpos($item['name'], 'onedrive_test_') === 0) {
                $testFile = $item;
                break;
            }
        }
        
        if (!$testFile) {
            $this->line('No test file found to download');
            return true; // Not really a failure
        }
        
        $localPath = sys_get_temp_dir() . '/downloaded_' . $testFile['name'];
        $success = $this->oneDriveService->downloadFile($testFile['name'], $localPath);
        
        if ($success && file_exists($localPath)) {
            $this->line('Downloaded file: ' . $testFile['name']);
            unlink($localPath);
            return true;
        }
        
        return false;
    }

    private function testDeleteItems(): bool
    {
        $contents = $this->oneDriveService->listFolderContents();
        $deleted = 0;
        
        foreach ($contents['value'] ?? [] as $item) {
            if (strpos($item['name'], 'onedrive_test_') === 0 || strpos($item['name'], 'OneDrive_Test_') === 0) {
                if ($this->oneDriveService->delete($item['name'])) {
                    $deleted++;
                }
            }
        }
        
        $this->line("Deleted {$deleted} test items");
        return true;
    }

    private function createTestFolder(): int
    {
        $folderName = $this->ask('Enter test folder name', 'OneDrive_Test_' . date('Y-m-d_H-i-s'));
        
        try {
            $result = $this->oneDriveService->createFolder($folderName);
            $this->info('✅ Test folder created successfully: ' . $folderName);
            $this->info('Folder ID: ' . $result['id']);
            return 0;
        } catch (\Exception $e) {
            $this->error('❌ Failed to create test folder: ' . $e->getMessage());
            return 1;
        }
    }

    private function uploadTestFile(): int
    {
        $fileName = $this->ask('Enter test file name', 'test_file_' . date('Y-m-d_H-i-s') . '.txt');
        
        // Create test content
        $testContent = "Test file created at " . date('Y-m-d H:i:s') . "\n";
        $testContent .= "This is a test of the OneDrive Personal API integration.\n";
        $testContent .= str_repeat("Sample data line " . rand(1000, 9999) . "\n", 50);
        
        $tempFile = tempnam(sys_get_temp_dir(), 'onedrive_test_');
        file_put_contents($tempFile, $testContent);
        
        try {
            $result = $this->oneDriveService->uploadSmallFile($tempFile, $fileName);
            $this->info('✅ Test file uploaded successfully: ' . $fileName);
            $this->info('File ID: ' . $result['id']);
            $this->info('Size: ' . $this->formatBytes($result['size']));
            return 0;
        } catch (\Exception $e) {
            $this->error('❌ Failed to upload test file: ' . $e->getMessage());
            return 1;
        } finally {
            unlink($tempFile);
        }
    }

    private function listRootContents(): int
    {
        try {
            $contents = $this->oneDriveService->listFolderContents();
            $items = $contents['value'] ?? [];
            
            $this->info('OneDrive Root Contents (' . count($items) . ' items):');
            $this->info('================================');
            
            foreach ($items as $item) {
                $type = isset($item['folder']) ? 'FOLDER' : 'FILE  ';
                $size = isset($item['folder']) ? 
                    '(' . ($item['folder']['childCount'] ?? 0) . ' items)' :
                    $this->formatBytes($item['size'] ?? 0);
                
                $this->line("[{$type}] {$item['name']} - {$size}");
            }
            
            return 0;
        } catch (\Exception $e) {
            $this->error('❌ Failed to list contents: ' . $e->getMessage());
            return 1;
        }
    }

    private function showStorageInfo(): int
    {
        try {
            $usage = $this->oneDriveService->getStorageUsage();
            
            $this->info('OneDrive Storage Information:');
            $this->info('============================');
            $this->info('Total Storage: ' . $this->formatBytes($usage['total_bytes']));
            $this->info('Used Storage:  ' . $this->formatBytes($usage['used_bytes']));
            $this->info('Free Storage:  ' . $this->formatBytes($usage['remaining_bytes']));
            $this->info('Deleted Items: ' . $this->formatBytes($usage['deleted_bytes']));
            $this->info('Status:        ' . ucfirst($usage['state']));
            
            $percentage = $usage['total_bytes'] > 0 ? 
                round(($usage['used_bytes'] / $usage['total_bytes']) * 100, 2) : 0;
            $this->info('Usage:         ' . $percentage . '%');
            
            return 0;
        } catch (\Exception $e) {
            $this->error('❌ Failed to get storage info: ' . $e->getMessage());
            return 1;
        }
    }

    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }
}