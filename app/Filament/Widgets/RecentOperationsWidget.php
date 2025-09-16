<?php

namespace App\Filament\Widgets;

use App\Models\SyncLog;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class RecentOperationsWidget extends BaseWidget
{
    protected static ?int $sort = 3;
    protected int | string | array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(SyncLog::query()->latest('started_at')->limit(10))
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
                    ->placeholder('Running...'),
                Tables\Columns\TextColumn::make('started_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->url(fn (SyncLog $record): string => 
                        route('filament.admin.resources.sync-logs.view', $record)
                    ),
            ]);
    }
}