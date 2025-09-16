<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SyncLogResource\Pages;
use App\Models\SyncLog;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Infolists;
use Filament\Infolists\Infolist;

class SyncLogResource extends Resource
{
    protected static ?string $model = SyncLog::class;
    protected static ?string $navigationIcon = 'heroicon-o-document-text';
    protected static ?string $navigationGroup = 'System';
    protected static ?int $navigationSort = 2;
    protected static ?string $navigationLabel = 'Sync Logs';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\BadgeColumn::make('operation_type')
                    ->label('Operation')
                    ->colors([
                        'primary' => 'initial_backup',
                        'success' => 'sync_new',
                        'warning' => 'purge_old',
                        'danger' => 'force_sync',
                        'info' => 'restore',
                    ]),
                Tables\Columns\BadgeColumn::make('status')
                    ->colors([
                        'warning' => 'running',
                        'success' => 'completed',
                        'danger' => 'failed',
                    ]),
                Tables\Columns\TextColumn::make('duration')
                    ->state(function (SyncLog $record): ?string {
                        if (!$record->started_at || !$record->completed_at) {
                            return null;
                        }
                        $diff = $record->completed_at->diffInSeconds($record->started_at);
                        return gmdate('H:i:s', $diff);
                    })
                    ->placeholder('N/A'),
                Tables\Columns\TextColumn::make('started_at')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('completed_at')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('Running...'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('operation_type')
                    ->options([
                        'initial_backup' => 'Initial Backup',
                        'sync_new' => 'Sync New',
                        'purge_old' => 'Purge Old',
                        'force_sync' => 'Force Sync',
                        'restore' => 'Restore',
                    ]),
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'running' => 'Running',
                        'completed' => 'Completed',
                        'failed' => 'Failed',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->defaultSort('started_at', 'desc');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Operation Details')
                    ->schema([
                        Infolists\Components\TextEntry::make('operation_type')
                            ->badge(),
                        Infolists\Components\TextEntry::make('status')
                            ->badge(),
                        Infolists\Components\TextEntry::make('started_at')
                            ->dateTime(),
                        Infolists\Components\TextEntry::make('completed_at')
                            ->dateTime()
                            ->placeholder('Still running...'),
                        Infolists\Components\TextEntry::make('duration')
                            ->state(function (SyncLog $record): ?string {
                                if (!$record->started_at || !$record->completed_at) {
                                    return null;
                                }
                                $diff = $record->completed_at->diffInSeconds($record->started_at);
                                return gmdate('H:i:s', $diff);
                            })
                            ->placeholder('N/A'),
                    ])->columns(2),
                Infolists\Components\Section::make('Details')
                    ->schema([
                        Infolists\Components\TextEntry::make('details')
                            ->columnSpanFull()
                            ->html()
                            ->placeholder('No details available'),
                    ]),
            ]);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSyncLogs::route('/'),
            'view' => Pages\ViewSyncLog::route('/{record}'),
        ];
    }
}