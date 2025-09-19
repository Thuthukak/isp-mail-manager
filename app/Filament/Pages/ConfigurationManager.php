<?php
// app/Filament/Pages/ConfigurationManager.php

namespace App\Filament\Pages;

use App\Models\ConfigurationGroup;
use App\Services\ConfigurationService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Support\Htmlable;

class ConfigurationManager extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';
    protected static ?string $navigationGroup = 'System';
    protected static ?string $navigationLabel = 'System Configuration';
    protected static ?int $navigationSort = 0;
    protected static string $view = 'filament.pages.configuration-manager';

    public ?array $data = [];
    public ?int $activeTab = null;

    public function mount(): void
    {
        $groups = ConfigurationGroup::with('configurations')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        if ($groups->isNotEmpty() && is_null($this->activeTab)) {
            $this->activeTab = $groups->first()->id;
        }

        $this->loadConfigurationData();
    }

    public function getTitle(): string | Htmlable
    {
        return 'System Configuration';
    }

    protected function loadConfigurationData(): void
    {
        $groups = ConfigurationGroup::with('configurations')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        foreach ($groups as $group) {
            $this->data[$group->id] = [];
            foreach ($group->configurations as $config) {
                $value = $config->value;
                
                // Handle boolean values for form display
                if ($config->type === 'boolean') {
                    $value = in_array(strtolower($value), ['true', '1', 'yes', 'on'], true);
                }
                
                $this->data[$group->id][$config->key] = $value;
            }
        }
    }

    public function form(Form $form): Form
    {
        $groups = ConfigurationGroup::with(['configurations' => function ($query) {
            $query->orderBy('sort_order');
        }])
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        $tabs = [];

        foreach ($groups as $group) {
            $fields = [];

            foreach ($group->configurations as $config) {
                $field = $this->createFieldForConfiguration($config);
                if ($field) {
                    $fields[] = $field;
                }
            }

            if (!empty($fields)) {
                $tabs[$group->id] = Forms\Components\Tabs\Tab::make($group->display_name)
                    ->icon($group->icon)
                    ->schema([
                        Forms\Components\Section::make($group->display_name)
                            ->description($group->description)
                            ->schema($fields)
                            ->columns(2)
                    ]);
            }
        }

        return $form
            ->schema([
                Forms\Components\Tabs::make('configuration_tabs')
                    ->tabs($tabs)
                    ->activeTab($this->activeTab)
                    ->persistTabInQueryString()
            ])
            ->statePath('data.' . $this->activeTab);
    }

    protected function createFieldForConfiguration($config): ?Forms\Components\Field
    {
        $baseField = match ($config->type) {
            'text' => Forms\Components\TextInput::make($config->key),
            'number' => Forms\Components\TextInput::make($config->key)->numeric(),
            'textarea' => Forms\Components\Textarea::make($config->key)->rows(3),
            'password' => Forms\Components\TextInput::make($config->key)->password()->revealable(),
            'boolean' => Forms\Components\Toggle::make($config->key),
            'select' => Forms\Components\Select::make($config->key)
                ->options($config->options ?? [])
                ->searchable(count($config->options ?? []) > 5),
            default => null,
        };

        if (!$baseField) {
            return null;
        }

        return $baseField
            ->label($config->display_name)
            ->helperText($config->description)
            ->required($config->is_required)
            ->rules($config->validation_rules ? explode('|', $config->validation_rules) : []);
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $configService = app(ConfigurationService::class);
        
        $group = ConfigurationGroup::find($this->activeTab);
        if (!$group) {
            Notification::make()
                ->title('Error')
                ->body('Configuration group not found.')
                ->danger()
                ->send();
            return;
        }

        try {
            $success = $configService->setGroup($group->name, $data);

            if ($success) {
                Notification::make()
                    ->title('Success')
                    ->body('Configuration saved successfully.')
                    ->success()
                    ->send();
            } else {
                Notification::make()
                    ->title('Error')
                    ->body('Failed to save configuration.')
                    ->danger()
                    ->send();
            }
        } catch (\Exception $e) {
            Notification::make()
                ->title('Error')
                ->body('An error occurred: ' . $e->getMessage())
                ->danger()
                ->send();
        }
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label('Save Configuration')
                ->submit('save')
                ->keyBindings(['mod+s']),
            
            Action::make('updateEnv')
                ->label('Update .env File')
                ->action('updateEnvFile')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Update .env File')
                ->modalDescription('This will update your .env file with all current database configurations. Make sure to backup your .env file first.')
                ->modalSubmitActionLabel('Update .env'),
        ];
    }

    public function updateEnvFile(): void
    {
        $configService = app(ConfigurationService::class);
        
        try {
            $success = $configService->updateEnvFile();

            if ($success) {
                Notification::make()
                    ->title('Success')
                    ->body('.env file updated successfully.')
                    ->success()
                    ->send();
            } else {
                Notification::make()
                    ->title('Error')
                    ->body('Failed to update .env file.')
                    ->danger()
                    ->send();
            }
        } catch (\Exception $e) {
            Notification::make()
                ->title('Error')
                ->body('An error occurred: ' . $e->getMessage())
                ->danger()
                ->send();
        }
    }
}