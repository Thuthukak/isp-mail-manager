<?php

namespace App\Filament\Resources\SyncLogResource\Pages;

use App\Filament\Resources\SyncLogResource;
use Filament\Resources\Pages\ViewRecord;

class ViewSyncLog extends ViewRecord
{
    protected static string $resource = SyncLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // No edit or delete actions since logs are read-only
        ];
    }
}