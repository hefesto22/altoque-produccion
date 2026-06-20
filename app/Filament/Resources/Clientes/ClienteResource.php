<?php

declare(strict_types=1);

namespace App\Filament\Resources\Clientes;

use App\Filament\Resources\Clientes\Pages\ManageClientes;
use App\Filament\Schemas\Components\RTNField;
use App\Models\Cliente;
use App\Support\Acceso;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ClienteResource extends Resource
{
    protected static ?string $model = Cliente::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-identification';

    protected static ?string $modelLabel = 'Cliente';

    protected static ?string $pluralModelLabel = 'Clientes';

    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): ?string
    {
        return 'Facturación';
    }

    public static function canViewAny(): bool
    {
        return Acceso::tieneAlguno(['administrador', 'gerente', 'contador']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            RTNField::make('rtn', true),
            TextInput::make('nombre')->label('Nombre / Razón social')->required()
                ->dehydrateStateUsing(fn (string $state): string => mb_strtoupper(trim($state))),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('nombre')->label('Nombre')->searchable()->sortable()->weight('bold'),
                TextColumn::make('rtn')->label('RTN')->searchable()->copyable(),
                TextColumn::make('created_at')->label('Registrado')->date('d/m/Y')->toggleable(),
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
            'index' => ManageClientes::route('/'),
        ];
    }
}
