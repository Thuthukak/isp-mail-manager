<?php
// app/Filament/Resources/ConfigurationResource.php

namespace App\Filament\Resources;

use App\Filament\Resources\ConfigurationResource\Pages;
use App\Models\Configuration;
use App\Models\ConfigurationGroup;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ConfigurationResource extends Resource
{
    protected static ?string $model = Configuration::class;
    protected static ?string $navigationIcon = 'heroicon-o-adjustments-horizontal';
    protected static ?string $navigationGroup = 'System';
    protected static ?int $navigationSort = 2;
    protected static ?string $navigationLabel = 'Configurations';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Basic Information')
                    ->schema([
                        Forms\Components\Select::make('configuration_group_id')
                            ->label('Configuration Group')
                            ->options(ConfigurationGroup::where('is_active', true)->pluck('display_name', 'id'))
                            ->required()
                            ->searchable()
                            ->preload(),
                        Forms\Components\TextInput::make('key')
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true)
                            ->placeholder('e.g., APP_NAME, MAIL_HOST'),
                        Forms\Components\TextInput::make('display_name')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('e.g., Application Name'),
                        Forms\Components\Textarea::make('description')
                            ->maxLength(1000)
                            ->placeholder('Description of what this configuration does'),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Configuration Details')
                    ->schema([
                        Forms\Components\Select::make('type')
                            ->options([
                                'text' => 'Text',
                                'textarea' => 'Textarea',
                                'number' => 'Number',
                                'boolean' => 'Boolean',
                                'select' => 'Select',
                                'password' => 'Password',
                            ])
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn ($state, Forms\Set $set) => $state !== 'select' ? $set('options', null) : null),

                        Forms\Components\KeyValue::make('options')
                            ->label('Select Options')
                            ->visible(fn (Forms\Get $get) => $get('type') === 'select')
                            ->helperText('Key-value pairs for select dropdown options'),

                        Forms\Components\Grid::make()
                            ->schema([
                                Forms\Components\Toggle::make('is_required')
                                    ->label('Required')
                                    ->helperText('Is this configuration required?'),
                                Forms\Components\Toggle::make('is_encrypted')
                                    ->label('Encrypted')
                                    ->helperText('Should this value be encrypted in database?'),
                            ])
                            ->columns(2),

                        Forms\Components\TextInput::make('validation_rules')
                            ->placeholder('e.g., email, url, min:3')
                            ->helperText('Laravel validation rules'),

                        Forms\Components\TextInput::make('sort_order')
                            ->numeric()
                            ->default(0)
                            ->helperText('Display order within group'),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Value')
                    ->schema([
                        self::getValueField(),
                    ]),
            ]);
    }

    protected static function getValueField(): Forms\Components\Field
    {
        return Forms\Components\Grid::make()
            ->schema([
                Forms\Components\TextInput::make('value')
                    ->visible(fn (Forms\Get $get) => in_array($get('type'), ['text', 'number']))
                    ->type(fn (Forms\Get $get) => $get('type') === 'number' ? 'number' : 'text'),

                Forms\Components\Textarea::make('value')
                    ->visible(fn (Forms\Get $get) => $get('type') === 'textarea')
                    ->rows(3),

                Forms\Components\TextInput::make('value')
                    ->password()
                    ->revealable()
                    ->visible(fn (Forms\Get $get) => $get('type') === 'password'),

                Forms\Components\Toggle::make('value')
                    ->visible(fn (Forms\Get $get) => $get('type') === 'boolean'),

                Forms\Components\Select::make('value')
                    ->visible(fn (Forms\Get $get) => $get('type') === 'select')
                    ->options(function (Forms\Get $get) {
                        $options = $get('options');
                        return is_array($options) ? $options : [];
                    }),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function (Builder $query) {
                if (request()->has('group')) {
                    $query->where('configuration_group_id', request()->get('group'));
                }
                return $query->with('configurationGroup');
            })
            ->columns([
                Tables\Columns\TextColumn::make('configurationGroup.display_name')
                    ->label('Group')
                    ->badge()
                    ->color('primary')
                    ->sortable(),
                Tables\Columns\TextColumn::make('display_name')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('key')
                    ->searchable()
                    ->fontFamily('mono')
                    ->size('sm'),
                Tables\Columns\TextColumn::make('type')
                    ->badge()
                    ->color(fn ($state) => match($state) {
                        'password' => 'danger',
                        'boolean' => 'info',
                        'select' => 'warning',
                        default => 'gray'
                    }),
                Tables\Columns\TextColumn::make('value')
                    ->limit(30)
                    ->formatStateUsing(function ($state, $record) {
                        if ($record->type === 'password') {
                            return $state ? '••••••••' : 'Not set';
                        }
                        if ($record->type === 'boolean') {
                            return $state ? 'True' : 'False';
                        }
                        return $state;
                    })
                    ->tooltip(function ($record) {
                        if ($record->type === 'password' || !$record->value) {
                            return null;
                        }
                        return strlen($record->value) > 30 ? $record->value : null;
                    }),
                Tables\Columns\IconColumn::make('is_required')
                    ->boolean()
                    ->toggleable(),
                Tables\Columns\IconColumn::make('is_encrypted')
                    ->boolean()
                    ->toggleable(),
            ])
            ->defaultSort('sort_order')
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->headerActions([
                Tables\Actions\Action::make('back_to_groups')
                    ->label('Back to Groups')
                    ->icon('heroicon-o-arrow-left')
                    ->url(route('filament.admin.resources.configuration-groups.index'))
                    ->visible(fn () => request()->has('group')),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('configuration_group_id')
                    ->label('Group')
                    ->options(ConfigurationGroup::where('is_active', true)->pluck('display_name', 'id'))
                    ->searchable()
                    ->preload(),
                Tables\Filters\SelectFilter::make('type')
                    ->options([
                        'text' => 'Text',
                        'textarea' => 'Textarea',
                        'number' => 'Number',
                        'boolean' => 'Boolean',
                        'select' => 'Select',
                        'password' => 'Password',
                    ]),
                Tables\Filters\TernaryFilter::make('is_required'),
                Tables\Filters\TernaryFilter::make('is_encrypted'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListConfigurations::route('/'),
            'create' => Pages\CreateConfiguration::route('/create'),
            'edit' => Pages\EditConfiguration::route('/{record}/edit'),
        ];
    }
}