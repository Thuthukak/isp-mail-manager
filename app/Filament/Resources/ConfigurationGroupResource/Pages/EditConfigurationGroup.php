<?php

namespace App\Filament\Resources\ConfigurationGroupResource\Pages;

use App\Filament\Resources\ConfigurationGroupResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditConfigurationGroup extends EditRecord
{
    protected static string $resource = ConfigurationGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}