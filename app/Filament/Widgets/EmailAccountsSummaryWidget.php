<?php

namespace App\Filament\Widgets;

use App\Models\EmailAccount;
use App\Models\BackupJob;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class EmailAccountsSummaryWidget extends BaseWidget
{
    protected static ?int $sort = 5;

    protected function getStats(): array
    {
        return [
            Stat::make('Total Accounts', $this->getTotalAccounts())
                ->description($this->getAccountsDescription())
                ->descriptionIcon('heroicon-m-user-group')
                ->color('info')
                ->chart($this->getAccountsChart()),

            Stat::make('Backed Up Today', $this->getBackedUpToday())
                ->description('Accounts with completed backups today')
                ->descriptionIcon('heroicon-m-check-circle')
                ->color('success')
                ->chart($this->getTodayBackupsChart()),

            Stat::make('Needs Attention', $this->getNeedsAttention())
                ->description($this->getNeedsAttentionDescription())
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($this->getNeedsAttentionColor())
                ->chart($this->getNeedsAttentionChart()),

            Stat::make('Connection Issues', $this->getConnectionIssues())
                ->description('Accounts with recent connection failures')
                ->descriptionIcon('heroicon-m-wifi')
                ->color($this->getConnectionIssuesColor())
                ->chart($this->getConnectionIssuesChart()),
        ];
    }

    private function getTotalAccounts(): string
    {
        $active = EmailAccount::where('active', true)->count();
        $inactive = EmailAccount::where('active', false)->count();
        return Number::format($active) . ($inactive > 0 ? " (+{$inactive} inactive)" : "");
    }

    private function getAccountsDescription(): string
    {
        $departments = EmailAccount::where('active', true)
            ->whereNotNull('department')
            ->distinct('department')
            ->count();
        
        return "Across {$departments} departments";
    }

    private function getBackedUpToday(): string
    {
        $count = BackupJob::whereDate('created_at', Carbon::today())
            ->where('status', 'completed')
            ->distinct('email_account_id')
            ->count();
            
        return Number::format($count);
    }

    private function getNeedsAttention(): string
    {
        $count = EmailAccount::where('active', true)
            ->where(function ($query) {
                $query->whereNull('last_backup')
                    ->orWhere('last_backup', '<', Carbon::now()->subDays(3));
            })
            ->count();
            
        return Number::format($count);
    }

    private function getNeedsAttentionDescription(): string
    {
        $neverBacked = EmailAccount::where('active', true)
            ->whereNull('last_backup')
            ->count();
            
        $stale = EmailAccount::where('active', true)
            ->where('last_backup', '<', Carbon::now()->subDays(3))
            ->whereNotNull('last_backup')
            ->count();
            
        if ($neverBacked > 0 && $stale > 0) {
            return "{$neverBacked} never backed up, {$stale} stale backups";
        } elseif ($neverBacked > 0) {
            return "{$neverBacked} accounts never backed up";
        } elseif ($stale > 0) {
            return "{$stale} accounts with stale backups";
        }
        
        return "All accounts recently backed up";
    }

    private function getNeedsAttentionColor(): string
    {
        $count = EmailAccount::where('active', true)
            ->where(function ($query) {
                $query->whereNull('last_backup')
                    ->orWhere('last_backup', '<', Carbon::now()->subDays(3));
            })
            ->count();
            
        return $count > 0 ? 'warning' : 'success';
    }

    private function getConnectionIssues(): string
    {
        $count = BackupJob::where('created_at', '>=', Carbon::now()->subDays(7))
            ->where('status', 'failed')
            ->where('error_message', 'like', '%connection%')
            ->distinct('email_account_id')
            ->count();
            
        return Number::format($count);
    }

    private function getConnectionIssuesColor(): string
    {
        $count = BackupJob::where('created_at', '>=', Carbon::now()->subDays(7))
            ->where('status', 'failed')
            ->where('error_message', 'like', '%connection%')
            ->distinct('email_account_id')
            ->count();
            
        return $count > 0 ? 'danger' : 'success';
    }

    private function getAccountsChart(): array
    {
        $data = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = Carbon::now()->subDays($i)->startOfDay();
            $count = EmailAccount::where('created_at', '<=', $date)
                ->where('active', true)
                ->count();
            $data[] = $count;
        }
        return $data;
    }

    private function getTodayBackupsChart(): array
    {
        $data = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = Carbon::now()->subDays($i);
            $count = BackupJob::whereDate('created_at', $date)
                ->where('status', 'completed')
                ->distinct('email_account_id')
                ->count();
            $data[] = $count;
        }
        return $data;
    }

    private function getNeedsAttentionChart(): array
    {
        $data = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = Carbon::now()->subDays($i);
            $count = EmailAccount::where('active', true)
                ->where(function ($query) use ($date) {
                    $query->whereNull('last_backup')
                        ->orWhere('last_backup', '<', $date->copy()->subDays(3));
                })
                ->count();
            $data[] = $count;
        }
        return $data;
    }

    private function getConnectionIssuesChart(): array
    {
        $data = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = Carbon::now()->subDays($i);
            $count = BackupJob::whereDate('created_at', $date)
                ->where('status', 'failed')
                ->where('error_message', 'like', '%connection%')
                ->distinct('email_account_id')
                ->count();
            $data[] = $count;
        }
        return $data;
    }    
}