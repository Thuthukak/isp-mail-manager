<?php

namespace App\Filament\Pages;

use App\Models\MailBackup;
use App\Models\SyncLog;
use App\Models\MailboxAlert;
use Filament\Pages\Page;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\StatsOverviewWidget;

class Reports extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';
    protected static string $view = 'filament.pages.reports';
    protected static ?string $navigationGroup = 'Reports';
    protected static ?int $navigationSort = 1;

    protected function getHeaderWidgets(): array
    {
        return [
            ReportsStatsWidget::class,
            BackupTrendsWidget::class,
            StorageUsageWidget::class,
        ];
    }
}