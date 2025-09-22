<?php

namespace App\Filament\Widgets;

use App\Models\BackupJob;
use App\Models\EmailAccount;
use App\Models\MailBackup;
use App\Services\MailBackupService;
use App\Services\OneDrivePersonalService;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;
use Carbon\Carbon;

class SystemHealthWidget extends BaseWidget
{
    protected static ?int $sort = 6;
    
    protected function getStats(): array
    {
        return [
            Stat::make('System Status', $this->getSystemStatus())
                ->description($this->getSystemStatusDescription())
                ->descriptionIcon($this->getSystemStatusIcon())
                ->color($this->getSystemStatusColor()),

            Stat::make('Success Rate', $this->getSuccessRate())
                ->description('Last 7 days backup success rate')
                ->descriptionIcon('heroicon-m-chart-bar')
                ->color($this->getSuccessRateColor())
                ->chart($this->getSuccessRateChart()),

            Stat::make('Average Job Time', $this->getAverageJobTime())
                ->description('Average backup completion time')
                ->descriptionIcon('heroicon-m-clock')
                ->color('info')
                ->chart($this->getJobTimeChart()),

            Stat::make('Data Integrity', $this->getDataIntegrityStatus())
                ->description($this->getDataIntegrityDescription())
                ->descriptionIcon('heroicon-m-shield-check')
                ->color($this->getDataIntegrityColor())
                ->chart($this->getDataIntegrityChart()),
        ];
    }

    private function getSystemStatus(): string
    {
        $runningJobs = BackupJob::where('status', 'running')->count();
        $recentFailures = BackupJob::where('created_at', '>=', Carbon::now()->subHours(2))
            ->where('status', 'failed')
            ->count();
        
        if ($runningJobs > 0) {
            return 'Active';
        }
        
        if ($recentFailures > 0) {
            return 'Issues Detected';
        }
        
        return 'Healthy';
    }

    private function getSystemStatusDescription(): string
    {
        $runningJobs = BackupJob::where('status', 'running')->count();
        $recentFailures = BackupJob::where('created_at', '>=', Carbon::now()->subHours(2))
            ->where('status', 'failed')
            ->count();
        
        if ($runningJobs > 0) {
            return "{$runningJobs} backup jobs currently running";
        }
        
        if ($recentFailures > 0) {
            return "{$recentFailures} failures in last 2 hours";
        }
        
        $lastJob = BackupJob::latest()->first();
        if ($lastJob) {
            return "Last activity: " . $lastJob->created_at->diffForHumans();
        }
        
        return "System operational";
    }

    private function getSystemStatusIcon(): string
    {
        $runningJobs = BackupJob::where('status', 'running')->count();
        $recentFailures = BackupJob::where('created_at', '>=', Carbon::now()->subHours(2))
            ->where('status', 'failed')
            ->count();
        
        if ($runningJobs > 0) {
            return 'heroicon-m-arrow-path';
        }
        
        if ($recentFailures > 0) {
            return 'heroicon-m-exclamation-triangle';
        }
        
        return 'heroicon-m-check-circle';
    }

    private function getSystemStatusColor(): string
    {
        $runningJobs = BackupJob::where('status', 'running')->count();
        $recentFailures = BackupJob::where('created_at', '>=', Carbon::now()->subHours(2))
            ->where('status', 'failed')
            ->count();
        
        if ($runningJobs > 0) {
            return 'info';
        }
        
        if ($recentFailures > 0) {
            return 'warning';
        }
        
        return 'success';
    }

    private function getSuccessRate(): string
    {
        $totalJobs = BackupJob::where('created_at', '>=', Carbon::now()->subDays(7))->count();
        $successfulJobs = BackupJob::where('created_at', '>=', Carbon::now()->subDays(7))
            ->where('status', 'completed')
            ->count();
        
        if ($totalJobs === 0) {
            return '100%';
        }
        
        $rate = ($successfulJobs / $totalJobs) * 100;
        return round($rate, 1) . '%';
    }

    private function getSuccessRateColor(): string
    {
        $totalJobs = BackupJob::where('created_at', '>=', Carbon::now()->subDays(7))->count();
        $successfulJobs = BackupJob::where('created_at', '>=', Carbon::now()->subDays(7))
            ->where('status', 'completed')
            ->count();
        
        if ($totalJobs === 0) {
            return 'success';
        }
        
        $rate = ($successfulJobs / $totalJobs) * 100;
        
        if ($rate >= 95) {
            return 'success';
        } elseif ($rate >= 80) {
            return 'warning';
        } else {
            return 'danger';
        }
    }

    private function getAverageJobTime(): string
    {
        $avgMinutes = BackupJob::where('status', 'completed')
            ->where('created_at', '>=', Carbon::now()->subDays(7))
            ->whereNotNull('started_at')
            ->whereNotNull('completed_at')
            ->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, started_at, completed_at)) as avg_duration')
            ->value('avg_duration');
        
        if (!$avgMinutes || $avgMinutes == 0) {
            return 'N/A';
        }
        
        $avgMinutes = round($avgMinutes);
        
        if ($avgMinutes < 60) {
            return "{$avgMinutes}m";
        }
        
        $hours = floor($avgMinutes / 60);
        $minutes = $avgMinutes % 60;
        return "{$hours}h {$minutes}m";
    }

    private function getDataIntegrityStatus(): string
    {
        $totalBackups = MailBackup::where('status', 'completed')->count();
        $corruptBackups = MailBackup::where('status', 'completed')
            ->where('file_size', '<=', 100) // Suspiciously small backups
            ->count();
        
        if ($totalBackups === 0) {
            return 'No Data';
        }
        
        $integrityRate = (($totalBackups - $corruptBackups) / $totalBackups) * 100;
        return round($integrityRate, 1) . '%';
    }

    private function getDataIntegrityDescription(): string
    {
        $totalBackups = MailBackup::where('status', 'completed')->count();
        $corruptBackups = MailBackup::where('status', 'completed')
            ->where('file_size', '<=', 100)
            ->count();
        
        if ($totalBackups === 0) {
            return 'No backup data available';
        }
        
        if ($corruptBackups === 0) {
            return "All {$totalBackups} backups appear healthy";
        }
        
        return "{$corruptBackups} of {$totalBackups} may have integrity issues";
    }

    private function getDataIntegrityColor(): string
    {
        $totalBackups = MailBackup::where('status', 'completed')->count();
        $corruptBackups = MailBackup::where('status', 'completed')
            ->where('file_size', '<=', 100)
            ->count();
        
        if ($totalBackups === 0) {
            return 'gray';
        }
        
        $integrityRate = (($totalBackups - $corruptBackups) / $totalBackups) * 100;
        
        if ($integrityRate >= 98) {
            return 'success';
        } elseif ($integrityRate >= 90) {
            return 'warning';
        } else {
            return 'danger';
        }
    }

    private function getSuccessRateChart(): array
    {
        $data = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = Carbon::now()->subDays($i);
            $totalJobs = BackupJob::whereDate('created_at', $date)->count();
            $successfulJobs = BackupJob::whereDate('created_at', $date)
                ->where('status', 'completed')
                ->count();
            
            $rate = $totalJobs > 0 ? ($successfulJobs / $totalJobs) * 100 : 100;
            $data[] = round($rate, 1);
        }
        return $data;
    }

    private function getJobTimeChart(): array
    {
        $data = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = Carbon::now()->subDays($i);
            $avgMinutes = BackupJob::whereDate('created_at', $date)
                ->where('status', 'completed')
                ->whereNotNull('started_at')
                ->whereNotNull('completed_at')
                ->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, started_at, completed_at)) as avg_duration')
                ->value('avg_duration');
            
            $data[] = $avgMinutes ? round($avgMinutes) : 0;
        }
        return $data;
    }

    private function getDataIntegrityChart(): array
    {
        $data = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = Carbon::now()->subDays($i);
            $totalBackups = MailBackup::whereDate('created_at', $date)
                ->where('status', 'completed')
                ->count();
            $suspiciousBackups = MailBackup::whereDate('created_at', $date)
                ->where('status', 'completed')
                ->where('file_size', '<=', 100)
                ->count();
            
            if ($totalBackups === 0) {
                $data[] = 100;
            } else {
                $integrityRate = (($totalBackups - $suspiciousBackups) / $totalBackups) * 100;
                $data[] = round($integrityRate, 1);
            }
        }
        return $data;
    }
}