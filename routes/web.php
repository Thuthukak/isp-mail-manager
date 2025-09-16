<?php

use Illuminate\Support\Facades\Route;
use App\Services\MailBackupService;
use App\Http\Controllers\Auth\OneDriveAuthController;
use App\Services\MaildirService;
use App\Models\EmailAccount;

Route::get('/', function () {
    return view('welcome');
});

// In your web.php routes
Route::middleware(['auth'])->group(function () {
     Route::get('/onedrive/auth/authenticate', [OneDriveAuthController::class, 'authenticate'])
        ->name('onedrive.auth.authenticate');
    
    Route::get('/onedrive/auth/callback', [OneDriveAuthController::class, 'callback'])
        ->name('onedrive.auth.callback');
});

// Test OneDrive connection
Route::get('/test/onedrive-connection', function () {
    try {
        $backupService = app(MailBackupService::class);
        $result = $backupService->testOneDriveConnection();
        
        return response()->json([
            'test' => 'OneDrive Connection',
            'result' => $result,
            'status' => $result['success'] ? 'PASS' : 'FAIL'
        ]);
    } catch (Exception $e) {
        return response()->json([
            'test' => 'OneDrive Connection',
            'error' => $e->getMessage(),
            'status' => 'ERROR'
        ], 500);
    }
});

// Test Maildir structure creation
Route::get('/test/maildir-structure', function () {
    try {
        $maildirService = app(MaildirService::class);
        $testPath = storage_path('app/test-maildir-' . time());
        
        $created = $maildirService->createMaildirStructure($testPath);
        $validated = $maildirService->validateMaildirStructure($testPath);
        $stats = $maildirService->getMaildirStats($testPath);
        
        // Clean up test directory
        if (is_dir($testPath)) {
            rmdir($testPath . '/new');
            rmdir($testPath . '/cur');
            rmdir($testPath . '/tmp');
            rmdir($testPath);
        }
        
        return response()->json([
            'test' => 'Maildir Structure',
            'created' => $created,
            'validated' => $validated,
            'stats' => $stats,
            'status' => ($created && $validated) ? 'PASS' : 'FAIL'
        ]);
    } catch (Exception $e) {
        return response()->json([
            'test' => 'Maildir Structure',
            'error' => $e->getMessage(),
            'status' => 'ERROR'
        ], 500);
    }
});

// Test IMAP connection for first active account
Route::get('/test/imap-connection', function () {
    try {
        $account = EmailAccount::where('active', true)->first();
        
        if (!$account) {
            return response()->json([
                'test' => 'IMAP Connection',
                'error' => 'No active email accounts found',
                'status' => 'SKIP'
            ]);
        }
        
        $backupService = app(MailBackupService::class);
        $result = $backupService->connectToIMAP($account);
        
        return response()->json([
            'test' => 'IMAP Connection',
            'account' => $account->email,
            'result' => $result,
            'status' => $result['success'] ? 'PASS' : 'FAIL'
        ]);
    } catch (Exception $e) {
        return response()->json([
            'test' => 'IMAP Connection',
            'error' => $e->getMessage(),
            'status' => 'ERROR'
        ], 500);
    }
});

// Test backup statistics
Route::get('/test/backup-stats', function () {
    try {
        $backupService = app(MailBackupService::class);
        $stats = $backupService->getOrganizationBackupStats();
        
        return response()->json([
            'test' => 'Backup Statistics',
            'stats' => $stats,
            'status' => 'PASS'
        ]);
    } catch (Exception $e) {
        return response()->json([
            'test' => 'Backup Statistics',
            'error' => $e->getMessage(),
            'status' => 'ERROR'
        ], 500);
    }
});

// Test single account backup (careful - this actually performs a backup)
Route::get('/test/single-backup/{accountId?}', function ($accountId = null) {
    try {
        if ($accountId) {
            $account = EmailAccount::findOrFail($accountId);
        } else {
            $account = EmailAccount::where('active', true)->first();
        }
        
        if (!$account) {
            return response()->json([
                'test' => 'Single Account Backup',
                'error' => 'No active email accounts found',
                'status' => 'SKIP'
            ]);
        }
        
        // Add a safety check
        if (app()->environment('production')) {
            return response()->json([
                'test' => 'Single Account Backup',
                'error' => 'Test backups disabled in production',
                'status' => 'DISABLED'
            ]);
        }
        
        $backupService = app(MailBackupService::class);
        $result = $backupService->backupSingleAccount($account);
        
        return response()->json([
            'test' => 'Single Account Backup',
            'account' => $account->email,
            'result' => $result,
            'status' => $result['success'] ? 'PASS' : 'FAIL'
        ]);
    } catch (Exception $e) {
        return response()->json([
            'test' => 'Single Account Backup',
            'error' => $e->getMessage(),
            'status' => 'ERROR'
        ], 500);
    }
});

// Test all components (comprehensive test)
Route::get('/test/all-components', function () {
    $tests = [];
    
    // 1. Test OneDrive Connection
    try {
        $backupService = app(MailBackupService::class);
        $oneDriveResult = $backupService->testOneDriveConnection();
        $tests['onedrive'] = [
            'status' => $oneDriveResult['success'] ? 'PASS' : 'FAIL',
            'result' => $oneDriveResult
        ];
    } catch (Exception $e) {
        $tests['onedrive'] = [
            'status' => 'ERROR',
            'error' => $e->getMessage()
        ];
    }
    
    // 2. Test IMAP Connection
    try {
        $account = EmailAccount::where('active', true)->first();
        if ($account) {
            $imapResult = $backupService->connectToIMAP($account);
            $tests['imap'] = [
                'status' => $imapResult['success'] ? 'PASS' : 'FAIL',
                'account' => $account->email,
                'result' => $imapResult
            ];
        } else {
            $tests['imap'] = [
                'status' => 'SKIP',
                'message' => 'No active accounts'
            ];
        }
    } catch (Exception $e) {
        $tests['imap'] = [
            'status' => 'ERROR',
            'error' => $e->getMessage()
        ];
    }
    
    // 3. Test Maildir Service
    try {
        $maildirService = app(MaildirService::class);
        $testPath = storage_path('app/test-maildir-' . time());
        $created = $maildirService->createMaildirStructure($testPath);
        $validated = $maildirService->validateMaildirStructure($testPath);
        
        $tests['maildir'] = [
            'status' => ($created && $validated) ? 'PASS' : 'FAIL',
            'created' => $created,
            'validated' => $validated
        ];
        
        // Cleanup
        if (is_dir($testPath)) {
            rmdir($testPath . '/new');
            rmdir($testPath . '/cur');
            rmdir($testPath . '/tmp');
            rmdir($testPath);
        }
    } catch (Exception $e) {
        $tests['maildir'] = [
            'status' => 'ERROR',
            'error' => $e->getMessage()
        ];
    }
    
    // 4. Test Statistics
    try {
        $stats = $backupService->getOrganizationBackupStats();
        $tests['statistics'] = [
            'status' => 'PASS',
            'stats' => $stats
        ];
    } catch (Exception $e) {
        $tests['statistics'] = [
            'status' => 'ERROR',
            'error' => $e->getMessage()
        ];
    }
    
    $overallStatus = collect($tests)->every(fn($test) => $test['status'] === 'PASS') ? 'ALL_PASS' : 'SOME_FAILED';
    
    return response()->json([
        'overall_status' => $overallStatus,
        'tests' => $tests,
        'timestamp' => now()
    ]);
});

Route::get('/mailbox-monitoring/export', function() {
    // Export logic here
    return response()->download(/* your export file */);
})->name('mailbox-monitoring.export');

Route::get('/test-onedrive-backup', function() {
    $mailBackupService = app(MailBackupService::class);
    return $mailBackupService->testOneDriveConnection();
});