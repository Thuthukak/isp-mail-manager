<?php

namespace App\Filament\Pages;

use App\Services\MicrosoftAuthService;
use App\Services\OneDrivePersonalService;
use Filament\Pages\Page;
use Filament\Actions\Action;
use Filament\Support\Enums\ActionSize;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class OneDriveAuth extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-cloud';
    
    protected static string $view = 'filament.pages.onedrive-auth';
    
    protected static ?string $title = 'OneDrive Authentication';
    
    protected static ?string $navigationLabel = 'OneDrive Setup';
    
    protected static ?string $navigationGroup = 'Settings';
    
    protected static ?int $navigationSort = 99;

    public array $authStatus = [];

    public function mount(): void
    {
        // Check if user has permission
        if (!Auth::user()->hasRole(['super_admin', 'admin'])) {
            $this->redirect('/admin');
            return;
        }

        // Handle session flash messages from OAuth callback
        $this->handleSessionMessages();

        $this->loadAuthenticationStatus();
    }

    protected function handleSessionMessages(): void
    {
        // Handle success messages
        if (session()->has('success')) {
            Notification::make()
                ->title('Success')
                ->body(session('success'))
                ->success()
                ->send();
            
            session()->forget('success');
        }

        // Handle error messages
        if (session()->has('error')) {
            Notification::make()
                ->title('Authentication Error')
                ->body(session('error'))
                ->danger()
                ->send();
            
            session()->forget('error');
        }

        // Handle info messages
        if (session()->has('info')) {
            Notification::make()
                ->title('Information')
                ->body(session('info'))
                ->info()
                ->send();
            
            session()->forget('info');
        }

        // Handle warning messages
        if (session()->has('warning')) {
            Notification::make()
                ->title('Warning')
                ->body(session('warning'))
                ->warning()
                ->send();
            
            session()->forget('warning');
        }
    }

    public function loadAuthenticationStatus(): void
    {
        $user = Auth::user();
        $authService = app(MicrosoftAuthService::class);
        $oneDriveService = app(OneDrivePersonalService::class);

        try {
            $accessToken = $authService->getValidAccessToken($user);
            
            if (!$accessToken) {
                $this->authStatus = [
                    'authenticated' => false,
                    'status' => 'not_authenticated',
                    'message' => 'No valid access token found. Click "Authenticate" to connect to OneDrive.',
                    'status_color' => 'danger'
                ];
                return;
            }

            // Get token details
            $token = \App\Models\OAuthToken::where('user_id', $user->id)
                ->where('provider', 'microsoft')
                ->first();

            $tokenInfo = null;
            if ($token) {
                $tokenInfo = [
                    'expires_at' => $token->expires_at?->format('Y-m-d H:i:s'),
                    'expires_soon' => $token->isExpiringSoon(60),
                    'has_refresh_token' => !empty($token->refresh_token)
                ];
            }

            // Test connection
            if (!$oneDriveService->testConnection($user)) {
                $this->authStatus = [
                    'authenticated' => false,
                    'status' => 'connection_failed',
                    'message' => 'Authentication exists but OneDrive connection failed. Try re-authenticating.',
                    'status_color' => 'warning',
                    'token_info' => $tokenInfo
                ];
                return;
            }

            // Get user and drive info
            $userInfo = $authService->getUserInfo($accessToken);
            $driveInfo = $oneDriveService->getDriveInfo($user);
            $storageUsage = $oneDriveService->getStorageUsage($user);

            $this->authStatus = [
                'authenticated' => true,
                'status' => 'connected',
                'message' => 'Successfully connected to OneDrive',
                'status_color' => 'success',
                'token_info' => $tokenInfo,
                'user_info' => [
                    'display_name' => $userInfo['displayName'] ?? 'Unknown',
                    'email' => $userInfo['mail'] ?? $userInfo['userPrincipalName'] ?? 'Unknown',
                    'id' => $userInfo['id'] ?? null
                ],
                'drive_info' => [
                    'name' => $driveInfo['name'] ?? 'Personal OneDrive',
                    'id' => $driveInfo['id'] ?? null,
                    'drive_type' => $driveInfo['driveType'] ?? 'personal'
                ],
                'storage_usage' => [
                    'used_bytes' => $storageUsage['used_bytes'] ?? 0,
                    'total_bytes' => $storageUsage['total_bytes'] ?? 0,
                    'remaining_bytes' => $storageUsage['remaining_bytes'] ?? 0,
                    'used_formatted' => $this->formatBytes($storageUsage['used_bytes'] ?? 0),
                    'total_formatted' => $this->formatBytes($storageUsage['total_bytes'] ?? 0),
                    'remaining_formatted' => $this->formatBytes($storageUsage['remaining_bytes'] ?? 0),
                    'usage_percentage' => $storageUsage['total_bytes'] > 0 ? 
                        round(($storageUsage['used_bytes'] / $storageUsage['total_bytes']) * 100, 1) : 0
                ]
            ];

        } catch (\Exception $e) {
            Log::error('Error getting OneDrive authentication status', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);

            $this->authStatus = [
                'authenticated' => false,
                'status' => 'error',
                'message' => 'Error checking authentication status: ' . $e->getMessage(),
                'status_color' => 'danger'
            ];
        }
    }

    protected function getActions(): array
    {
        return [
            Action::make('authenticate')
                ->label('Authenticate with OneDrive')
                ->icon('heroicon-m-key')
                ->color('primary')
                ->size(ActionSize::Large)
                ->visible(fn () => !($this->authStatus['authenticated'] ?? false))
                ->url(route('onedrive.auth.authenticate'))
                ->openUrlInNewTab(false),

            Action::make('test_connection')
                ->label('Test Connection')
                ->icon('heroicon-m-wifi')
                ->color('info')
                ->visible(fn () => $this->authStatus['authenticated'] ?? false)
                ->action('testConnection'),

            Action::make('refresh_status')
                ->label('Refresh Status')
                ->icon('heroicon-m-arrow-path')
                ->color('gray')
                ->action('refreshStatus'),

            Action::make('revoke_authentication')
                ->label('Revoke Authentication')
                ->icon('heroicon-m-trash')
                ->color('danger')
                ->visible(fn () => $this->authStatus['authenticated'] ?? false)
                ->requiresConfirmation()
                ->modalHeading('Revoke OneDrive Authentication')
                ->modalDescription('This will disconnect OneDrive and delete stored tokens. You will need to re-authenticate to continue using OneDrive backup.')
                ->modalSubmitActionLabel('Yes, Revoke')
                ->action('revokeAuthentication'),
        ];
    }

    public function testConnection(): void
    {
        $user = Auth::user();
        $oneDriveService = app(OneDrivePersonalService::class);
        
        try {
            if ($oneDriveService->testConnection($user)) {
                Notification::make()
                    ->title('Connection Successful')
                    ->body('OneDrive connection is working correctly.')
                    ->success()
                    ->send();
            } else {
                Notification::make()
                    ->title('Connection Failed')
                    ->body('OneDrive connection test failed. Please re-authenticate.')
                    ->danger()
                    ->send();
            }
        } catch (\Exception $e) {
            Notification::make()
                ->title('Connection Error')
                ->body('Error testing connection: ' . $e->getMessage())
                ->danger()
                ->send();
        }
        
        $this->loadAuthenticationStatus();
    }

    public function refreshStatus(): void
    {
        $this->loadAuthenticationStatus();
        
        Notification::make()
            ->title('Status Refreshed')
            ->body('Authentication status has been updated.')
            ->success()
            ->send();
    }

    public function revokeAuthentication(): void
    {
        $user = Auth::user();
        $authService = app(MicrosoftAuthService::class);
        
        try {
            $authService->revokeToken($user);
            
            Log::info('OneDrive authentication revoked', [
                'user_id' => $user->id,
                'user_email' => $user->email
            ]);
            
            Notification::make()
                ->title('Authentication Revoked')
                ->body('OneDrive authentication has been successfully revoked.')
                ->success()
                ->send();
                
        } catch (\Exception $e) {
            Log::error('Failed to revoke OneDrive authentication', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
            
            Notification::make()
                ->title('Revocation Failed')
                ->body('Failed to revoke authentication: ' . $e->getMessage())
                ->danger()
                ->send();
        }
        
        $this->loadAuthenticationStatus();
    }

    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }

    public static function shouldRegisterNavigation(): bool
    {
        return Auth::check() && Auth::user()->hasRole(['super_admin', 'admin']);
    }
}