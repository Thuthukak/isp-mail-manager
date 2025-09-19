<?php

namespace App\Filament\Resources\ConfigurationGroupResource\Pages;

use App\Filament\Resources\ConfigurationGroupResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListConfigurationGroups extends ListRecords
{
    protected static string $resource = ConfigurationGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}