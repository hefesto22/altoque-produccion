<?php

declare(strict_types=1);

namespace App\Filament\Resources\EventoArticulos;

use App\Filament\Resources\EventoArticulos\Pages\ManageEventoArticulos;
use App\Filament\Schemas\Components\MontoField;
use App\Models\EventoArticulo;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/**
 * Catálogo de artículos SOLO para eventos (panas, cazuelas, paquetes…)
 * con precios personalizados, separado del menú del restaurante.
 * Se alimenta solo: cada ítem cotizado se guarda aquí con su último
 * precio; desde esta pantalla se corrigen precios o se depura la lista.
 */
class EventoArticuloResource extends Resource
{
    protected static ?string $model = EventoArticulo::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cube';

    protected static ?string $modelLabel = 'Artículo de eventos';

    protected static ?string $pluralModelLabel = 'Artículos de Eventos';

    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): ?string
    {
        return 'Eventos';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('nombre')->label('Nombre')->required()->maxLength(255)
                ->unique(ignoreRecord: true)
                ->dehydrateStateUsing(fn (string $state): string => mb_strtoupper(trim($state))),
            MontoField::make('precio', 'Precio para eventos')
                ->helperText('Precio personalizado de eventos — no es el del menú. Se actualiza solo con el último precio cotizado.'),
            Toggle::make('grava_isv')->label('Grava ISV')->default(true),
            Toggle::make('activo')->label('Activo')->default(true)
                ->helperText('Los inactivos no aparecen al armar cotizaciones.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('nombre')->label('Artículo')->searchable()->sortable()->weight('bold'),
                TextColumn::make('precio')->label('Precio eventos')->money('HNL')->sortable(),
                IconColumn::make('grava_isv')->label('ISV')->boolean(),
                IconColumn::make('activo')->label('Activo')->boolean(),
                TextColumn::make('updated_at')->label('Último uso')->date('d/m/Y')->sortable()->toggleable(),
            ])
            ->defaultSort('nombre')
            ->filters([
                TernaryFilter::make('activo')->label('Activo'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageEventoArticulos::route('/'),
        ];
    }
}
