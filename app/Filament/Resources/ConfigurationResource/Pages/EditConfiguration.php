<?php

namespace App\Filament\Resources\ConfigurationResource\Pages;

use App\Filament\Resources\ConfigurationResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditConfiguration extends EditRecord
{
    protected static string $resource = ConfigurationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        // Handle boolean values for form display
        if ($data['type'] === 'boolean' && isset($data['value'])) {
            $data['value'] = $data['value'] === 'true' || $data['value'] === true;
        }

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Handle boolean values
        if ($data['type'] === 'boolean' && isset($data['value'])) {
            $data['value'] = $data['value'] ? 'true' : 'false';
        }

        return $data;
    }
}
