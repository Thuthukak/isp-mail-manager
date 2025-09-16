<?php

namespace App\Console\Commands;

use App\Services\PersonalOneDriveService;
use Illuminate\Console\Command;

class AuthorizePersonalOneDrive extends Command
{
    protected $signature = 'auth:personal-onedrive {--code= : Authorization code from Microsoft}';
    protected $description = 'Authorize access to personal OneDrive account';

    public function handle(PersonalOneDriveService $oneDriveService)
    {
        $authCode = $this->option('code');
        
        if (!$authCode) {
            $this->info('Personal OneDrive Authorization Setup');
            $this->newLine();
            
            $this->warn('Step 1: Update your Azure App Registration');
            $this->line('Add this redirect URI in your Azure app:');
            $this->line('http://localhost/auth/callback');
            $this->newLine();
            
            $this->warn('Step 2: Visit this URL to authorize your app:');
            $authUrl = $oneDriveService->getAuthorizationUrl();
            $this->line($authUrl);
            $this->newLine();
            
            $this->warn('Step 3: After authorization, copy the "code" parameter from the callback URL');
            $this->warn('Step 4: Run this command again with the code:');
            $this->line('php artisan auth:personal-onedrive --code=YOUR_CODE_HERE');
            
            return;
        }
        
        try {
            $this->info('Exchanging authorization code for tokens...');
            
            $tokens = $oneDriveService->exchangeCodeForTokens($authCode);
            
            $this->info('Authorization successful!');
            $this->line('Access token expires in: ' . $tokens['expires_in'] . ' seconds');
            $this->newLine();
            
            // Test the connection
            $this->info('Testing OneDrive connection...');
            $testResult = $oneDriveService->testConnection();
            
            if ($testResult['success']) {
                $this->info('Connection test successful!');
                $this->line('Drive ID: ' . $testResult['drive_id']);
                $this->line('Drive Type: ' . $testResult['drive_type']);
                $this->line('Owner: ' . $testResult['owner']);
                $this->line('Total Space: ' . $this->formatBytes($testResult['quota_total']));
                $this->line('Used Space: ' . $this->formatBytes($testResult['quota_used']));
                $this->newLine();
                $this->info('Your personal OneDrive is now ready for use!');
            } else {
                $this->error('Connection test failed: ' . $testResult['error']);
            }
            
        } catch (\Exception $e) {
            $this->error('Authorization failed: ' . $e->getMessage());
        }
    }
    
    private function formatBytes($size, $precision = 2)
    {
        if ($size == 0) return '0 B';
        
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $base = log($size, 1024);
        
        return round(pow(1024, $base - floor($base)), $precision) . ' ' . $units[floor($base)];
    }
}