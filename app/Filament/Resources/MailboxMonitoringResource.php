<?php
namespace App\Filament\Resources;

use App\Filament\Resources\MailboxMonitoringResource\Pages;
use App\Models\MailboxAlert;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class MailboxMonitoringResource extends Resource
{
    protected static ?string $model = MailboxAlert::class;
    protected static ?string $navigationIcon = 'heroicon-o-exclamation-triangle';
    protected static ?string $navigationGroup = 'Monitoring';
    protected static ?int $navigationSort = 1;
    protected static ?string $navigationLabel = 'Mailbox Alerts';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('email_address')
                    ->label('Email Address')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('current_size_mb')
                    ->label('Current Size (MB)')
                    ->state(function (MailboxAlert $record): float {
                        return $record->current_size_bytes / (1024 * 1024);
                    })
                    ->formatStateUsing(fn (float $state): string => number_format($state, 2))
                    ->sortable(),
                Tables\Columns\TextColumn::make('threshold_mb')
                    ->label('Threshold (MB)')
                    ->state(function (MailboxAlert $record): float {
                        return $record->threshold_bytes / (1024 * 1024);
                    })
                    ->formatStateUsing(fn (float $state): string => number_format($state, 2))
                    ->sortable(),
                Tables\Columns\TextColumn::make('usage_percentage')
                    ->label('Usage %')
                    ->state(function (MailboxAlert $record): float {
                        return ($record->current_size_bytes / $record->threshold_bytes) * 100;
                    })
                    ->formatStateUsing(fn (float $state): string => number_format($state, 1) . '%')
                    ->color(fn (float $state): string => match (true) {
                        $state >= 95 => 'danger',
                        $state >= 80 => 'warning',
                        default => 'success',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('alert_type')
                    ->label('Alert Type')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'size_critical' => 'danger',
                        'purge_required' => 'danger',
                        'size_warning' => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'size_critical' => 'Critical',
                        'size_warning' => 'Warning',
                        'purge_required' => 'Purge Required',
                        default => ucfirst($state),
                    }),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'resolved' => 'success',
                        'acknowledged' => 'warning',
                        'active' => 'danger',
                        'ignored' => 'gray',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => ucfirst($state)),
                Tables\Columns\TextColumn::make('alert_date')
                    ->label('Alert Date')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('acknowledged_by')
                    ->label('Acknowledged By')
                    ->placeholder('Not acknowledged')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('acknowledged_at')
                    ->label('Acknowledged At')
                    ->dateTime()
                    ->placeholder('Not acknowledged')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'active' => 'Active',
                        'acknowledged' => 'Acknowledged',
                        'resolved' => 'Resolved',
                        'ignored' => 'Ignored',
                    ])
                    ->default('active'),
                Tables\Filters\SelectFilter::make('alert_type')
                    ->label('Alert Type')
                    ->options([
                        'size_warning' => 'Size Warning',
                        'size_critical' => 'Size Critical',
                        'purge_required' => 'Purge Required',
                    ]),
                Tables\Filters\Filter::make('critical')
                    ->label('Critical Usage (95%+)')
                    ->query(fn ($query) => $query->whereRaw('(current_size_bytes / threshold_bytes) * 100 >= 95')),
            ])
            ->actions([
                Tables\Actions\Action::make('acknowledge')
                    ->icon('heroicon-m-check')
                    ->color('warning')
                    ->visible(fn (MailboxAlert $record): bool => $record->status === 'active')
                    ->form([
                        Forms\Components\TextInput::make('acknowledged_by')
                            ->label('Acknowledged By')
                            ->default(auth()->user()->name ?? 'System')
                            ->required(),
                        Forms\Components\Textarea::make('admin_notes')
                            ->label('Notes')
                            ->placeholder('Optional notes about this acknowledgment'),
                    ])
                    ->action(function (MailboxAlert $record, array $data) {
                        $record->update([
                            'status' => 'acknowledged',
                            'acknowledged_by' => $data['acknowledged_by'],
                            'acknowledged_at' => now(),
                            'admin_notes' => $data['admin_notes'] ?? $record->admin_notes,
                        ]);
                    }),
                Tables\Actions\Action::make('resolve')
                    ->icon('heroicon-m-check-circle')
                    ->color('success')
                    ->visible(fn (MailboxAlert $record): bool => in_array($record->status, ['active', 'acknowledged']))
                    ->form([
                        Forms\Components\Textarea::make('admin_notes')
                            ->label('Resolution Notes')
                            ->placeholder('Optional notes about the resolution'),
                    ])
                    ->action(function (MailboxAlert $record, array $data) {
                        $record->update([
                            'status' => 'resolved',
                            'admin_notes' => $data['admin_notes'] ?? $record->admin_notes,
                        ]);
                    }),
                Tables\Actions\Action::make('ignore')
                    ->icon('heroicon-m-eye-slash')
                    ->color('gray')
                    ->visible(fn (MailboxAlert $record): bool => in_array($record->status, ['active', 'acknowledged']))
                    ->form([
                        Forms\Components\Textarea::make('admin_notes')
                            ->label('Ignore Reason')
                            ->placeholder('Why is this alert being ignored?')
                            ->required(),
                    ])
                    ->action(function (MailboxAlert $record, array $data) {
                        $record->update([
                            'status' => 'ignored',
                            'admin_notes' => $data['admin_notes'],
                        ]);
                    }),
                Tables\Actions\Action::make('reactivate')
                    ->icon('heroicon-m-arrow-path')
                    ->color('warning')
                    ->visible(fn (MailboxAlert $record): bool => in_array($record->status, ['resolved', 'ignored']))
                    ->action(fn (MailboxAlert $record) => $record->update(['status' => 'active'])),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('acknowledge_selected')
                    ->label('Acknowledge Selected')
                    ->icon('heroicon-m-check')
                    ->color('warning')
                    ->form([
                        Forms\Components\TextInput::make('acknowledged_by')
                            ->label('Acknowledged By')
                            ->default(auth()->user()->name ?? 'System')
                            ->required(),
                        Forms\Components\Textarea::make('admin_notes')
                            ->label('Notes')
                            ->placeholder('Optional notes for all selected alerts'),
                    ])
                    ->action(function ($records, array $data) {
                        foreach ($records as $record) {
                            if ($record->status === 'active') {
                                $record->update([
                                    'status' => 'acknowledged',
                                    'acknowledged_by' => $data['acknowledged_by'],
                                    'acknowledged_at' => now(),
                                    'admin_notes' => $data['admin_notes'] ?? $record->admin_notes,
                                ]);
                            }
                        }
                    }),
                Tables\Actions\BulkAction::make('resolve_selected')
                    ->label('Resolve Selected')
                    ->icon('heroicon-m-check-circle')
                    ->color('success')
                    ->action(function ($records) {
                        foreach ($records as $record) {
                            if (in_array($record->status, ['active', 'acknowledged'])) {
                                $record->update(['status' => 'resolved']);
                            }
                        }
                    }),
            ])
            ->defaultSort('alert_date', 'desc');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMailboxMonitoring::route('/'),
        ];
    }
}