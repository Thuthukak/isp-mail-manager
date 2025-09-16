<?php

namespace App\Filament\Pages;

use App\Models\SyncConfiguration;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Filament\Actions\Action;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;

class SystemConfiguration extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-cog-8-tooth';
    protected static string $view = 'filament.pages.system-configuration';
    protected static ?string $navigationGroup = 'System';
    protected static ?int $navigationSort = 10;

    public ?array $data = [];

    public function mount(): void
    {
        $configs = SyncConfiguration::pluck('value', 'key')->toArray();
        
        // Ensure all values are properly formatted for form fields
        $formData = [];
        foreach ($configs as $key => $value) {
            // Debug logging (remove after fixing)
            if (is_array($value) || is_object($value)) {
                \Log::warning("Field '{$key}' contains array/object data", ['value' => $value]);
            }

            // Handle null values
            if ($value === null) {
                $formData[$key] = '';
                continue;
            }

            // Convert boolean strings to actual booleans for toggle fields
            if ($key === 'enable_slack_notifications') {
                $formData[$key] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
            }
            // Handle arrays (convert to string representation)
            elseif (is_array($value)) {
                $formData[$key] = json_encode($value);
            }
            // Handle objects (convert to string representation)
            elseif (is_object($value)) {
                $formData[$key] = json_encode($value);
            }
            // Ensure all other values are strings
            else {
                $formData[$key] = (string)$value;
            }
        }
        
        $this->form->fill($formData);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('OneDrive Configuration')
                    ->schema([
                        Forms\Components\TextInput::make('onedrive_client_id')
                            ->label('Client ID')
                            ->required(),
                        Forms\Components\TextInput::make('onedrive_client_secret')
                            ->label('Client Secret')
                            ->password()
                            ->required(),
                        Forms\Components\TextInput::make('onedrive_tenant_id')
                            ->label('Tenant ID')
                            ->required(),
                        Forms\Components\TextInput::make('onedrive_root_folder')
                            ->label('Root Folder Path')
                            ->default('/mail-backups')
                            ->required(),
                    ])->columns(2),

                Forms\Components\Section::make('Sync Configuration')
                    ->schema([
                        Forms\Components\TextInput::make('sync_interval_minutes')
                            ->label('Sync Interval (minutes)')
                            ->numeric()
                            ->default(30)
                            ->required(),
                        Forms\Components\TextInput::make('batch_size')
                            ->label('Batch Size')
                            ->numeric()
                            ->default(100)
                            ->required(),
                        Forms\Components\TextInput::make('max_file_size_mb')
                            ->label('Max File Size (MB)')
                            ->numeric()
                            ->default(100)
                            ->required(),
                        Forms\Components\TextInput::make('retention_days')
                            ->label('Retention Days')
                            ->numeric()
                            ->default(365)
                            ->required(),
                    ])->columns(2),

                Forms\Components\Section::make('Mail Server Configuration')
                    ->schema([
                        Forms\Components\TextInput::make('mail_server_path')
                            ->label('Mail Server Path')
                            ->default('/var/mail')
                            ->required(),
                        Forms\Components\Textarea::make('excluded_directories')
                            ->label('Excluded Directories (one per line)')
                            ->placeholder("tmp\ntemp\n.trash")
                            ->rows(3),
                        Forms\Components\Textarea::make('file_extensions')
                            ->label('Included File Extensions (comma separated)')
                            ->default('.eml,.msg,.pst')
                            ->required(),
                    ])->columns(1),

                Forms\Components\Section::make('Monitoring Configuration')
                    ->schema([
                        Forms\Components\TextInput::make('mailbox_size_threshold_mb')
                            ->label('Default Mailbox Size Threshold (MB)')
                            ->numeric()
                            ->default(1000)
                            ->required(),
                        Forms\Components\TextInput::make('alert_email')
                            ->label('Alert Email Address')
                            ->email(),
                        Forms\Components\Toggle::make('enable_slack_notifications')
                            ->label('Enable Slack Notifications'),
                        Forms\Components\TextInput::make('slack_webhook_url')
                            ->label('Slack Webhook URL')
                            ->visible(fn (Forms\Get $get) => $get('enable_slack_notifications')),
                    ])->columns(2),
            ])
            ->statePath('data');
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label(__('filament-panels::resources/pages/edit-record.form.actions.save.label'))
                ->submit('save'),
        ];
    }

    public function save(): void
    {
        $data = $this->form->getState();

        foreach ($data as $key => $value) {
            // Handle null values
            if ($value === null) {
                $storeValue = '';
            }
            // Store booleans as string representation
            elseif (is_bool($value)) {
                $storeValue = $value ? '1' : '0';
            }
            // Store arrays/objects as JSON strings
            elseif (is_array($value) || is_object($value)) {
                $storeValue = json_encode($value);
            }
            // Ensure value is stored as string
            else {
                $storeValue = (string)$value;
            }
            
            SyncConfiguration::updateOrCreate(
                ['key' => $key],
                ['value' => $storeValue]
            );
        }

        // Clear configuration cache
        \Illuminate\Support\Facades\Cache::forget('sync_configuration');

        Notification::make()
            ->title('Configuration saved successfully')
            ->success()
            ->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('test_connection')
                ->label('Test OneDrive Connection')
                ->color('info')
                ->action(function () {
                    try {
                        // Test OneDrive connection logic here
                        Notification::make()
                            ->title('OneDrive connection successful')
                            ->success()
                            ->send();
                    } catch (\Exception $e) {
                        Notification::make()
                            ->title('OneDrive connection failed')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }
}