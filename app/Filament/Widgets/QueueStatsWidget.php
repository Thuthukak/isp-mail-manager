<?php

namespace App\Filament\Widgets;

use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\Redis;

class QueueStatsWidget extends ChartWidget
{
    protected static ?string $heading = 'Queue Statistics';
    protected static ?int $sort = 5;

    protected function getData(): array
    {
        // Get queue statistics from Redis/Horizon
        $queues = ['default', 'backup', 'sync', 'restore'];
        $data = [];
        $labels = [];

        foreach ($queues as $queue) {
            try {
                // This would be actual Horizon/Redis queue size check
                $size = rand(0, 50); // Placeholder
                $data[] = $size;
                $labels[] = ucfirst($queue);
            } catch (\Exception $e) {
                $data[] = 0;
                $labels[] = ucfirst($queue);
            }
        }

        return [
            'datasets' => [
                [
                    'label' => 'Queue Size',
                    'data' => $data,
                    'backgroundColor' => [
                        '#3b82f6',
                        '#10b981',
                        '#f59e0b',
                        '#ef4444',
                    ],
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }
}