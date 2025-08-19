<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\OneDriveService;
use App\Services\MailServerService;
use App\Services\MailBackupService;
use App\Services\MailRestorationService;
use App\Services\MailboxMonitorService;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(OneDriveService::class);
        $this->app->singleton(MailServerService::class);
        $this->app->singleton(MailBackupService::class);
        $this->app->singleton(MailRestorationService::class);
        $this->app->singleton(MailboxMonitorService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
