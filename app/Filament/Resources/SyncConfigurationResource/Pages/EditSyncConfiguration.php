<?php

namespace App\Filament\Resources\SyncConfigurationResource\Pages;

use App\Filament\Resources\SyncConfigurationResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSyncConfiguration extends EditRecord
{
    protected static string $resource = SyncConfigurationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}