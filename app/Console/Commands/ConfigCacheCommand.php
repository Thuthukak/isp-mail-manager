<?php

namespace App\Console\Commands;

use App\Services\ConfigurationService;
use Illuminate\Console\Command;

class ConfigCacheCommand extends Command
{
    protected $signature = 'config:cache-db';
    protected $description = 'Clear and refresh database configuration cache';

    public function handle(ConfigurationService $configService): int
    {
        $this->info('Clearing configuration cache...');
        $configService->clearAllCache();
        
        $this->info('Warming up configuration cache...');
        $configService->all(); // This will cache all configurations
        
        $this->info('Database configuration cache refreshed successfully!');
        
        return self::SUCCESS;
    }
}