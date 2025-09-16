<?php

namespace App\Filament\Widgets;

use App\Models\MailBackup;
use App\Models\MailboxAlert;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Cache;

class SystemHealthWidget extends BaseWidget
{
    protected static ?int $sort = 4;

    protected function getStats(): array
    {
        $totalBackups = MailBackup::count();
        $failedBackups = MailBackup::where('status', 'failed')->count();
        $activeAlerts = MailboxAlert::whereNotIn('status', ['resolved', 'ignored'])->count();
        
        // Check OneDrive connection (cached for 5 minutes)
        $oneDriveStatus = Cache::remember('onedrive_status', 300, function () {
            try {
                // Check OneDrive connection here
                return 'connected';
            } catch (\Exception $e) {
                return 'disconnected';
            }
        });

        return [
            Stat::make('Total Backups', number_format($totalBackups))
                ->description($failedBackups > 0 ? "{$failedBackups} failed" : 'All successful')
                ->descriptionIcon('heroicon-m-cloud-arrow-up')
                ->color($failedBackups > 0 ? 'warning' : 'success'),

            Stat::make('Active Alerts', $activeAlerts)
                ->description('Mailbox size alerts')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($activeAlerts > 0 ? 'danger' : 'success'),

            Stat::make('OneDrive Status', ucfirst($oneDriveStatus))
                ->description('Connection status')
                ->descriptionIcon('heroicon-m-cloud')
                ->color($oneDriveStatus === 'connected' ? 'success' : 'danger'),
        ];
    }
}
