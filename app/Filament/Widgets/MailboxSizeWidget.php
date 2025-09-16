<?php

namespace App\Filament\Widgets;

use App\Models\MailboxAlert;
use Filament\Widgets\ChartWidget;

class MailboxSizeWidget extends ChartWidget
{
    protected static ?string $heading = 'Mailbox Usage';
    protected static ?int $sort = 2;

    protected function getData(): array
    {
        $alerts = MailboxAlert::latest('alert_date')->limit(10)->get();

        return [
            'datasets' => [
                [
                    'label' => 'Usage %',
                    'data' => $alerts->map(fn ($alert) => 
                        round(($alert->size_mb / $alert->threshold_mb) * 100, 1)
                    )->toArray(),
                    'backgroundColor' => $alerts->map(function ($alert) {
                        $usage = ($alert->size_mb / $alert->threshold_mb) * 100;
                        return match (true) {
                            $usage >= 95 => '#ef4444',
                            $usage >= 80 => '#f59e0b',
                            default => '#10b981',
                        };
                    })->toArray(),
                ],
            ],
            'labels' => $alerts->map(fn ($alert) => 
                substr($alert->mailbox, 0, 15) . (strlen($alert->mailbox) > 15 ? '...' : '')
            )->toArray(),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getOptions(): array
    {
        return [
            'scales' => [
                'y' => [
                    'beginAtZero' => true,
                    'max' => 100,
                ],
            ],
        ];
    }
}