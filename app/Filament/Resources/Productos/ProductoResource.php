<?php

declare(strict_types=1);

namespace App\Filament\Resources\Productos;

use App\Filament\Resources\Productos\Pages\ManageProductos;
use App\Filament\Schemas\Components\MontoField;
use App\Models\Producto;
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
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProductoResource extends Resource
{
    protected static ?string $model = Producto::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?string $recordTitleAttribute = 'nombre';

    protected static ?string $modelLabel = 'Producto';

    protected static ?string $pluralModelLabel = 'Productos';

    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): ?string
    {
        return 'Menú';
    }

    /** El catálogo no muestra combos especiales: se administran en su propia pantalla. */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('categoria', '!=', 'combo');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('nombre')
                ->label('Nombre')
                ->required()
                ->maxLength(255)
                ->placeholder('Ej: Pollo en teriyaki al horno'),

            Select::make('categoria')
                ->label('Categoría')
                ->required()
                ->live()
                ->options([
                    'proteina'    => 'Proteína',
                    'complemento' => 'Complemento',
                    'bebida'      => 'Bebida',
                    'extra'       => 'Extra',
                ]),

            Select::make('tier_combo')
                ->label('Nivel de precio')
                ->options(fn (): array => Tier::opciones())
                ->native(false)
                ->helperText('Define a qué reglas de precio aplica esta proteína. ¿Falta uno? Creálo en Menú → Niveles de Precio.')
                ->visible(fn ($get): bool => $get('categoria') === 'proteina')
                ->required(fn ($get): bool => $get('categoria') === 'proteina'),

            MontoField::make('precio', 'Precio individual'),

            Toggle::make('grava_isv')
                ->label('Grava ISV (15%)')
                ->default(true)
                ->onColor('success'),

            Toggle::make('activo')
                ->label('Activo en el menú')
                ->default(true)
                ->onColor('success'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('nombre')->label('Nombre')->searchable()->sortable()->weight('bold'),
                TextColumn::make('categoria')
                    ->label('Categoría')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->color(fn (string $state): string => match ($state) {
                        'proteina'    => 'danger',
                        'complemento' => 'success',
                        'bebida'      => 'info',
                        default       => 'gray',
                    }),
                TextColumn::make('tier_combo')->label('Nivel')->placeholder('—')->toggleable()
                    ->formatStateUsing(fn (?string $state): string => $state !== null ? (Tier::mapa()[$state] ?? $state) : '—'),
                TextColumn::make('precio')->label('Precio')->money('HNL')->sortable(),
                IconColumn::make('grava_isv')->label('ISV')->boolean(),
                ToggleColumn::make('activo')->label('Activo')->onColor('success'),
            ])
            ->filters([
                SelectFilter::make('categoria')->label('Categoría')->options([
                    'proteina'    => 'Proteína',
                    'complemento' => 'Complemento',
                    'bebida'      => 'Bebida',
                    'extra'       => 'Extra',
                ]),
                TernaryFilter::make('activo')->label('Activo'),
            ])
            ->defaultSort('categoria')
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageProductos::route('/'),
        ];
    }
}
