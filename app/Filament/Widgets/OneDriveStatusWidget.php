<?php

namespace App\Filament\Widgets;

use App\Services\MicrosoftAuthService;
use App\Services\OneDrivePersonalService;
use App\Services\MailBackupService;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class OneDriveStatusWidget extends BaseWidget
{
    protected static ?int $sort = 3;
    
    protected function getStats(): array
    {
        $stats = [];
        
        try {
            $mailBackupService = app(MailBackupService::class);
            $authService = app(MicrosoftAuthService::class);
            $oneDriveService = app(OneDrivePersonalService::class);
            
            // Get the OneDrive user (using same logic as MailBackupService)
            $oneDriveUser = $this->getOneDriveUser();
            
            if (!$oneDriveUser) {
                $stats[] = Stat::make('OneDrive Status', 'Not Configured')
                    ->description('No user found for OneDrive operations')
                    ->descriptionIcon('heroicon-m-exclamation-triangle')
                    ->color('danger');
                    
                return $stats;
            }
            
            // Check authentication status
            $isAuthenticated = $authService->isAuthenticated($oneDriveUser);
            
            if (!$isAuthenticated) {
                $stats[] = Stat::make('OneDrive Status', 'Not Authenticated')
                    ->description('OneDrive authentication required')
                    ->descriptionIcon('heroicon-m-key')
                    ->color('warning');
                    
                $stats[] = Stat::make('OneDrive User', $oneDriveUser->email)
                    ->description('User account for OneDrive operations')
                    ->descriptionIcon('heroicon-m-user')
                    ->color('info');
                    
                return $stats;
            }
            
            // Test connection
            $connectionWorking = $oneDriveService->testConnection($oneDriveUser);
            
            $stats[] = Stat::make('OneDrive Connection', $connectionWorking ? 'Connected' : 'Failed')
                ->description($connectionWorking ? 'OneDrive is accessible' : 'Connection test failed')
                ->descriptionIcon($connectionWorking ? 'heroicon-m-check-circle' : 'heroicon-m-x-circle')
                ->color($connectionWorking ? 'success' : 'danger');
            
            if ($connectionWorking) {
                // Get storage usage
                $storageUsage = $oneDriveService->getStorageUsage($oneDriveUser);
                
                $stats[] = Stat::make('OneDrive Storage', $this->formatStorageUsage($storageUsage))
                    ->description($this->getStorageDescription($storageUsage))
                    ->descriptionIcon('heroicon-m-cloud')
                    ->color($this->getStorageColor($storageUsage))
                    ->chart($this->getStorageChart($storageUsage));
                
                // Get user info
                $stats[] = Stat::make('OneDrive User', $oneDriveUser->email)
                    ->description('Active user for backup operations')
                    ->descriptionIcon('heroicon-m-user')
                    ->color('info');
                
                // Get token expiry info
                $tokenInfo = $authService->getTokenInfo($oneDriveUser);
                if ($tokenInfo) {
                    $expiryStatus = $this->getTokenExpiryStatus($tokenInfo);
                    $stats[] = Stat::make('Token Status', $expiryStatus['label'])
                        ->description($expiryStatus['description'])
                        ->descriptionIcon($expiryStatus['icon'])
                        ->color($expiryStatus['color']);
                }
            }
            
        } catch (\Exception $e) {
            Log::error('Error in OneDriveStatusWidget', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            $stats[] = Stat::make('OneDrive Status', 'Error')
                ->description('Error checking OneDrive status')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color('danger');
        }
        
        return $stats;
    }
    
    private function getOneDriveUser(): ?User
    {
        // Use the same logic as MailBackupService to get OneDrive user
        $currentUser = Auth::user();
        if ($currentUser && $this->userCanPerformBackups($currentUser)) {
            return $currentUser;
        }

        $adminUser = User::whereHas('roles', function($q) {
            $q->where('name', 'super_admin')->orWhere('name', 'admin');
        })->first();

        if ($adminUser) {
            return $adminUser;
        }

        return User::first();
    }
    
    private function userCanPerformBackups(User $user): bool
    {
        return $user->hasRole(['super_admin', 'admin']);
    }
    
    private function formatStorageUsage(array $storageUsage): string
    {
        $usedBytes = $storageUsage['used_bytes'] ?? 0;
        $totalBytes = $storageUsage['total_bytes'] ?? 0;
        
        if ($totalBytes > 0) {
            $percentage = round(($usedBytes / $totalBytes) * 100, 1);
            return "{$percentage}%";
        }
        
        return Number::fileSize($usedBytes);
    }
    
    private function getStorageDescription(array $storageUsage): string
    {
        $usedBytes = $storageUsage['used_bytes'] ?? 0;
        $totalBytes = $storageUsage['total_bytes'] ?? 0;
        $remainingBytes = $storageUsage['remaining_bytes'] ?? 0;
        
        $used = Number::fileSize($usedBytes);
        $total = Number::fileSize($totalBytes);
        $remaining = Number::fileSize($remainingBytes);
        
        return "{$used} used of {$total} ({$remaining} free)";
    }
    
    private function getStorageColor(array $storageUsage): string
    {
        $usedBytes = $storageUsage['used_bytes'] ?? 0;
        $totalBytes = $storageUsage['total_bytes'] ?? 0;
        
        if ($totalBytes > 0) {
            $percentage = ($usedBytes / $totalBytes) * 100;
            
            if ($percentage > 90) {
                return 'danger';
            } elseif ($percentage > 75) {
                return 'warning';
            }
        }
        
        return 'success';
    }
    
    private function getStorageChart(array $storageUsage): array
    {
        $usedBytes = $storageUsage['used_bytes'] ?? 0;
        $totalBytes = $storageUsage['total_bytes'] ?? 0;
        
        if ($totalBytes > 0) {
            $percentage = ($usedBytes / $totalBytes) * 100;
            return [0, 20, 40, 60, 80, $percentage, $percentage];
        }
        
        return [0, 0, 0, 0, 0, 0, 0];
    }
    
    private function getTokenExpiryStatus(array $tokenInfo): array
    {
        if ($tokenInfo['is_expired']) {
            return [
                'label' => 'Token Expired',
                'description' => 'Authentication token has expired',
                'icon' => 'heroicon-m-clock',
                'color' => 'danger'
            ];
        }
        
        if ($tokenInfo['expires_soon']) {
            $expiresAt = $tokenInfo['expires_at'] ?? 'soon';
            return [
                'label' => 'Expires Soon',
                'description' => "Token expires at {$expiresAt}",
                'icon' => 'heroicon-m-clock',
                'color' => 'warning'
            ];
        }
        
        $expiresAt = $tokenInfo['expires_at'] ?? 'unknown';
        return [
            'label' => 'Token Valid',
            'description' => "Expires: {$expiresAt}",
            'icon' => 'heroicon-m-check-circle',
            'color' => 'success'
        ];
    }
}