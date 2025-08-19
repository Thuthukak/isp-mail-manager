<?php

namespace App\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

class MailSystemStatsWidget extends BaseWidget
{
    protected function getStats(): array
    {
        return [
            Stat::make('Total Mails Backed Up', $this->getTotalBackedUpMails())
                ->description('Successfully backed up to OneDrive')
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('success')
                ->chart([7, 2, 10, 3, 15, 4, 17]),
            
            Stat::make('Storage Used', $this->getStorageUsed())
                ->description('Current OneDrive storage usage')
                ->descriptionIcon('heroicon-m-cloud-arrow-up')
                ->color('info')
                ->chart([15, 4, 10, 2, 12, 4, 12]),
            
            Stat::make('Mailboxes Monitored', $this->getMonitoredMailboxes())
                ->description('Active mailboxes under management')
                ->descriptionIcon('heroicon-m-user-group')
                ->color('warning')
                ->chart([2, 10, 3, 15, 4, 17, 7]),
            
            Stat::make('Pending Sync Jobs', $this->getPendingSyncJobs())
                ->description('Jobs in queue waiting to process')
                ->descriptionIcon('heroicon-m-clock')
                ->color($this->getPendingSyncJobs() > 0 ? 'danger' : 'success')
                ->chart([1, 1, 2, 3, 2, 1, 0]),
        ];
    }

    private function getTotalBackedUpMails(): string
    {
        // This would be connected to your MailBackup model
        $count = 0; // Replace with: MailBackup::where('status', 'completed')->count();
        return Number::format($count);
    }

    private function getStorageUsed(): string
    {
        // This would calculate actual OneDrive storage usage
        $bytes = 0; // Replace with actual calculation
        return Number::fileSize($bytes);
    }

    private function getMonitoredMailboxes(): string
    {
        // This would count active mailboxes
        $count = 0; // Replace with actual count
        return Number::format($count);
    }

    private function getPendingSyncJobs(): int
    {
        // This would count pending jobs in queue
        return 0; // Replace with actual queue count
    }
}