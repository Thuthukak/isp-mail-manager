<?php

namespace App\Filament\Widgets;

use App\Models\EmailAccount;
use App\Models\BackupJob;
use App\Models\MailBackup;
use App\Services\MailBackupService;
use App\Services\OneDrivePersonalService;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class MailSystemStatsWidget extends BaseWidget
{
    protected static ?int $sort = 1;
    
    protected function getStats(): array
    {
        return [
            Stat::make('Total Emails Backed Up', $this->getTotalBackedUpMails())
                ->description('Successfully backed up to OneDrive')
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('success')
                ->chart($this->getEmailBackupChart()),
                
            Stat::make('Storage Used', $this->getStorageUsed())
                ->description('Current backup storage usage')
                ->descriptionIcon('heroicon-m-cloud-arrow-up')
                ->color('info')
                ->chart($this->getStorageChart()),
                
            Stat::make('Active Email Accounts', $this->getActiveAccounts())
                ->description('Accounts being monitored')
                ->descriptionIcon('heroicon-m-user-group')
                ->color('warning')
                ->chart($this->getAccountsChart()),
                
            Stat::make('Backup Jobs Status', $this->getRecentJobsStatus())
                ->description($this->getJobsStatusDescription())
                ->descriptionIcon($this->getJobsStatusIcon())
                ->color($this->getJobsStatusColor())
                ->chart($this->getJobsChart()),
        ];
    }

    private function getTotalBackedUpMails(): string
    {
        $count = MailBackup::where('status', 'completed')->count();
        return Number::format($count);
    }

    private function getStorageUsed(): string
    {
        $totalBytes = MailBackup::where('status', 'completed')->sum('file_size');
        return Number::fileSize($totalBytes);
    }

    private function getActiveAccounts(): string
    {
        $count = EmailAccount::where('active', true)->count();
        return Number::format($count);
    }

    private function getRecentJobsStatus(): string
    {
        $recentJobs = BackupJob::where('created_at', '>=', Carbon::now()->subDays(7));
        $completed = $recentJobs->clone()->where('status', 'completed')->count();
        $total = $recentJobs->count();
        
        if ($total === 0) {
            return 'No jobs';
        }
        
        $percentage = round(($completed / $total) * 100, 1);
        return "{$percentage}% success";
    }

    private function getJobsStatusDescription(): string
    {
        $running = BackupJob::where('status', 'running')->count();
        $failed = BackupJob::where('status', 'failed')
            ->where('created_at', '>=', Carbon::now()->subDays(1))
            ->count();
        
        if ($running > 0) {
            return "{$running} jobs currently running";
        }
        
        if ($failed > 0) {
            return "{$failed} failed jobs in last 24h";
        }
        
        return 'All recent jobs completed';
    }

    private function getJobsStatusIcon(): string
    {
        $running = BackupJob::where('status', 'running')->count();
        $failed = BackupJob::where('status', 'failed')
            ->where('created_at', '>=', Carbon::now()->subDays(1))
            ->count();
        
        if ($running > 0) {
            return 'heroicon-m-arrow-path';
        }
        
        if ($failed > 0) {
            return 'heroicon-m-exclamation-triangle';
        }
        
        return 'heroicon-m-check-circle';
    }

    private function getJobsStatusColor(): string
    {
        $running = BackupJob::where('status', 'running')->count();
        $failed = BackupJob::where('status', 'failed')
            ->where('created_at', '>=', Carbon::now()->subDays(1))
            ->count();
        
        if ($running > 0) {
            return 'info';
        }
        
        if ($failed > 0) {
            return 'danger';
        }
        
        return 'success';
    }

    private function getEmailBackupChart(): array
    {
        $data = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = Carbon::now()->subDays($i)->startOfDay();
            $count = MailBackup::whereDate('created_at', $date)
                ->where('status', 'completed')
                ->count();
            $data[] = $count;
        }
        return $data;
    }

    private function getStorageChart(): array
    {
        $data = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = Carbon::now()->subDays($i)->startOfDay();
            $bytes = MailBackup::whereDate('created_at', '<=', $date)
                ->where('status', 'completed')
                ->sum('file_size');
            $mb = $bytes / (1024 * 1024); // Convert to MB for chart
            $data[] = round($mb, 2);
        }
        return $data;
    }

    private function getAccountsChart(): array
    {
        $data = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = Carbon::now()->subDays($i)->startOfDay();
            $count = EmailAccount::where('created_at', '<=', $date)
                ->where('active', true)
                ->count();
            $data[] = $count;
        }
        return $data;
    }

    private function getJobsChart(): array
    {
        $data = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = Carbon::now()->subDays($i);
            $completed = BackupJob::whereDate('created_at', $date)
                ->where('status', 'completed')
                ->count();
            $total = BackupJob::whereDate('created_at', $date)->count();
            
            $percentage = $total > 0 ? ($completed / $total) * 100 : 100;
            $data[] = round($percentage, 1);
        }
        return $data;
    }
}