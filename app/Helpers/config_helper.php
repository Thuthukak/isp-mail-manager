<?php

if (!function_exists('app_config')) {
    /**
     * Get configuration value from database
     */
    function app_config(string $key, mixed $default = null): mixed
    {
        return app(\App\Services\ConfigurationService::class)->get($key, $default);
    }
}

if (!function_exists('app_config_group')) {
    /**
     * Get all configurations for a group
     */
    function app_config_group(string $groupName): \Illuminate\Support\Collection
    {
        return app(\App\Services\ConfigurationService::class)->getGroup($groupName);
    }
}