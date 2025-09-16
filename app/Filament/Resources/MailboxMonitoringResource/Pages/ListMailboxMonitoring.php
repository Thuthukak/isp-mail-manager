<?php

namespace App\Filament\Resources\MailboxMonitoringResource\Pages;

use App\Filament\Resources\MailboxMonitoringResource;
use App\Models\MailboxAlert;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListMailboxMonitoring extends ListRecords
{
    protected static string $resource = MailboxMonitoringResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('checkMailboxSizes')
                ->label('Check All Mailboxes')
                ->icon('heroicon-o-magnifying-glass')
                ->color('primary')
                ->action(function () {
                    // Dispatch the job to check mailbox sizes
                    \App\Jobs\CheckMailboxSizesJob::dispatch();
                    
                    $this->notify('success', 'Mailbox size check initiated. Results will appear shortly.');
                })
                ->requiresConfirmation()
                ->modalHeading('Check All Mailboxes')
                ->modalDescription('This will check the current size of all mailboxes and create alerts if thresholds are exceeded.')
                ->modalSubmitActionLabel('Start Check'),

            Actions\Action::make('resolveAll')
                ->label('Resolve All Alerts')
                ->icon('heroicon-o-check')
                ->color('success')
                ->action(function () {
                    MailboxAlert::whereIn('status', ['active', 'acknowledged'])->update(['status' => 'resolved']);
                    $this->notify('success', 'All unresolved alerts have been marked as resolved.');
                })
                ->requiresConfirmation()
                ->modalHeading('Resolve All Alerts')
                ->modalDescription('This will mark all unresolved alerts as resolved. Are you sure?')
                ->modalSubmitActionLabel('Resolve All')
                ->visible(fn () => MailboxAlert::whereIn('status', ['active', 'acknowledged'])->exists()),

            Actions\Action::make('exportReport')
                ->label('Export Report')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->url(fn () => route('mailbox-monitoring.export'))
                ->openUrlInNewTab(),
        ];
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All Alerts')
                ->badge(MailboxAlert::count()),

            'unresolved' => Tab::make('Unresolved')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', ['active', 'acknowledged']))
                ->badge(MailboxAlert::whereIn('status', ['active', 'acknowledged'])->count())
                ->badgeColor('danger'),

            'critical' => Tab::make('Critical (95%+)')
                ->modifyQueryUsing(fn (Builder $query) => 
                    $query->whereIn('status', ['active', 'acknowledged'])
                          ->whereRaw('(current_size_bytes / threshold_bytes) * 100 >= 95')
                )
                ->badge(MailboxAlert::whereIn('status', ['active', 'acknowledged'])
                    ->whereRaw('(current_size_bytes / threshold_bytes) * 100 >= 95')
                    ->count())
                ->badgeColor('danger'),

            'warning' => Tab::make('Warning (80-94%)')
                ->modifyQueryUsing(fn (Builder $query) => 
                    $query->whereIn('status', ['active', 'acknowledged'])
                          ->whereRaw('(current_size_bytes / threshold_bytes) * 100 >= 80')
                          ->whereRaw('(current_size_bytes / threshold_bytes) * 100 < 95')
                )
                ->badge(MailboxAlert::whereIn('status', ['active', 'acknowledged'])
                    ->whereRaw('(current_size_bytes / threshold_bytes) * 100 >= 80')
                    ->whereRaw('(current_size_bytes / threshold_bytes) * 100 < 95')
                    ->count())
                ->badgeColor('warning'),

            'resolved' => Tab::make('Resolved')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'resolved'))
                ->badge(MailboxAlert::where('status', 'resolved')->count())
                ->badgeColor('success'),
        ];
    }

    protected function getTableRecordsPerPageSelectOptions(): array
    {
        return [10, 25, 50, 100];
    }

    protected function getTableDefaultSortColumn(): ?string
    {
        return 'alert_date';
    }

    protected function getTableDefaultSortDirection(): ?string
    {
        return 'desc';
    }

    public function getTitle(): string
    {
        return 'Mailbox Monitoring';
    }

    public function getHeading(): string
    {
        return 'Mailbox Size Alerts';
    }

    public function getSubheading(): ?string
    {
        $unresolvedCount = MailboxAlert::whereIn('status', ['active', 'acknowledged'])->count();
        $criticalCount = MailboxAlert::whereIn('status', ['active', 'acknowledged'])
            ->whereRaw('(current_size_bytes / threshold_bytes) * 100 >= 95')
            ->count();

        if ($criticalCount > 0) {
            return "⚠️ {$criticalCount} critical alerts requiring immediate attention";
        }

        if ($unresolvedCount > 0) {
            return "📊 {$unresolvedCount} unresolved alerts";
        }

        return "✅ All mailboxes are within their size thresholds";
    }

    protected function notify(string $type, string $message): void
    {
        match ($type) {
            'success' => \Filament\Notifications\Notification::make()
                ->success()
                ->title($message)
                ->send(),
            'warning' => \Filament\Notifications\Notification::make()
                ->warning()
                ->title($message)
                ->send(),
            'danger' => \Filament\Notifications\Notification::make()
                ->danger()
                ->title($message)
                ->send(),
            default => \Filament\Notifications\Notification::make()
                ->info()
                ->title($message)
                ->send(),
        };
    }
}