<?php

declare(strict_types=1);

namespace App\Filament\Resources\Tiers;

use App\Filament\Resources\Tiers\Pages\ManageTiers;
use App\Models\Tier;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;

/**
 * Niveles de precio (tiers). Agrupan proteínas que comparten precio de
 * combo. Crear un nivel nuevo (ej: "Pescado") permite asignarlo a una
 * proteína y definirle sus propias reglas de precio.
 */
class TierResource extends Resource
{
    protected static ?string $model = Tier::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-tag';

    protected static ?string $modelLabel = 'Nivel de precio';

    protected static ?string $pluralModelLabel = 'Niveles de Precio';

    protected static ?int $navigationSort = 4;

    public static function getNavigationGroup(): ?string
    {
        return 'Menú';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('nombre')
                ->label('Nombre del nivel')
                ->required()
                ->maxLength(60)
                ->placeholder('Ej: Pescado'),

            TextInput::make('codigo')
                ->label('Código interno')
                ->helperText('Se genera solo del nombre. No lo cambies si ya hay productos o reglas usándolo.')
                ->maxLength(40)
                ->dehydrateStateUsing(fn (?string $state): ?string => $state !== null && $state !== ''
                    ? (string) Str::of($state)->slug('_')
                    : null)
                ->disabled(fn (?Tier $record): bool => $record !== null), // no editable una vez creado

            TextInput::make('orden')
                ->label('Orden')
                ->numeric()->default(0)
                ->helperText('Posición en pantallas y listados.'),

            Toggle::make('activo')->label('Activo')->default(true)->onColor('success'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('nombre')->label('Nivel')->searchable()->weight('bold'),
                TextColumn::make('codigo')->label('Código')->color('gray'),
                TextColumn::make('orden')->label('Orden')->sortable(),
                IconColumn::make('activo')->label('Activo')->boolean(),
            ])
            ->defaultSort('orden')
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageTiers::route('/'),
        ];
    }
}
