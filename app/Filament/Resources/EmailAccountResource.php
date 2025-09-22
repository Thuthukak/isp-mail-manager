<?php

namespace App\Filament\Resources;

use App\Filament\Resources\EmailAccountResource\Pages;
use App\Models\EmailAccount;
use App\Services\MailBackupService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use App\Jobs\BackupEmailAccountJob;


class EmailAccountResource extends Resource
{
    protected static ?string $model = EmailAccount::class;

    protected static ?string $navigationIcon = 'heroicon-o-at-symbol';
    
    protected static ?string $navigationLabel = 'Email Accounts';
    
    protected static ?string $navigationGroup = 'Email Backups';
    
    protected static ?int $navigationSort = 3;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Account Information')
                    ->schema([
                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\TextInput::make('email')
                                    ->email()
                                    ->required()
                                    ->unique(ignoreRecord: true)
                                    ->placeholder('john.doe@company.com'),
                                    
                                Forms\Components\TextInput::make('employee_name')
                                    ->required()
                                    ->placeholder('John Doe'),
                                    
                                Forms\Components\Select::make('department')
                                    ->options([
                                        'Sales' => 'Sales',
                                        'Marketing' => 'Marketing', 
                                        'Support' => 'Support',
                                        'Development' => 'Development',
                                        'HR' => 'Human Resources',
                                        'Finance' => 'Finance',
                                        'Management' => 'Management',
                                        'Other' => 'Other'
                                    ])
                                    ->searchable()
                                    ->required(),
                                    
                                Forms\Components\Toggle::make('active')
                                    ->default(true)
                                    ->helperText('Only active accounts will be included in backups'),
                            ])
                    ]),
                    
                Forms\Components\Section::make('IMAP Configuration')
                    ->schema([
                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\TextInput::make('username')
                                    ->required()
                                    ->placeholder('Usually same as email')
                                    ->default(fn ($get) => $get('email')),
                                    
                                Forms\Components\TextInput::make('password')
                                    ->password()
                                    ->required()
                                    ->revealable()
                                    ->dehydrateStateUsing(fn ($state) => encrypt($state))
                                    ->helperText('Password will be encrypted when saved'),
                                    
                                Forms\Components\TextInput::make('imap_host')
                                    ->required()
                                    ->placeholder('mail.company.com')
                                    ->helperText('IMAP server hostname'),
                                    
                                Forms\Components\TextInput::make('imap_port')
                                    ->numeric()
                                    ->default(993)
                                    ->required()
                                    ->helperText('Usually 993 for SSL, 143 for non-SSL'),
                                    
                                Forms\Components\Toggle::make('imap_ssl')
                                    ->label('Use SSL/TLS')
                                    ->default(true)
                                    ->helperText('Enable for secure connection'),
                            ])
                    ]),
                    
                Forms\Components\Section::make('Backup Information')
                    ->schema([
                        Forms\Components\Placeholder::make('last_backup')
                            ->label('Last Backup')
                            ->content(fn ($record) => $record?->last_backup?->format('Y-m-d H:i:s') ?? 'Never')
                            ->visible(fn ($record) => $record !== null),
                            
                        Forms\Components\Placeholder::make('backup_status')
                            ->label('Backup Status')
                            ->content(function ($record) {
                                if (!$record) return 'New Account';
                                
                                $latestJob = $record->backupJobs()->latest()->first();
                                if (!$latestJob) return 'No backups yet';
                                
                                return match($latestJob->status) {
                                    'completed' => '✅ Last backup successful',
                                    'failed' => '❌ Last backup failed',
                                    'running' => '🔄 Backup in progress',
                                    default => '⏳ Backup pending'
                                };
                            })
                            ->visible(fn ($record) => $record !== null),
                    ])
                    ->visible(fn ($record) => $record !== null)
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('email')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->icon('heroicon-m-at-symbol'),
                    
                Tables\Columns\TextColumn::make('employee_name')
                    ->searchable()
                    ->sortable(),
                    
                Tables\Columns\BadgeColumn::make('department')
                    ->colors([
                        'primary' => 'Sales',
                        'success' => 'Marketing',
                        'warning' => 'Support',
                        'info' => 'Development',
                        'secondary' => 'HR',
                        'danger' => 'Finance',
                    ])
                    ->searchable(),
                    
                Tables\Columns\IconColumn::make('active')
                    ->boolean()
                    ->label('Active')
                    ->sortable(),
                    
                Tables\Columns\TextColumn::make('last_backup')
                    ->dateTime('M j, Y H:i')
                    ->sortable()
                    ->placeholder('Never')
                    ->color(fn ($record) => $record->last_backup?->isToday() ? 'success' : 'warning'),
                    
                Tables\Columns\BadgeColumn::make('backup_status')
                    ->label('Status')
                    ->getStateUsing(function ($record) {
                        $latestJob = $record->backupJobs()->latest()->first();
                        return $latestJob?->status ?? 'never';
                    })
                    ->colors([
                        'success' => 'completed',
                        'danger' => 'failed', 
                        'warning' => 'running',
                        'secondary' => 'never'
                    ])
                    ->icons([
                        'heroicon-m-check-circle' => 'completed',
                        'heroicon-m-x-circle' => 'failed',
                        'heroicon-m-arrow-path' => 'running',
                        'heroicon-m-question-mark-circle' => 'never'
                    ]),
                    
                Tables\Columns\TextColumn::make('backupJobs.emails_backed_up')
                    ->label('Emails Backed Up')
                    ->getStateUsing(fn ($record) => $record->backupJobs()->where('status', 'completed')->sum('emails_backed_up'))
                    ->numeric()
                    ->sortable(),
            ])
            ->defaultSort('last_backup', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('department')
                    ->options([
                        'Sales' => 'Sales',
                        'Marketing' => 'Marketing',
                        'Support' => 'Support', 
                        'Development' => 'Development',
                        'HR' => 'HR',
                        'Finance' => 'Finance',
                    ])
                    ->multiple(),
                    
                Tables\Filters\TernaryFilter::make('active')
                    ->label('Active Status')
                    ->boolean(),
                    
                Tables\Filters\Filter::make('last_backup')
                    ->form([
                        Forms\Components\DatePicker::make('backed_up_since')
                            ->label('Backed up since'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query->when(
                            $data['backed_up_since'],
                            fn (Builder $query, $date): Builder => $query->where('last_backup', '>=', $date),
                        );
                    }),
            ])
            ->actions([
               Tables\Actions\Action::make('test_connection')
                ->icon('heroicon-m-wifi')
                ->color('info')
                ->action(function (EmailAccount $record) {
                    try {
                        $mailService = app(MailBackupService::class);
                        $result = $mailService->connectToIMAP($record);
                        
                        if ($result['success']) {
                            Notification::make()
                                ->title('Connection Successful')
                                ->body("Successfully connected to {$record->email}")
                                ->success()
                                ->send();
                        } else {
                            Notification::make()
                                ->title('Connection Failed')
                                ->body("Failed to connect to {$record->email}: " . $result['error'])
                                ->danger()
                                ->send();
                        }
                    } catch (\Exception $e) {
                        Notification::make()
                            ->title('Connection Failed')
                            ->body("Unexpected error for {$record->email}: " . $e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
                    
                Tables\Actions\Action::make('backup_now')
                    ->icon('heroicon-m-arrow-down-tray')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Start Backup')
                    ->modalDescription('This will start an immediate backup for this email account.')
                    ->action(function (EmailAccount $record) {
                        BackupEmailAccountJob::dispatch($record);
                        
                        Notification::make()
                            ->title('Backup Started')
                            ->body("Backup job queued for {$record->email}")
                            ->success()
                            ->send();
                    }),
                    
                Tables\Actions\EditAction::make(),
                
                Tables\Actions\DeleteAction::make()
                    ->modalHeading('Delete Email Account')
                    ->modalDescription('This will delete the account configuration but not the backed up emails.')
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('backup_selected')
                        ->icon('heroicon-m-arrow-down-tray')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading('Backup Selected Accounts')
                        ->modalDescription('Start backup jobs for all selected email accounts?')
                        ->action(function ($records) {
                            $count = 0;
                            foreach ($records as $record) {
                                if ($record->active) {
                                    BackupEmailAccountJob::dispatch($record);
                                    $count++;
                                }
                            }
                            
                            Notification::make()
                                ->title('Backups Started')
                                ->body("Backup jobs queued for {$count} accounts")
                                ->success()
                                ->send();
                        }),
                        
                    Tables\Actions\BulkAction::make('activate')
                        ->icon('heroicon-m-check-circle')
                        ->color('success')
                        ->action(function ($records) {
                            $count = $records->count();
                            EmailAccount::whereIn('id', $records->pluck('id'))->update(['active' => true]);
                            
                            Notification::make()
                                ->title('Accounts Activated')
                                ->body("{$count} accounts have been activated")
                                ->success()
                                ->send();
                        }),
                        
                    Tables\Actions\BulkAction::make('deactivate')
                        ->icon('heroicon-m-x-circle')
                        ->color('warning')
                        ->action(function ($records) {
                            $count = $records->count();
                            EmailAccount::whereIn('id', $records->pluck('id'))->update(['active' => false]);
                            
                            Notification::make()
                                ->title('Accounts Deactivated')
                                ->body("{$count} accounts have been deactivated")
                                ->warning()
                                ->send();
                        }),
                        
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->headerActions([
                Tables\Actions\Action::make('backup_all')
                    ->icon('heroicon-m-arrow-down-tray')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Backup All Active Accounts')
                    ->modalDescription('This will start backup jobs for all active email accounts in your organization.')
                    ->action(function () {
                        $activeAccounts = EmailAccount::where('active', true)->get();
                        
                        foreach ($activeAccounts as $account) {
                            BackupEmailAccountJob::dispatch($account);
                        }
                        
                        Notification::make()
                            ->title('Organization Backup Started')
                            ->body("Backup jobs queued for {$activeAccounts->count()} active accounts")
                            ->success()
                            ->send();
                    }),
                    //form to add account
                Tables\Actions\Action::make('add_account')
                    ->icon('heroicon-m-document-plus')
                    ->color('success')
                    ->form([
                         // Informational note at the top
        Forms\Components\Placeholder::make('imap_instructions')
            ->label('')
            ->content(new \Illuminate\Support\HtmlString('
                <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-4">
                    <div class="flex items-start">
                        <svg class="w-5 h-5 text-blue-400 mt-0.5 mr-3" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path>
                        </svg>
                        <div class="text-sm text-blue-700">
                            <p class="font-semibold mb-2">IMAP/Incoming Server Configuration</p>
                            <p class="mb-2">Use these settings for IMAP configuration:</p>
                            <ul class="list-disc list-inside space-y-1 mb-2">
                                <li><strong>Username:</strong> Full email address (e.g., user@domain.com)</li>
                                <li><strong>IMAP Host:</strong> Your incoming mail server</li>
                                <li><strong>IMAP Port:</strong> 993 (SSL) or 143 (non-SSL/STARTTLS)</li>
                            </ul>
                            <p class="text-xs text-blue-600">💡 Most secure email providers use port 993 with SSL encryption.</p>
                        </div>
                    </div>
                </div>
            ')),

                        Forms\Components\TextInput::make('email')
                            ->label('Email')
                            ->required(),
                        Forms\Components\TextInput::make('username')
                            ->label('Username')
                            ->required(),
                        Forms\Components\TextInput::make('password')
                            ->label('Password')
                            ->required(),
                        Forms\Components\TextInput::make('imap_host')
                            ->label('IMAP Host')
                            ->required(),
                        Forms\Components\TextInput::make('imap_port')
                            ->label('IMAP Port')
                            ->required(),
                        Forms\Components\TextInput::make('employee_name')
                            ->label('Employee Name')
                            ->required(),
                        Forms\Components\TextInput::make('department')
                            ->label('Department')
                            ->required(),
                    ])
                    ->action(function (array $data) {
                        // Handle account addition logic here
                        try {
                            // Create the account (assuming you have an Account model)
                            $account = EmailAccount::create([
                                'email' => $data['email'],
                                'username' => $data['username'],
                                'password' => encrypt($data['password']), // Encrypt sensitive data
                                'imap_host' => $data['imap_host'],
                                'imap_port' => $data['imap_port'],
                                'employee_name' => $data['employee_name'],
                                'department' => $data['department'],
                                'active' => true
                            ]);

                            // Optional: Test IMAP connection
                            // $this->testImapConnection($data);

                            Notification::make()
                                ->title('Account Added Successfully')
                                ->body("Email account for {$data['email']} has been created")
                                ->success()
                                ->send();

                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('Failed to Add Account')
                                ->body('An error occurred: ' . $e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),    
                    
                Tables\Actions\Action::make('import_accounts')
                    ->icon('heroicon-m-document-arrow-up')
                    ->color('info')
                    ->form([
                        Forms\Components\FileUpload::make('csv_file')
                            ->label('CSV File')
                            ->acceptedFileTypes(['text/csv', 'application/csv'])
                            ->required()
                            ->helperText('CSV should have columns: email, employee_name, department, username, password, imap_host, imap_port'),
                    ])
                    ->action(function (array $data) {
                        // Handle CSV import logic here
                        Notification::make()
                            ->title('Import Completed')
                            ->body('Email accounts have been imported successfully')
                            ->success()
                            ->send();
                    }),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEmailAccounts::route('/'),
            'create' => Pages\CreateEmailAccount::route('/create'),
            'edit' => Pages\EditEmailAccount::route('/{record}/edit'),
        ];
    }
    
    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::where('active', true)->count();
    }
    
    public static function getNavigationBadgeColor(): string|array|null
    {
        return static::getModel()::where('active', true)->count() > 0 ? 'success' : 'warning';
    }
}