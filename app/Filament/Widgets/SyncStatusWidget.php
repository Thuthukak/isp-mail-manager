<?php

namespace App\Filament\Widgets;

use App\Models\SyncLog;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class SyncStatusWidget extends BaseWidget
{
    protected function getStats(): array
    {
        $lastSync = SyncLog::where('operation_type', 'sync_new')
            ->latest('started_at')
            ->first();

        $runningOperations = SyncLog::where('status', 'running')->count();
        $failedOperations = SyncLog::where('status', 'failed')
            ->where('started_at', '>=', now()->subDay())
            ->count();

        return [
            Stat::make('Last Sync', $lastSync?->started_at?->diffForHumans() ?? 'Never')
                ->description($lastSync?->status === 'completed' ? 'Completed successfully' : 'Failed or running')
                ->descriptionIcon('heroicon-m-clock')
                ->color($lastSync?->status === 'completed' ? 'success' : 'warning'),

            Stat::make('Running Operations', $runningOperations)
                ->description('Currently processing')
                ->descriptionIcon('heroicon-m-play')
                ->color($runningOperations > 0 ? 'info' : 'success'),

            Stat::make('Failed Operations (24h)', $failedOperations)
                ->description('Requires attention')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($failedOperations > 0 ? 'danger' : 'success'),
        ];
    }
}