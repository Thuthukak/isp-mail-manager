<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ConfigurationGroupResource\Pages;
use App\Models\ConfigurationGroup;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ConfigurationGroupResource extends Resource
{
    protected static ?string $model = ConfigurationGroup::class;
    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';
    protected static ?string $navigationGroup = 'System Configurations';
    protected static ?int $navigationSort = 4;
    protected static ?string $navigationLabel = 'Configuration Groups';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make()
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true)
                            ->placeholder('e.g., onedrive, email, application'),
                        Forms\Components\TextInput::make('display_name')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('e.g., OneDrive Settings'),
                        Forms\Components\Textarea::make('description')
                            ->maxLength(1000)
                            ->placeholder('Brief description of this configuration group'),
                        Forms\Components\TextInput::make('icon')
                            ->placeholder('heroicon-o-cloud-arrow-up')
                            ->helperText('Heroicon name for navigation'),
                        Forms\Components\TextInput::make('sort_order')
                            ->numeric()
                            ->default(0)
                            ->helperText('Order in navigation (lower numbers first)'),
                        Forms\Components\Toggle::make('is_active')
                            ->default(true)
                            ->helperText('Whether this group is active'),
                    ])
                    ->columns(2)
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('display_name')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->badge()
                    ->color('gray'),
                Tables\Columns\TextColumn::make('description')
                    ->limit(50)
                    ->toggleable(),
                Tables\Columns\TextColumn::make('configurations_count')
                    ->counts('configurations')
                    ->label('Configs')
                    ->badge()
                    ->color('success'),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean(),
                Tables\Columns\TextColumn::make('sort_order')
                    ->sortable()
                    ->toggleable(),
            ])
            ->defaultSort('sort_order')
            ->actions([
                Tables\Actions\Action::make('manage_configs')
                    ->label('Manage Configs')
                    ->icon('heroicon-o-wrench-screwdriver')
                    ->url(fn ($record) => route('filament.admin.resources.configurations.index', ['group' => $record->id])),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListConfigurationGroups::route('/'),
            'create' => Pages\CreateConfigurationGroup::route('/create'),
            'edit' => Pages\EditConfigurationGroup::route('/{record}/edit'),
        ];
    }
}

