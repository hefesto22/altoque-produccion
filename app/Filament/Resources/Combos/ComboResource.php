<?php

declare(strict_types=1);

namespace App\Filament\Resources\Combos;

use App\Filament\Resources\Combos\Pages\ManageCombos;
use App\Filament\Schemas\Components\MontoField;
use App\Models\Combo;
use App\Models\Tier;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ComboResource extends Resource
{
    protected static ?string $model = Combo::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $modelLabel = 'Regla de precio';

    protected static ?string $pluralModelLabel = 'Reglas de Precio';

    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): ?string
    {
        return 'Menú';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('tier')
                ->label('Nivel de precio')
                ->required()
                ->native(false)
                ->options(fn (): array => Tier::opciones())
                ->helperText('¿Falta un nivel? Creálo en Menú → Niveles de Precio.'),

            TextInput::make('complementos')
                ->label('Cantidad de complementos')
                ->required()
                ->numeric()
                ->minValue(0)
                ->step(1)
                ->helperText('Nº de complementos que incluye el combo (ej: 2 ó 3).'),

            MontoField::make('precio', 'Precio del combo'),

            Toggle::make('activo')->label('Activo')->default(true)->onColor('success'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('tier')
                    ->label('Nivel')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Tier::mapa()[$state] ?? $state),
                TextColumn::make('complementos')->label('Complementos')->sortable(),
                TextColumn::make('precio')->label('Precio')->money('HNL')->sortable(),
                IconColumn::make('activo')->label('Activo')->boolean(),
            ])
            ->defaultSort('tier')
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageCombos::route('/'),
        ];
    }
}
