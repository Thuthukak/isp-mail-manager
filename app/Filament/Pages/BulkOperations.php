<?php

namespace App\Filament\Pages;

use App\Jobs\ProcessInitialBackupJob;
use App\Jobs\SyncNewMailsJob;
use App\Jobs\PurgeOldMailsJob;
use App\Jobs\ForceSyncJob;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Filament\Actions\Action;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;

class BulkOperations extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-bolt';
    protected static string $view = 'filament.pages.bulk-operations';
    protected static ?string $navigationGroup = 'Operations';
    protected static ?int $navigationSort = 1;

    public ?array $initialBackupData = [];
    public ?array $syncData = [];
    public ?array $purgeData = [];
    public ?array $forceData = [];

    public function initialBackupForm(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('directory')
                    ->label('Mail Directory')
                    ->default('/var/mail')
                    ->required(),
                Forms\Components\Toggle::make('recursive')
                    ->label('Include Subdirectories')
                    ->default(true),
                Forms\Components\Select::make('priority')
                    ->label('Queue Priority')
                    ->options([
                        'low' => 'Low',
                        'normal' => 'Normal',
                        'high' => 'High',
                    ])
                    ->default('normal'),
            ])
            ->statePath('initialBackupData');
    }

    public function syncForm(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\DateTimePicker::make('since')
                    ->label('Sync files modified since')
                    ->default(now()->subHour()),
                Forms\Components\TextInput::make('batch_size')
                    ->label('Batch Size')
                    ->numeric()
                    ->default(50),
                Forms\Components\Toggle::make('verify_checksums')
                    ->label('Verify Checksums')
                    ->default(true),
            ])
            ->statePath('syncData');
    }

    public function purgeForm(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\DatePicker::make('older_than')
                    ->label('Purge files older than')
                    ->default(now()->subYear())
                    ->required(),
                Forms\Components\Toggle::make('dry_run')
                    ->label('Dry Run (Preview only)')
                    ->default(true),
                Forms\Components\Textarea::make('exclude_patterns')
                    ->label('Exclude Patterns (one per line)')
                    ->placeholder("*.log\ntemp/*\n*.tmp"),
            ])
            ->statePath('purgeData');
    }

    public function forceForm(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('specific_path')
                    ->label('Specific Path (optional)')
                    ->placeholder('/var/mail/user@domain.com'),
                Forms\Components\Toggle::make('force_reupload')
                    ->label('Force Re-upload Existing Files')
                    ->default(false),
                Forms\Components\Select::make('operation_type')
                    ->label('Operation Type')
                    ->options([
                        'full_sync' => 'Full Synchronization',
                        'verification_only' => 'Verification Only',
                        'repair_missing' => 'Repair Missing Files',
                    ])
                    ->default('full_sync'),
            ])
            ->statePath('forceData');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('initial_backup')
                ->label('Start Initial Backup')
                ->color('primary')
                ->form([
                    Forms\Components\TextInput::make('directory')
                        ->label('Mail Directory')
                        ->default('/var/mail')
                        ->required(),
                    Forms\Components\Toggle::make('recursive')
                        ->label('Include Subdirectories')
                        ->default(true),
                ])
                ->action(function (array $data) {
                    ProcessInitialBackupJob::dispatch($data['directory'], $data['recursive']);
                    
                    Notification::make()
                        ->title('Initial backup started')
                        ->body('The backup process has been queued and will start shortly.')
                        ->success()
                        ->send();
                }),

            Action::make('sync_new')
                ->label('Sync New Files')
                ->color('success')
                ->form([
                    Forms\Components\DateTimePicker::make('since')
                        ->label('Sync files modified since')
                        ->default(now()->subHour()),
                ])
                ->action(function (array $data) {
                    SyncNewMailsJob::dispatch($data['since']);
                    
                    Notification::make()
                        ->title('Sync operation started')
                        ->success()
                        ->send();
                }),

            Action::make('force_sync')
                ->label('Force Sync')
                ->color('warning')
                ->requiresConfirmation()
                ->form([
                    Forms\Components\TextInput::make('specific_path')
                        ->label('Specific Path (optional)'),
                ])
                ->action(function (array $data) {
                    ForceSyncJob::dispatch($data['specific_path'] ?? null);
                    
                    Notification::make()
                        ->title('Force sync started')
                        ->warning()
                        ->send();
                }),

            Action::make('purge_old')
                ->label('Purge Old Files')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Purge Old Files')
                ->modalDescription('This action will permanently delete old backup files. Are you sure?')
                ->modalIcon('heroicon-o-exclamation-triangle')
                ->form([
                    Forms\Components\DatePicker::make('older_than')
                        ->label('Purge files older than')
                        ->default(now()->subYear())
                        ->required(),
                    Forms\Components\Toggle::make('dry_run')
                        ->label('Dry Run (Preview only)')
                        ->default(true),
                ])
                ->action(function (array $data) {
                    PurgeOldMailsJob::dispatch($data['older_than'], $data['dry_run']);
                    
                    Notification::make()
                        ->title($data['dry_run'] ? 'Purge preview started' : 'Purge operation started')
                        ->color($data['dry_run'] ? 'info' : 'danger')
                        ->send();
                }),
        ];
    }
}