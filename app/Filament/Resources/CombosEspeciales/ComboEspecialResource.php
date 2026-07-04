<?php

declare(strict_types=1);

namespace App\Filament\Resources\CombosEspeciales;

use App\Filament\Resources\CombosEspeciales\Pages\ManageComboEspeciales;
use App\Filament\Schemas\Components\MontoField;
use App\Models\ComboEspecial;
use App\Models\Producto;
use App\Models\Tier;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Combos promocionales con nombre (ej: "Combo Familiar"). Se venden de un
 * toque en el POS a precio fijo. Distintos de los combos-regla (precio por
 * tier + nº de complementos), que viven en ComboResource.
 */
class ComboEspecialResource extends Resource
{
    protected static ?string $model = ComboEspecial::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-gift';

    protected static ?string $modelLabel = 'Platillo completo';

    protected static ?string $pluralModelLabel = 'Platillos Completos';

    protected static ?int $navigationSort = 3;

    public static function getNavigationGroup(): ?string
    {
        return 'Menú';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['proteinaCombo:id,nombre', 'items.producto:id,nombre']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            // Identidad: nombre ancho + precio fijo destacado.
            Grid::make(3)->schema([
                TextInput::make('nombre')
                    ->label('Nombre del platillo')
                    ->required()->maxLength(120)
                    ->placeholder('Ej: Desayuno típico')
                    ->columnSpan(2),

                MontoField::make('precio', 'Precio fijo')->columnSpan(1),
            ])->columnSpanFull(),

            // Cómo se compone el platillo.
            Select::make('combo_modo')
                ->label('Tipo de platillo')
                ->options([
                    'cantidades' => 'El cliente elige (cantidades) — estilo buffet',
                    'platillo'   => 'Platillo armado (productos fijos) — desayuno / cena',
                ])
                ->default('cantidades')
                ->selectablePlaceholder(false)
                ->native(false)
                ->live()
                ->columnSpanFull()
                ->helperText('“Cantidades”: el cliente elige sus complementos. “Platillo armado”: lleva productos fijos que vos definís.'),

            // Modo cantidades (buffet).
            Section::make('¿Qué incluye?')
                ->icon('heroicon-o-clipboard-document-list')
                ->description('El cliente elige; se describe por cantidades. Se muestra desglosado en pantalla, ticket y cocina.')
                ->visible(fn ($get): bool => ($get('combo_modo') ?? 'cantidades') === 'cantidades')
                ->columnSpanFull()
                ->columns(2)
                ->schema(self::camposCantidades()),

            // Modo platillo armado: tildá los productos del catálogo que lleva.
            Section::make('Productos del platillo')
                ->icon('heroicon-o-squares-2x2')
                ->description('Tildá los productos que lleva el platillo (del catálogo). La cocina los recibe tal cual.')
                ->visible(fn ($get): bool => $get('combo_modo') === 'platillo')
                ->columnSpanFull()
                ->schema([
                    CheckboxList::make('productosIncluidos')
                        // Carga y guardado por el camino estándar de la relación.
                        // OJO: nada de modifyQueryUsing aquí — Filament aplica ese
                        // closure también a las queries de fill/save del BelongsToMany;
                        // un addSelect ahí corrompe el select (Eloquent deja de agregar
                        // productos.* y el pivote) y el modal de edición revienta con
                        // las casillas vacías.
                        ->label('Productos incluidos')
                        ->relationship(name: 'productosIncluidos', titleAttribute: 'nombre')
                        // Las opciones se arman aparte con query directa: filtro, orden
                        // lógico por categoría (no alfabético) y prefijo visual, sin
                        // pelear con el SELECT DISTINCT que Filament arma en Postgres.
                        ->options(fn (): array => Producto::query()
                            ->where('categoria', '!=', 'combo')
                            ->where('activo', true)
                            ->orderByRaw("case categoria when 'proteina' then 1 when 'complemento' then 2 when 'bebida' then 3 when 'extra' then 4 else 5 end")
                            ->orderBy('nombre')
                            ->get(['id', 'nombre', 'categoria'])
                            ->mapWithKeys(fn (Producto $p): array => [$p->id => match ($p->categoria) {
                                'proteina'    => '🍖 '.$p->nombre,
                                'complemento' => '🥗 '.$p->nombre,
                                'bebida'      => '🥤 '.$p->nombre,
                                'extra'       => '➕ '.$p->nombre,
                                default       => $p->nombre,
                            }])
                            ->all())
                        ->searchable()
                        ->bulkToggleable()
                        ->columns(3)
                        ->gridDirection('column'),
                ]),

            Textarea::make('descripcion')
                ->label('Nota adicional (opcional)')
                ->rows(2)->maxLength(255)
                ->placeholder('Ej: válido solo los viernes')
                ->columnSpanFull(),

            Grid::make(2)->schema(self::camposOpciones())->columnSpanFull(),
        ]);
    }

    /**
     * Campos de la composición por cantidades (modo buffet).
     *
     * @return array<int, Field>
     */
    public static function camposCantidades(): array
    {
        return [
            Select::make('combo_tier_carne')
                ->label('Tipo de carne')
                ->options(fn (): array => ['cualquiera' => 'Carne a elección'] + Tier::opciones())
                ->default('cualquiera')
                ->selectablePlaceholder(false)
                ->prefixIcon('heroicon-o-fire')
                ->native(false)
                ->helperText('Elegí "Carne a elección" si el cliente decide, o un tipo (Pescado, Res…) si el combo es de esa carne.'),

            Select::make('combo_proteina_id')
                ->label('Carne específica (opcional)')
                ->options(fn (): array => Producto::query()
                    ->where('categoria', 'proteina')
                    ->orderBy('nombre')
                    ->pluck('nombre', 'id')
                    ->all())
                ->searchable()
                ->native(false)
                ->placeholder('Sin carne específica')
                ->helperText('Solo si el combo es de un plato puntual (ej: Pollo en teriyaki). Reemplaza al tipo. Si no, dejala vacía.'),

            TextInput::make('combo_num_complementos')
                ->label('Complementos incluidos')
                ->numeric()->minValue(0)->maxValue(20)->default(0)->required()
                ->prefixIcon('heroicon-o-squares-2x2')
                ->suffix('compl.'),

            TextInput::make('combo_num_bebidas')
                ->label('Frescos / bebidas incluidos')
                ->numeric()->minValue(0)->maxValue(20)->default(0)->required()
                ->prefixIcon('heroicon-o-beaker')
                ->suffix('bebida(s)')
                ->helperText('0 = sin bebida.'),
        ];
    }

    /**
     * Toggles de ISV y disponibilidad.
     *
     * @return array<int, Field>
     */
    public static function camposOpciones(): array
    {
        return [
            Toggle::make('grava_isv')
                ->label('Grava ISV (15%)')
                ->default(true)->onColor('success')->inline(false)
                ->helperText('La comida grava ISV. Apagalo solo si este combo es exento.'),

            Toggle::make('activo')
                ->label('Activo en el menú')
                ->default(true)->onColor('success')->inline(false)
                ->helperText('Si lo apagás, no aparece en el POS ni en la pantalla.'),
        ];
    }

    /**
     * Formulario reducido (modo cantidades) para el botón rápido "Nuevo
     * combo especial" de la pantalla de Reglas de Precio. Los platillos
     * armados se crean desde esta pantalla (Combos Especiales).
     *
     * @return array<int, Component>
     */
    public static function camposFormulario(): array
    {
        return [
            Grid::make(3)->schema([
                TextInput::make('nombre')->label('Nombre del combo')->required()->maxLength(120)
                    ->placeholder('Ej: Combo Familiar')->columnSpan(2),
                MontoField::make('precio', 'Precio fijo')->columnSpan(1),
            ])->columnSpanFull(),

            Section::make('¿Qué incluye?')
                ->icon('heroicon-o-clipboard-document-list')
                ->description('Se muestra desglosado en la pantalla, el ticket y la comanda de cocina.')
                ->columnSpanFull()->columns(2)
                ->schema(self::camposCantidades()),

            Textarea::make('descripcion')->label('Nota adicional (opcional)')
                ->rows(2)->maxLength(255)->placeholder('Ej: válido solo los viernes')->columnSpanFull(),

            Grid::make(2)->schema(self::camposOpciones())->columnSpanFull(),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('nombre')->label('Nombre')->searchable()->weight('bold'),
                TextColumn::make('incluye')
                    ->label('Incluye')
                    ->state(fn (ComboEspecial $record): string => $record->desglose($record->proteinaCombo?->nombre))
                    ->color('gray'),
                TextColumn::make('precio')->label('Precio')->money('HNL')->sortable(),
                IconColumn::make('grava_isv')->label('ISV')->boolean(),
                IconColumn::make('activo')->label('Activo')->boolean(),
            ])
            ->defaultSort('nombre')
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageComboEspeciales::route('/'),
        ];
    }
}
