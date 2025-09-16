<?php

namespace App\Filament\Widgets;

use App\Models\MailBackup;
use App\Models\SyncLog;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ReportsStatsWidget extends BaseWidget
{
    protected function getStats(): array
    {
        $totalBackups = MailBackup::count();
        $totalSize = MailBackup::sum('file_size');
        $avgOperationTime = SyncLog::whereNotNull('completed_at')
            ->selectRaw('AVG(TIMESTAMPDIFF(SECOND, started_at, completed_at)) as avg_time')
            ->value('avg_time');

        return [
            Stat::make('Total Backups', number_format($totalBackups))
                ->description('All time backups')
                ->descriptionIcon('heroicon-m-cloud-arrow-up')
                ->color('primary'),

            Stat::make('Total Storage', $this->formatBytes($totalSize))
                ->description('Backup storage used')
                ->descriptionIcon('heroicon-m-server-stack')
                ->color('success'),

            Stat::make('Avg Operation Time', gmdate('H:i:s', $avgOperationTime ?? 0))
                ->description('Average sync duration')
                ->descriptionIcon('heroicon-m-clock')
                ->color('info'),
        ];
    }

    private function formatBytes(?int $bytes): string
    {
        if (!$bytes) return '0 B';
        
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= (1 << (10 * $pow));
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }
}