<?php

declare(strict_types=1);

namespace App\Filament\Resources\CorteCajas;

use App\Filament\Resources\CorteCajas\Pages\ListCorteCajas;
use App\Filament\Schemas\Components\MontoField;
use App\Models\CorteCaja;
use App\Support\Acceso;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

class CorteCajaResource extends Resource
{
    protected static ?string $model = CorteCaja::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-calculator';

    protected static ?string $modelLabel = 'Corte de caja';

    protected static ?string $pluralModelLabel = 'Cortes de Caja';

    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): ?string
    {
        return 'Caja';
    }

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
                TextColumn::make('abierto_at')->label('Abierto')->dateTime('d/m/Y h:i A')->sortable(),
                TextColumn::make('cajero.name')->label('Cajero')->placeholder('—'),
                TextColumn::make('estado')->label('Estado')->badge()
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->color(fn (string $state): string => $state === 'abierto' ? 'warning' : 'gray'),
                TextColumn::make('cantidad_ventas')->label('Ventas')->alignCenter(),
                TextColumn::make('total_ventas')->label('Total')->money('HNL'),
                TextColumn::make('total_efectivo')->label('Efectivo')->money('HNL')->toggleable(),
                TextColumn::make('efectivo_contado')->label('Contado')->money('HNL')->placeholder('—'),
                TextColumn::make('diferencia')->label('Diferencia')
                    ->money('HNL')->placeholder('—')
                    ->color(fn ($state): string => $state === null ? 'gray' : ((float) $state === 0.0 ? 'success' : 'danger'))
                    ->weight('bold'),
            ])
            ->defaultSort('abierto_at', 'desc')
            ->filters([
                SelectFilter::make('estado')->options(['abierto' => 'Abierto', 'cerrado' => 'Cerrado']),
            ])
            ->recordActions([
                Action::make('ver')
                    ->label('Ver')
                    ->icon('heroicon-o-eye')
                    ->modalHeading(fn (CorteCaja $record): string => 'Corte del '.$record->abierto_at->format('d/m/Y h:i A'))
                    ->modalContent(fn (CorteCaja $record) => view('filament.corte-detalle', ['corte' => $record]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Cerrar'),
                Action::make('corregir')
                    ->label('Corregir')
                    ->icon('heroicon-o-pencil-square')
                    ->color('warning')
                    ->visible(fn (CorteCaja $record): bool => $record->estado === 'cerrado' && Acceso::puede('Update:CorteCaja'))
                    ->modalHeading('Corregir corte de caja')
                    ->fillForm(fn (CorteCaja $record): array => [
                        'efectivo_contado' => $record->efectivo_contado,
                        'notas'            => $record->notas,
                    ])
                    ->schema([
                        Section::make('Cuadre del turno')
                            ->schema([
                                Placeholder::make('p_fondo')->label('Fondo inicial')
                                    ->content(fn (CorteCaja $record): string => 'L. '.number_format((float) $record->fondo_inicial, 2)),
                                Placeholder::make('p_efectivo')->label('Efectivo de ventas')
                                    ->content(fn (CorteCaja $record): string => 'L. '.number_format((float) $record->total_efectivo, 2)),
                                Placeholder::make('p_esperado')->label('Efectivo esperado en caja')
                                    ->content(fn (CorteCaja $record): string => 'L. '.number_format($record->efectivoEsperado(), 2)),
                                Placeholder::make('p_contado')->label('Contado registrado')
                                    ->content(fn (CorteCaja $record): string => 'L. '.number_format((float) $record->efectivo_contado, 2)),
                            ])->columns(2),
                        MontoField::make('efectivo_contado', 'Efectivo contado (corregido)')->live(onBlur: true),
                        Placeholder::make('p_nueva_dif')
                            ->label('Nueva diferencia')
                            ->content(function (CorteCaja $record, $get): HtmlString {
                                $dif = round((float) ($get('efectivo_contado') ?? 0) - $record->efectivoEsperado(), 2);
                                $color = $dif === 0.0 ? '#10b981' : '#ef4444';
                                $txt = $dif === 0.0 ? 'Cuadra' : ($dif > 0 ? 'Sobrante' : 'Faltante');

                                return new HtmlString('<span style="color:'.$color.'; font-weight:700; font-size:1.1rem;">L. '.number_format($dif, 2).' · '.$txt.'</span>');
                            }),
                        Textarea::make('notas')->label('Motivo / nota de la corrección')->required()->maxLength(255),
                    ])
                    ->action(function (CorteCaja $record, array $data): void {
                        $contado = (float) $data['efectivo_contado'];
                        $esperado = $record->efectivoEsperado();

                        $record->update([
                            'efectivo_contado' => $contado,
                            'diferencia'       => round($contado - $esperado, 2),
                            'notas'            => $data['notas'] ?? $record->notas,
                        ]);

                        activity()->performedOn($record)->log('corte_corregido');

                        Notification::make()->title('Corte corregido')->body('Diferencia recalculada.')->success()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCorteCajas::route('/'),
        ];
    }
}
