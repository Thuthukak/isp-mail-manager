<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BackupJobResource\Pages;
use App\Models\BackupJob;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Filament\Support\Enums\FontWeight;

class BackupJobResource extends Resource
{
    protected static ?string $model = BackupJob::class;

    protected static ?string $navigationIcon = 'heroicon-o-clock';
    
    protected static ?string $navigationLabel = 'Backup History';
    
    protected static ?string $navigationGroup = 'Email Backups';
    
    protected static ?int $navigationSort = 4;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Job Information')
                    ->schema([
                        Forms\Components\Select::make('email_account_id')
                            ->relationship('emailAccount', 'email')
                            ->required()
                            ->disabled(),
                            
                        Forms\Components\Select::make('status')
                            ->options([
                                'pending' => 'Pending',
                                'running' => 'Running',
                                'completed' => 'Completed',
                                'failed' => 'Failed'
                            ])
                            ->disabled(),
                            
                        Forms\Components\TextInput::make('emails_backed_up')
                            ->numeric()
                            ->disabled(),
                            
                        Forms\Components\TextInput::make('backup_path')
                            ->disabled()
                            ->columnSpanFull(),
                    ]),
                    
                Forms\Components\Section::make('Timing')
                    ->schema([
                        Forms\Components\DateTimePicker::make('started_at')
                            ->disabled(),
                            
                        Forms\Components\DateTimePicker::make('completed_at')
                            ->disabled(),
                            
                        Forms\Components\Placeholder::make('duration')
                            ->content(function ($record) {
                                if (!$record || !$record->started_at) return 'N/A';
                                
                                $end = $record->completed_at ?? now();
                                $duration = $record->started_at->diffInSeconds($end);
                                
                                if ($duration < 60) {
                                    return "{$duration} seconds";
                                } elseif ($duration < 3600) {
                                    return round($duration / 60, 1) . " minutes";
                                } else {
                                    return round($duration / 3600, 1) . " hours";
                                }
                            }),
                    ]),
                    
                Forms\Components\Section::make('Mailboxes Backed Up')
                    ->schema([
                        Forms\Components\Repeater::make('mailboxes_backed_up')
                            ->schema([
                                Forms\Components\TextInput::make('name')
                                    ->disabled(),
                                Forms\Components\TextInput::make('email_count')
                                    ->disabled()
                                    ->numeric(),
                                Forms\Components\TextInput::make('size_mb')
                                    ->disabled()
                                    ->numeric(),
                            ])
                            ->disabled()
                            ->columnSpanFull()
                    ])
                    ->visible(fn ($record) => $record && $record->mailboxes_backed_up),
                    
                Forms\Components\Section::make('Error Details')
                    ->schema([
                        Forms\Components\Textarea::make('error_message')
                            ->disabled()
                            ->rows(5)
                            ->columnSpanFull(),
                    ])
                    ->visible(fn ($record) => $record && $record->error_message)
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('emailAccount.email')
                    ->label('Email Account')
                    ->searchable()
                    ->sortable()
                    ->copyable(),
                    
                Tables\Columns\TextColumn::make('emailAccount.employee_name')
                    ->label('Employee')
                    ->searchable()
                    ->toggleable(),
                    
                Tables\Columns\BadgeColumn::make('emailAccount.department')
                    ->label('Department')
                    ->colors([
                        'primary' => 'Sales',
                        'success' => 'Marketing',
                        'warning' => 'Support',
                        'info' => 'Development',
                        'secondary' => 'HR',
                        'danger' => 'Finance',
                    ])
                    ->toggleable(),
                    
                Tables\Columns\BadgeColumn::make('status')
                    ->colors([
                        'secondary' => 'pending',
                        'warning' => 'running',
                        'success' => 'completed',
                        'danger' => 'failed'
                    ])
                    ->icons([
                        'heroicon-m-clock' => 'pending',
                        'heroicon-m-arrow-path' => 'running',
                        'heroicon-m-check-circle' => 'completed',
                        'heroicon-m-x-circle' => 'failed'
                    ])
                    ->sortable(),
                    
                Tables\Columns\TextColumn::make('emails_backed_up')
                    ->label('Emails')
                    ->numeric()
                    ->sortable()
                    ->alignEnd(),
                    
                Tables\Columns\TextColumn::make('started_at')
                    ->label('Started')
                    ->dateTime('M j, H:i')
                    ->sortable(),
                    
                Tables\Columns\TextColumn::make('duration')
                    ->label('Duration')
                    ->getStateUsing(function ($record) {
                        if (!$record->started_at) return 'N/A';
                        
                        $end = $record->completed_at ?? now();
                        $duration = $record->started_at->diffInSeconds($end);
                        
                        if ($duration < 60) {
                            return "{$duration}s";
                        } elseif ($duration < 3600) {
                            return round($duration / 60, 1) . "m";
                        } else {
                            return round($duration / 3600, 1) . "h";
                        }
                    })
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        return $query->orderByRaw("
                            CASE 
                                WHEN completed_at IS NOT NULL AND started_at IS NOT NULL 
                                THEN TIMESTAMPDIFF(SECOND, started_at, completed_at)
                                WHEN started_at IS NOT NULL 
                                THEN TIMESTAMPDIFF(SECOND, started_at, NOW())
                                ELSE 0 
                            END {$direction}
                        ");
                    }),
                    
                Tables\Columns\TextColumn::make('backup_size')
                    ->label('Size')
                    ->getStateUsing(function ($record) {
                        if (!$record->mailboxes_backed_up) return 'N/A';
                        
                        $totalSizeMb = collect($record->mailboxes_backed_up)->sum('size_mb');
                        
                        if ($totalSizeMb > 1024) {
                            return round($totalSizeMb / 1024, 2) . ' GB';
                        }
                        
                        return round($totalSizeMb, 2) . ' MB';
                    })
                    ->toggleable(),
                    
                Tables\Columns\IconColumn::make('has_error')
                    ->label('Error')
                    ->getStateUsing(fn ($record) => !empty($record->error_message))
                    ->boolean()
                    ->trueIcon('heroicon-o-exclamation-triangle')
                    ->falseIcon('heroicon-o-check-circle')
                    ->trueColor('danger')
                    ->falseColor('success')
                    ->toggleable(),
                    
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime('M j, Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('started_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'running' => 'Running', 
                        'completed' => 'Completed',
                        'failed' => 'Failed'
                    ])
                    ->multiple(),
                    
                Tables\Filters\SelectFilter::make('department')
                    ->relationship('emailAccount', 'department')
                    ->options([
                        'Sales' => 'Sales',
                        'Marketing' => 'Marketing',
                        'Support' => 'Support',
                        'Development' => 'Development',
                        'HR' => 'HR',
                        'Finance' => 'Finance',
                    ])
                    ->multiple(),
                    
                Tables\Filters\Filter::make('created_at')
                    ->form([
                        Forms\Components\DatePicker::make('created_from')
                            ->label('From Date'),
                        Forms\Components\DatePicker::make('created_until')
                            ->label('Until Date'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['created_from'],
                                fn (Builder $query, $date): Builder => $query->whereDate('created_at', '>=', $date),
                            )
                            ->when(
                                $data['created_until'],
                                fn (Builder $query, $date): Builder => $query->whereDate('created_at', '<=', $date),
                            );
                    }),
                    
                Tables\Filters\TernaryFilter::make('has_error')
                    ->label('Has Errors')
                    ->nullable()
                    ->trueLabel('With Errors')
                    ->falseLabel('Without Errors')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('error_message'),
                        false: fn (Builder $query) => $query->whereNull('error_message'),
                    ),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                
                Tables\Actions\Action::make('view_backup_path')
                    ->icon('heroicon-m-folder-open')
                    ->color('info')
                    ->url(fn (BackupJob $record): string => $record->backup_path ? 
                        "https://onedrive.live.com/?id=" . urlencode($record->backup_path) : '#')
                    ->openUrlInNewTab()
                    ->visible(fn (BackupJob $record): bool => !empty($record->backup_path)),
                    
                Tables\Actions\Action::make('retry_backup')
                    ->icon('heroicon-m-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn (BackupJob $record): bool => $record->status === 'failed')
                    ->action(function (BackupJob $record) {
                        \App\Jobs\BackupEmailAccountJob::dispatch($record->emailAccount);
                        
                        \Filament\Notifications\Notification::make()
                            ->title('Backup Retry Started')
                            ->body("New backup job queued for {$record->emailAccount->email}")
                            ->success()
                            ->send();
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->modalHeading('Delete Backup Jobs')
                        ->modalDescription('This will delete the job records but not the actual backup files.'),
                        
                    Tables\Actions\BulkAction::make('retry_failed')
                        ->icon('heroicon-m-arrow-path')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalHeading('Retry Failed Backups')
                        ->modalDescription('Queue new backup jobs for all selected failed backups?')
                        ->action(function ($records) {
                            $count = 0;
                            foreach ($records as $record) {
                                if ($record->status === 'failed') {
                                    \App\Jobs\BackupEmailAccountJob::dispatch($record->emailAccount);
                                    $count++;
                                }
                            }
                            
                            \Filament\Notifications\Notification::make()
                                ->title('Backup Retries Started')
                                ->body("Queued {$count} backup retry jobs")
                                ->success()
                                ->send();
                        }),
                ]),
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
            'index' => Pages\ListBackupJobs::route('/'),
            'view' => Pages\ViewBackupJob::route('/{record}'),
        ];
    }
    
    public static function getNavigationBadge(): ?string
    {
        $runningJobs = static::getModel()::where('status', 'running')->count();
        return $runningJobs > 0 ? (string) $runningJobs : null;
    }
    
    public static function getNavigationBadgeColor(): string|array|null
    {
        return 'warning';
    }
}