<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MailBackupResource\Pages;
use App\Models\MailBackup;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Illuminate\Database\Eloquent\Builder;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Actions\BulkAction;
use Illuminate\Database\Eloquent\Collection;

class MailBackupResource extends Resource
{
    protected static ?string $model = MailBackup::class;
    protected static ?string $navigationIcon = 'heroicon-o-cloud-arrow-up';
    protected static ?string $navigationGroup = 'Mail Management';
    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('mail_path')
                    ->required()
                    ->maxLength(500)
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('onedrive_path')
                    ->required()
                    ->maxLength(500)
                    ->columnSpanFull(),
                Forms\Components\Select::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'processing' => 'Processing',
                        'completed' => 'Completed',
                        'failed' => 'Failed',
                    ])
                    ->required(),
                Forms\Components\TextInput::make('size')
                    ->numeric()
                    ->suffix('bytes'),
                Forms\Components\Textarea::make('error_message')
                    ->columnSpanFull()
                    ->visible(fn ($get) => $get('status') === 'failed'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('mail_path')
                    ->searchable()
                    ->limit(50)
                    ->tooltip(function (Tables\Columns\TextColumn $column): ?string {
                        $state = $column->getState();
                        return strlen($state) > 50 ? $state : null;
                    }),
                Tables\Columns\BadgeColumn::make('status')
                    ->colors([
                        'warning' => 'pending',
                        'primary' => 'processing',
                        'success' => 'completed',
                        'danger' => 'failed',
                    ]),
                Tables\Columns\TextColumn::make('size')
                    ->formatStateUsing(fn (string $state): string => number_format($state / 1024 / 1024, 2) . ' MB')
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'processing' => 'Processing',
                        'completed' => 'Completed',
                        'failed' => 'Failed',
                    ]),
                Tables\Filters\Filter::make('created_at')
                    ->form([
                        Forms\Components\DatePicker::make('created_from'),
                        Forms\Components\DatePicker::make('created_until'),
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
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('retry')
                    ->icon('heroicon-m-arrow-path')
                    ->color('warning')
                    ->visible(fn (MailBackup $record): bool => $record->status === 'failed')
                    ->action(function (MailBackup $record) {
                        // Dispatch retry job
                        $record->update(['status' => 'pending']);
                        // ProcessInitialBackupJob::dispatch($record);
                    }),
                Tables\Actions\Action::make('download')
                    ->icon('heroicon-m-cloud-arrow-down')
                    ->color('success')
                    ->visible(fn (MailBackup $record): bool => $record->status === 'completed')
                    ->url(fn (MailBackup $record): string => route('backup.download', $record)),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                    BulkAction::make('retry_failed')
                        ->label('Retry Failed')
                        ->icon('heroicon-m-arrow-path')
                        ->color('warning')
                        ->action(function (Collection $records) {
                            $records->where('status', 'failed')->each(function (MailBackup $record) {
                                $record->update(['status' => 'pending']);
                                // ProcessInitialBackupJob::dispatch($record);
                            });
                        }),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Backup Information')
                    ->schema([
                        Infolists\Components\TextEntry::make('mail_path')
                            ->label('Mail Path')
                            ->columnSpanFull(),
                        Infolists\Components\TextEntry::make('onedrive_path')
                            ->label('OneDrive Path')
                            ->columnSpanFull(),
                        Infolists\Components\TextEntry::make('status')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'pending' => 'warning',
                                'processing' => 'primary',
                                'completed' => 'success',
                                'failed' => 'danger',
                            }),
                        Infolists\Components\TextEntry::make('size')
                            ->formatStateUsing(fn (?string $state): string => 
                                $state ? number_format($state / 1024 / 1024, 2) . ' MB' : 'N/A'
                            ),
                        Infolists\Components\TextEntry::make('created_at')
                            ->dateTime(),
                        Infolists\Components\TextEntry::make('updated_at')
                            ->dateTime(),
                    ])->columns(2),
                Infolists\Components\Section::make('Error Details')
                    ->schema([
                        Infolists\Components\TextEntry::make('error_message')
                            ->columnSpanFull(),
                    ])
                    ->visible(fn (MailBackup $record): bool => $record->status === 'failed'),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMailBackups::route('/'),
            'view' => Pages\ViewMailBackup::route('/{record}'),
        ];
    }
}