<?php
namespace App\Console\Commands;

use App\Services\MicrosoftAuthService;
use App\Services\OneDrivePersonalService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Session;

class AuthenticateMicrosoftCommand extends Command
{
    protected $signature = 'microsoft:auth 
                          {--test : Test existing authentication}
                          {--revoke : Revoke existing tokens}
                          {--status : Show authentication status}';
    
    protected $description = 'Authenticate with Microsoft OneDrive for personal accounts';

    public function __construct(
        private MicrosoftAuthService $authService,
        private OneDrivePersonalService $oneDriveService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if ($this->option('test')) {
            return $this->testAuthentication();
        }

        if ($this->option('revoke')) {
            return $this->revokeAuthentication();
        }

        if ($this->option('status')) {
            return $this->showAuthenticationStatus();
        }

        return $this->setupAuthentication();
    }

    private function setupAuthentication(): int
    {
        $this->info('Microsoft OneDrive Personal Authentication Setup');
        $this->info('==============================================');

        // Generate auth URL
        $authUrl = $this->authService->getAuthorizationUrl();

        $this->info('Please visit the following URL to authenticate:');
        $this->line('');
        $this->line($authUrl);
        $this->line('');
        $this->info('After authentication, you will be redirected to your redirect URI.');
        $this->info('Copy the authorization code from the URL and paste it below.');
        $this->line('');

        $code = $this->ask('Enter the authorization code');

        if (empty($code)) {
            $this->error('No authorization code provided.');
            return 1;
        }

        try {
            $this->info('Exchanging code for access token...');
            $tokenData = $this->authService->getAccessTokenFromCode($code);
            
            $this->info('Getting user information...');
            $userInfo = $this->authService->getUserInfo($tokenData['access_token']);
            
            $this->info('Storing token...');
            $this->authService->storeToken($tokenData);
            
            $this->info('Testing OneDrive connection...');
            $driveInfo = $this->oneDriveService->getDriveInfo();
            
            $this->info('✅ Authentication successful!');
            $this->line('');
            $this->info('User: ' . ($userInfo['displayName'] ?? $userInfo['userPrincipalName'] ?? 'Unknown'));
            $this->info('Drive: ' . ($driveInfo['name'] ?? 'Personal OneDrive'));
            $this->info('Storage: ' . $this->formatBytes($driveInfo['quota']['used'] ?? 0) . ' / ' . 
                       $this->formatBytes($driveInfo['quota']['total'] ?? 0));
            
            return 0;
        } catch (\Exception $e) {
            $this->error('Authentication failed: ' . $e->getMessage());
            return 1;
        }
    }

    private function testAuthentication(): int
    {
        $this->info('Testing Microsoft OneDrive Authentication...');
        
        try {
            $accessToken = $this->authService->getValidAccessToken();
            
            if (!$accessToken) {
                $this->error('❌ No valid access token found. Please re-authenticate.');
                return 1;
            }

            $this->info('✅ Valid access token found.');
            
            $userInfo = $this->authService->getUserInfo($accessToken);
            $this->info('✅ User info retrieved successfully.');
            
            $connectionTest = $this->oneDriveService->testConnection();
            
            if ($connectionTest) {
                $this->info('✅ OneDrive connection test successful.');
                
                $driveInfo = $this->oneDriveService->getDriveInfo();
                $this->line('');
                $this->info('User: ' . ($userInfo['displayName'] ?? $userInfo['userPrincipalName'] ?? 'Unknown'));
                $this->info('Drive: ' . ($driveInfo['name'] ?? 'Personal OneDrive'));
                $this->info('Storage: ' . $this->formatBytes($driveInfo['quota']['used'] ?? 0) . ' / ' . 
                           $this->formatBytes($driveInfo['quota']['total'] ?? 0));
                
                return 0;
            } else {
                $this->error('❌ OneDrive connection test failed.');
                return 1;
            }
        } catch (\Exception $e) {
            $this->error('❌ Authentication test failed: ' . $e->getMessage());
            return 1;
        }
    }

    private function revokeAuthentication(): int
    {
        $this->warn('This will revoke all stored Microsoft tokens.');
        
        if (!$this->confirm('Are you sure you want to continue?')) {
            $this->info('Operation cancelled.');
            return 0;
        }

        try {
            $this->authService->revokeToken();
            $this->info('✅ Tokens revoked successfully.');
            return 0;
        } catch (\Exception $e) {
            $this->error('Failed to revoke tokens: ' . $e->getMessage());
            return 1;
        }
    }

    private function showAuthenticationStatus(): int
    {
        $this->info('Microsoft Authentication Status');
        $this->info('================================');

        try {
            $accessToken = $this->authService->getValidAccessToken();
            
            if (!$accessToken) {
                $this->warn('❌ No valid access token found.');
                $this->info('Run "php artisan microsoft:auth" to authenticate.');
                return 1;
            }

            $this->info('✅ Valid access token found.');
            
            // Get token details from database
            $token = \App\Models\OAuthToken::forProvider('microsoft')->first();
            
            if ($token) {
                $this->info('Token expires: ' . ($token->expires_at ? $token->expires_at->format('Y-m-d H:i:s') : 'Never'));
                $this->info('Has refresh token: ' . ($token->refresh_token ? 'Yes' : 'No'));
                
                if ($token->isExpiringSoon(60)) {
                    $this->warn('⚠️  Token expires soon!');
                }
            }

            // Test connection
            if ($this->oneDriveService->testConnection()) {
                $this->info('✅ OneDrive connection active.');
                
                $driveInfo = $this->oneDriveService->getDriveInfo();
                $userInfo = $this->authService->getUserInfo($accessToken);
                
                $this->line('');
                $this->info('User: ' . ($userInfo['displayName'] ?? $userInfo['userPrincipalName'] ?? 'Unknown'));
                $this->info('Email: ' . ($userInfo['mail'] ?? $userInfo['userPrincipalName'] ?? 'Unknown'));
                $this->info('Drive: ' . ($driveInfo['name'] ?? 'Personal OneDrive'));
                $this->info('Drive ID: ' . ($driveInfo['id'] ?? 'Unknown'));
                $this->info('Storage Used: ' . $this->formatBytes($driveInfo['quota']['used'] ?? 0));
                $this->info('Total Storage: ' . $this->formatBytes($driveInfo['quota']['total'] ?? 0));
                $this->info('Remaining: ' . $this->formatBytes($driveInfo['quota']['remaining'] ?? 0));
            } else {
                $this->error('❌ OneDrive connection failed.');
            }
            
            return 0;
        } catch (\Exception $e) {
            $this->error('Error checking status: ' . $e->getMessage());
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