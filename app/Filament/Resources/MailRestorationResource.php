<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MailRestorationResource\Pages;
use App\Models\MailRestoration;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Infolists;
use Filament\Infolists\Infolist;

class MailRestorationResource extends Resource
{
    protected static ?string $model = MailRestoration::class;
    protected static ?string $navigationIcon = 'heroicon-o-arrow-down-on-square';
    protected static ?string $navigationGroup = 'Mail Management';
    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('mail_path')
                    ->required()
                    ->maxLength(500)
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('restored_from')
                    ->required()
                    ->maxLength(500)
                    ->columnSpanFull(),
                Forms\Components\Select::make('status')
                    ->options([
                        'requested' => 'Requested',
                        'processing' => 'Processing',
                        'completed' => 'Completed',
                        'failed' => 'Failed',
                    ])
                    ->required(),
                Forms\Components\DateTimePicker::make('requested_at')
                    ->default(now()),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('mail_path')
                    ->searchable()
                    ->limit(50),
                Tables\Columns\BadgeColumn::make('status')
                    ->colors([
                        'info' => 'requested',
                        'primary' => 'processing',
                        'success' => 'completed',
                        'danger' => 'failed',
                    ]),
                Tables\Columns\TextColumn::make('requested_at')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('completed_at')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('Not completed'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'requested' => 'Requested',
                        'processing' => 'Processing',
                        'completed' => 'Completed',
                        'failed' => 'Failed',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('process')
                    ->icon('heroicon-m-play')
                    ->color('primary')
                    ->visible(fn (MailRestoration $record): bool => $record->status === 'requested')
                    ->action(function (MailRestoration $record) {
                        $record->update(['status' => 'processing']);
                        // RestoreMailsJob::dispatch($record);
                    }),
            ])
            ->defaultSort('requested_at', 'desc');
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Restoration Information')
                    ->schema([
                        Infolists\Components\TextEntry::make('mail_path')
                            ->label('Mail Path')
                            ->columnSpanFull(),
                        Infolists\Components\TextEntry::make('restored_from')
                            ->label('Restored From')
                            ->columnSpanFull(),
                        Infolists\Components\TextEntry::make('status')
                            ->badge(),
                        Infolists\Components\TextEntry::make('requested_at')
                            ->dateTime(),
                        Infolists\Components\TextEntry::make('completed_at')
                            ->dateTime()
                            ->placeholder('Not completed'),
                    ])->columns(2),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMailRestorations::route('/'),
            'create' => Pages\CreateMailRestoration::route('/create'),
            'view' => Pages\ViewMailRestoration::route('/{record}'),
        ];
    }
}