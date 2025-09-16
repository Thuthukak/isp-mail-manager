<?php

namespace App\Filament\Widgets;

use App\Models\MailBackup;
use Filament\Widgets\ChartWidget;

class StorageUsageWidget extends ChartWidget
{
    protected static ?string $heading = 'Storage Usage by Month';

    protected function getData(): array
    {
        $data = MailBackup::selectRaw('YEAR(created_at) as year, MONTH(created_at) as month, SUM(file_size) as total_size')
            ->where('created_at', '>=', now()->subMonths(12))
            ->groupBy('year', 'month')
            ->orderBy('year')
            ->orderBy('month')
            ->get();

        return [
            'datasets' => [
                [
                    'label' => 'Storage (MB)',
                    'data' => $data->map(fn ($item) => round($item->total_size / 1024 / 1024, 2))->toArray(),
                    'backgroundColor' => '#3b82f6',
                ],
            ],
            'labels' => $data->map(fn ($item) => 
                \Carbon\Carbon::createFromDate($item->year, $item->month, 1)->format('M Y')
            )->toArray(),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}