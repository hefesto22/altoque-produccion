<?php

declare(strict_types=1);

namespace App\Filament\Resources\PeriodosFiscales;

use App\Filament\Resources\PeriodosFiscales\Pages\ListPeriodosFiscales;
use App\Models\PeriodoFiscal;
use App\Support\Acceso;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;

class PeriodoFiscalResource extends Resource
{
    protected static ?string $model = PeriodoFiscal::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $modelLabel = 'Declaración ISV';

    protected static ?string $pluralModelLabel = 'Declaraciones ISV';

    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): ?string
    {
        return 'Fiscal';
    }

    public static function canViewAny(): bool
    {
        return Acceso::tieneAlguno(['administrador', 'gerente', 'contador']);
    }

    /** Los períodos se crean/cierran desde la página de Declaración ISV. */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('periodo')->label('Período')
                    ->state(fn (PeriodoFiscal $r): string => Carbon::create($r->anio, $r->mes, 1)->translatedFormat('F Y'))
                    ->sortable(['anio', 'mes']),
                TextColumn::make('estado')->label('Estado')->badge()
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->color(fn (string $state): string => $state === 'declarado' ? 'success' : 'gray'),
                TextColumn::make('isv')->label('ISV declarado')->money('HNL'),
                TextColumn::make('total')->label('Total ventas')->money('HNL'),
                TextColumn::make('cantidad_ventas')->label('Ventas')->alignCenter(),
                TextColumn::make('declarante.name')->label('Declarado por')->placeholder('—'),
                TextColumn::make('declarado_at')->label('Declarado el')->dateTime('d/m/Y H:i')->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('estado')->options(['abierto' => 'Abierto', 'declarado' => 'Declarado']),
            ])
            ->defaultSort('anio', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPeriodosFiscales::route('/'),
        ];
    }
}
