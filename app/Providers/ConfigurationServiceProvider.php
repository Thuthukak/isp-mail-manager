<?php
// app/Providers/ConfigurationServiceProvider.php

namespace App\Providers;

use App\Services\ConfigurationService;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Config;

class ConfigurationServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ConfigurationService::class, function ($app) {
            return new ConfigurationService();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Load helper functions
        if (file_exists($file = app_path('Helpers/config_helper.php'))) {
            require $file;
        }

        // Override configuration with database values after app is booted
        $this->app->booted(function () {
            if ($this->app->runningInConsole() && !$this->shouldLoadDatabaseConfig()) {
                return;
            }

            try {
                $configService = $this->app->make(ConfigurationService::class);
                $this->loadDatabaseConfigurations($configService);
            } catch (\Exception $e) {
                // Silently fail during migrations or when database is not available
            }
        });
    }

    /**
     * Determine if database configurations should be loaded
     */
    protected function shouldLoadDatabaseConfig(): bool
    {
        // Don't load during migrations
        if ($this->app->runningInConsole()) {
            $command = $_SERVER['argv'][1] ?? '';
            if (str_contains($command, 'migrate') || str_contains($command, 'install')) {
                return false;
            }
        }

        return true;
    }

    /**
     * Load database configurations and override config values
     */
    protected function loadDatabaseConfigurations(ConfigurationService $configService): void
    {
        // Get OneDrive configurations
        $oneDriveConfigs = $configService->getGroup('onedrive');
        if ($oneDriveConfigs->isNotEmpty()) {
            $this->setConfigGroup('services.onedrive', $oneDriveConfigs->toArray());
        }

        // Get Application configurations
        $appConfigs = $configService->getGroup('application');
        foreach ($appConfigs as $key => $value) {
            $this->setAppConfig($key, $value);
        }

        // Get Email configurations
        $emailConfigs = $configService->getGroup('email');
        foreach ($emailConfigs as $key => $value) {
            $this->setMailConfig($key, $value);
        }
    }

    /**
     * Set configuration group values
     */
    protected function setConfigGroup(string $configKey, array $values): void
    {
        foreach ($values as $key => $value) {
            $mappedKey = $this->mapConfigKey($key);
            if ($mappedKey) {
                Config::set($configKey . '.' . $mappedKey, $value);
            }
        }
    }

    /**
     * Set application configuration
     */
    protected function setAppConfig(string $key, mixed $value): void
    {
        switch ($key) {
            case 'APP_NAME':
                Config::set('app.name', $value);
                break;
            case 'APP_ENV':
                Config::set('app.env', $value);
                break;
            case 'APP_DEBUG':
                Config::set('app.debug', $value);
                break;
            case 'APP_URL':
                Config::set('app.url', $value);
                break;
            case 'APP_LOCALE':
                Config::set('app.locale', $value);
                break;
        }
    }

    /**
     * Set mail configuration
     */
    protected function setMailConfig(string $key, mixed $value): void
    {
        switch ($key) {
            case 'MAIL_MAILER':
                Config::set('mail.default', $value);
                break;
            case 'MAIL_HOST':
                Config::set('mail.mailers.smtp.host', $value);
                break;
            case 'MAIL_PORT':
                Config::set('mail.mailers.smtp.port', (int) $value);
                break;
            case 'MAIL_USERNAME':
                Config::set('mail.mailers.smtp.username', $value);
                break;
            case 'MAIL_PASSWORD':
                Config::set('mail.mailers.smtp.password', $value);
                break;
            case 'MAIL_ENCRYPTION':
                Config::set('mail.mailers.smtp.encryption', $value);
                break;
            case 'MAIL_FROM_ADDRESS':
                Config::set('mail.from.address', $value);
                break;
            case 'MAIL_FROM_NAME':
                Config::set('mail.from.name', $value);
                break;
        }
    }

    /**
     * Map environment variable names to config keys
     */
    protected function mapConfigKey(string $envKey): ?string
    {
        $mapping = [
            'MICROSOFT_GRAPH_CLIENT_ID' => 'client_id',
            'MICROSOFT_GRAPH_CLIENT_SECRET' => 'client_secret',
            'MICROSOFT_GRAPH_TENANT_ID' => 'tenant_id',
            'MICROSOFT_GRAPH_REDIRECT_URI' => 'redirect_uri',
            'ONEDRIVE_USER_ID' => 'user_id',
            'ONEDRIVE_ROOT_FOLDER' => 'root_folder',
            'ONEDRIVE_UPLOAD_CHUNK_SIZE' => 'upload_chunk_size',
            'ONEDRIVE_MAX_RETRY_ATTEMPTS' => 'max_retry_attempts',
            'ONEDRIVE_RETRY_DELAY' => 'retry_delay',
            'MICROSOFT_SCOPES' => 'scopes',
            'ONEDRIVE_TYPE' => 'drive_type',
            'ONEDRIVE_DRIVE_ID' => 'drive_id',
        ];

        return $mapping[$envKey] ?? null;
    }
}
