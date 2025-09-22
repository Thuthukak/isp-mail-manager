<?php

namespace App\Filament\Widgets;

use App\Models\BackupJob;
use App\Models\EmailAccount;
use Filament\Widgets\ChartWidget;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class BackupJobsChartWidget extends ChartWidget
{
    protected static ?string $heading = 'Backup Jobs Overview';
    protected static ?int $sort = 2;
    
    protected int | string | array $columnSpan = 'full';

    protected function getData(): array
    {
        $last30Days = collect(range(29, 0))->map(function ($daysAgo) {
            return Carbon::now()->subDays($daysAgo);
        });

        $jobsData = BackupJob::select(
            DB::raw('DATE(created_at) as date'),
            DB::raw('COUNT(*) as total'),
            DB::raw('SUM(CASE WHEN status = "completed" THEN 1 ELSE 0 END) as completed'),
            DB::raw('SUM(CASE WHEN status = "failed" THEN 1 ELSE 0 END) as failed'),
            DB::raw('SUM(CASE WHEN status = "running" THEN 1 ELSE 0 END) as running')
        )
        ->where('created_at', '>=', Carbon::now()->subDays(30))
        ->groupBy(DB::raw('DATE(created_at)'))
        ->get()
        ->keyBy('date');

        $labels = [];
        $completedData = [];
        $failedData = [];
        $runningData = [];

        foreach ($last30Days as $date) {
            $dateKey = $date->format('Y-m-d');
            $labels[] = $date->format('M j');
            
            $dayData = $jobsData->get($dateKey);
            $completedData[] = $dayData ? (int) $dayData->completed : 0;
            $failedData[] = $dayData ? (int) $dayData->failed : 0;
            $runningData[] = $dayData ? (int) $dayData->running : 0;
        }

        return [
            'datasets' => [
                [
                    'label' => 'Completed',
                    'data' => $completedData,
                    'backgroundColor' => 'rgb(34, 197, 94)',
                    'borderColor' => 'rgb(34, 197, 94)',
                ],
                [
                    'label' => 'Failed',
                    'data' => $failedData,
                    'backgroundColor' => 'rgb(239, 68, 68)',
                    'borderColor' => 'rgb(239, 68, 68)',
                ],
                [
                    'label' => 'Running',
                    'data' => $runningData,
                    'backgroundColor' => 'rgb(59, 130, 246)',
                    'borderColor' => 'rgb(59, 130, 246)',
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend' => [
                    'display' => true,
                ],
            ],
            'scales' => [
                'y' => [
                    'beginAtZero' => true,
                ],
            ],
        ];
    }
}