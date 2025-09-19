<?php
// app/Services/ConfigurationService.php

namespace App\Services;

use App\Models\Configuration;
use App\Models\ConfigurationGroup;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Collection;

class ConfigurationService
{
    protected const CACHE_TTL = 3600; // 1 hour
    protected const CACHE_PREFIX = 'app_config:';

    /**
     * Get configuration value by key
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $cacheKey = self::CACHE_PREFIX . $key;
        
        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($key, $default) {
            $config = Configuration::where('key', $key)->first();
            
            if (!$config || is_null($config->value)) {
                return $default;
            }

            return $this->castValue($config->value, $config->type);
        });
    }

    /**
     * Set configuration value
     */
    public function set(string $key, mixed $value): bool
    {
        $config = Configuration::where('key', $key)->first();
        
        if (!$config) {
            return false;
        }

        // Convert value based on type
        $processedValue = $this->processValueForStorage($value, $config->type);
        
        $config->value = $processedValue;
        $result = $config->save();

        // Clear cache
        $this->clearCache($key);

        return $result;
    }

    /**
     * Get all configurations for a group
     */
    public function getGroup(string $groupName): Collection
    {
        $cacheKey = self::CACHE_PREFIX . 'group:' . $groupName;
        
        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($groupName) {
            $group = ConfigurationGroup::where('name', $groupName)->first();
            
            if (!$group) {
                return collect();
            }

            return $group->configurations->mapWithKeys(function ($config) {
                return [$config->key => $this->castValue($config->value, $config->type)];
            });
        });
    }

    /**
     * Set multiple configurations for a group
     */
    public function setGroup(string $groupName, array $values): bool
    {
        $group = ConfigurationGroup::where('name', $groupName)->first();
        
        if (!$group) {
            return false;
        }

        $configurations = $group->configurations->keyBy('key');
        $success = true;

        foreach ($values as $key => $value) {
            if (isset($configurations[$key])) {
                $config = $configurations[$key];
                $processedValue = $this->processValueForStorage($value, $config->type);
                $config->value = $processedValue;
                
                if (!$config->save()) {
                    $success = false;
                }
                
                $this->clearCache($key);
            }
        }

        // Clear group cache
        $this->clearGroupCache($groupName);

        return $success;
    }

    /**
     * Get all configurations as array (for env generation)
     */
    public function all(): array
    {
        $cacheKey = self::CACHE_PREFIX . 'all';
        
        return Cache::remember($cacheKey, self::CACHE_TTL, function () {
            return Configuration::all()->mapWithKeys(function ($config) {
                return [$config->key => $config->value];
            })->toArray();
        });
    }

    /**
     * Clear cache for specific key
     */
    public function clearCache(string $key): void
    {
        Cache::forget(self::CACHE_PREFIX . $key);
        Cache::forget(self::CACHE_PREFIX . 'all');
    }

    /**
     * Clear cache for group
     */
    public function clearGroupCache(string $groupName): void
    {
        Cache::forget(self::CACHE_PREFIX . 'group:' . $groupName);
        Cache::forget(self::CACHE_PREFIX . 'all');
    }

    /**
     * Clear all configuration cache
     */
    public function clearAllCache(): void
    {
        $keys = Cache::get(self::CACHE_PREFIX . 'keys', []);
        foreach ($keys as $key) {
            Cache::forget($key);
        }
        Cache::forget(self::CACHE_PREFIX . 'all');
    }

    /**
     * Cast value based on configuration type
     */
    protected function castValue(mixed $value, string $type): mixed
    {
        if (is_null($value)) {
            return null;
        }

        return match ($type) {
            'boolean' => in_array(strtolower($value), ['true', '1', 'yes', 'on'], true),
            'number' => is_numeric($value) ? (str_contains($value, '.') ? (float) $value : (int) $value) : $value,
            'textarea', 'text', 'select', 'password' => (string) $value,
            default => $value,
        };
    }

    /**
     * Process value for storage
     */
    protected function processValueForStorage(mixed $value, string $type): string
    {
        return match ($type) {
            'boolean' => $value ? 'true' : 'false',
            'number' => (string) $value,
            default => (string) $value,
        };
    }

    /**
     * Update .env file with current configurations
     */
    public function updateEnvFile(): bool
    {
        try {
            $configurations = $this->all();
            $envPath = base_path('.env');
            
            if (!file_exists($envPath)) {
                return false;
            }

            $envContent = file_get_contents($envPath);
            $envLines = explode("\n", $envContent);
            $updatedLines = [];
            $processedKeys = [];

            // Update existing lines
            foreach ($envLines as $line) {
                if (preg_match('/^([A-Z_]+)=(.*)$/', $line, $matches)) {
                    $key = $matches[1];
                    if (array_key_exists($key, $configurations)) {
                        $value = $configurations[$key];
                        $updatedLines[] = $key . '=' . $this->formatEnvValue($value);
                        $processedKeys[] = $key;
                    } else {
                        $updatedLines[] = $line;
                    }
                } else {
                    $updatedLines[] = $line;
                }
            }

            // Add new configurations
            foreach ($configurations as $key => $value) {
                if (!in_array($key, $processedKeys)) {
                    $updatedLines[] = $key . '=' . $this->formatEnvValue($value);
                }
            }

            return file_put_contents($envPath, implode("\n", $updatedLines)) !== false;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Format value for .env file
     */
    protected function formatEnvValue(mixed $value): string
    {
        if (is_null($value)) {
            return '';
        }

        $value = (string) $value;

        // Quote value if it contains spaces or special characters
        if (preg_match('/\s|[#"\'\\\\]/', $value)) {
            return '"' . addslashes($value) . '"';
        }

        return $value;
    }
}