<?php

namespace App\Filament\Widgets;

use App\Models\MailBackup;
use Filament\Widgets\ChartWidget;

class BackupTrendsWidget extends ChartWidget
{
    protected static ?string $heading = 'Backup Trends (Last 30 Days)';
    protected int | string | array $columnSpan = 'full';

    protected function getData(): array
    {
        $data = MailBackup::selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->where('created_at', '>=', now()->subDays(30))
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return [
            'datasets' => [
                [
                    'label' => 'Backups Created',
                    'data' => $data->pluck('count')->toArray(),
                    'borderColor' => '#10b981',
                    'backgroundColor' => 'rgba(16, 185, 129, 0.1)',
                    'fill' => true,
                ],
            ],
            'labels' => $data->pluck('date')->map(fn ($date) => 
                \Carbon\Carbon::parse($date)->format('M d')
            )->toArray(),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}