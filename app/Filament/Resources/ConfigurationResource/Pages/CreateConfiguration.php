<?php

namespace App\Filament\Resources\ConfigurationResource\Pages;

use App\Filament\Resources\ConfigurationResource;
use Filament\Resources\Pages\CreateRecord;

class CreateConfiguration extends CreateRecord
{
    protected static string $resource = ConfigurationResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Handle boolean values
        if ($data['type'] === 'boolean' && isset($data['value'])) {
            $data['value'] = $data['value'] ? 'true' : 'false';
        }

        return $data;
    }
}