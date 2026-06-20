<?php

declare(strict_types=1);

namespace App\Filament\Resources\Compras;

use App\Filament\Resources\Compras\Pages\ManageCompras;
use App\Filament\Schemas\Components\MontoField;
use App\Models\Compra;
use App\Support\Acceso;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CompraResource extends Resource
{
    protected static ?string $model = Compra::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-shopping-bag';

    protected static ?string $modelLabel = 'Compra';

    protected static ?string $pluralModelLabel = 'Compras';

    protected static ?int $navigationSort = 4;

    public static function getNavigationGroup(): ?string
    {
        return 'Fiscal';
    }

    public static function canViewAny(): bool
    {
        return Acceso::tieneAlguno(['administrador', 'gerente', 'contador']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Datos de la factura de compra')
                ->schema([
                    DatePicker::make('fecha')->label('Fecha de compra')->required()->native(false)->default(now()),
                    TextInput::make('numero_factura')->label('N° de factura')->required()->maxLength(50),
                    TextInput::make('proveedor_nombre')->label('Proveedor (empresa)')->required(),
                    TextInput::make('proveedor_rtn')->label('RTN del proveedor')->maxLength(14)->helperText('Necesario para acreditar el ISV.'),
                    Select::make('categoria')->label('Categoría')->required()->default('otros')->options([
                        'insumos'   => 'Insumos',
                        'empaques'  => 'Empaques / descartables',
                        'equipo'    => 'Equipo / utensilios',
                        'servicios' => 'Servicios',
                        'limpieza'  => 'Limpieza',
                        'otros'     => 'Otros',
                    ]),
                ])->columns(2),

            Section::make('Desglose')
                ->description('El ISV de la compra es el crédito fiscal que se resta del ISV de ventas.')
                ->schema([
                    MontoField::make('exento', 'Importe exento')->default(0),
                    MontoField::make('gravado', 'Importe gravado 15%')
                        ->live(onBlur: true)
                        // Autocalcula el ISV (15%) al escribir el gravado.
                        ->afterStateUpdated(fn ($state, $set, $get) => $set('isv', round((float) $state * 0.15, 2))),
                    MontoField::make('isv', 'ISV (crédito fiscal)')->helperText('15% del gravado. Ajustable si la factura difiere.'),
                    MontoField::make('total', 'Total de la factura'),
                ])->columns(2),

            TextInput::make('notas')->label('Notas')->maxLength(255)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('fecha')->label('Fecha')->date('d/m/Y')->sortable(),
                TextColumn::make('numero_factura')->label('Factura')->searchable(),
                TextColumn::make('proveedor_nombre')->label('Proveedor')->searchable()
                    ->description(fn (Compra $r): ?string => $r->proveedor_rtn),
                TextColumn::make('categoria')->label('Categoría')->badge()
                    ->formatStateUsing(fn (string $state): string => ucfirst($state)),
                TextColumn::make('gravado')->label('Gravado')->money('HNL')->toggleable(),
                TextColumn::make('isv')->label('ISV (crédito)')->money('HNL')->weight('bold')->color('success'),
                TextColumn::make('total')->label('Total')->money('HNL'),
            ])
            ->defaultSort('fecha', 'desc')
            ->filters([
                SelectFilter::make('categoria')->options([
                    'insumos'   => 'Insumos', 'empaques' => 'Empaques', 'equipo' => 'Equipo',
                    'servicios' => 'Servicios', 'limpieza' => 'Limpieza', 'otros' => 'Otros',
                ]),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageCompras::route('/'),
        ];
    }
}
