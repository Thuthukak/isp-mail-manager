<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\OneDrivePersonalService;
use App\Services\MicrosoftAuthService;
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
        $this->app->bind(OneDrivePersonalService::class, function ($app) {
        return new OneDrivePersonalService($app->make(MicrosoftAuthService::class));
        });
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
