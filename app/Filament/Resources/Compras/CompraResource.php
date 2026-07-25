<?php

declare(strict_types=1);

namespace App\Filament\Resources\Compras;

use App\Filament\Resources\Compras\Pages\ManageCompras;
use App\Filament\Schemas\Components\MontoField;
use App\Models\Compra;
use App\Models\Proveedor;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

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

    /** ¿La compra del formulario es factura (y por tanto descuenta ISV)? */
    private static function esFactura(Get $get): bool
    {
        return $get('tipo_documento') !== 'recibo';
    }

    /**
     * Compra ya registrada con el MISMO número y el MISMO proveedor.
     * Sirve para avisar antes de guardar dos veces la misma factura, que
     * duplicaría el crédito fiscal (lo primero que observa una auditoría).
     */
    private static function facturaDuplicada(Get $get, ?Compra $record): ?Compra
    {
        $numero = trim((string) $get('numero_factura'));
        $proveedor = mb_strtoupper(trim((string) $get('proveedor_nombre')));

        if ($numero === '' || $proveedor === '') {
            return null;
        }

        $query = Compra::query()
            ->where('numero_factura', $numero)
            ->whereRaw('upper(trim(proveedor_nombre)) = ?', [$proveedor]);

        // Al editar, la propia compra no cuenta como duplicada.
        if ($record !== null) {
            $query->whereKeyNot($record->getKey());
        }

        return $query->first();
    }

    /** Total = exento + gravado + ISV. Se recalcula al tocar cualquiera. */
    private static function recalcularTotal(Get $get, Set $set): void
    {
        $n = static fn ($v): float => is_numeric($v) ? (float) $v : 0.0;

        $set('total', round($n($get('exento')) + $n($get('gravado')) + $n($get('isv')), 2));
    }

    public static function form(Schema $schema): Schema
    {
        // Una sola columna de secciones: cada paso ocupa el ancho completo del
        // modal y acomoda sus campos adentro. Con secciones lado a lado (lo
        // anterior) la más corta dejaba un hueco enorme al lado.
        return $schema->columns(1)->components([

            // ── Paso 1: qué documento entregó el proveedor ──────────────
            Section::make('1 · ¿Qué te dio el proveedor?')
                ->description('De esto depende si el ISV se puede descontar o no.')
                ->schema([
                    ToggleButtons::make('tipo_documento')
                        ->hiddenLabel()
                        ->required()
                        ->default('factura')
                        ->inline()
                        ->live()
                        ->options([
                            'factura' => 'Factura',
                            'recibo'  => 'Recibo de compra',
                        ])
                        ->icons([
                            'factura' => 'heroicon-o-document-text',
                            'recibo'  => 'heroicon-o-receipt-percent',
                        ])
                        ->colors([
                            'factura' => 'success',
                            'recibo'  => 'warning',
                        ]),

                    // Al lado de los botones, no debajo: aprovecha el ancho.
                    Placeholder::make('explicacion_tipo')
                        ->hiddenLabel()
                        ->content(fn (Get $get): HtmlString => new HtmlString(
                            self::esFactura($get)
                                ? '<span style="font-size:.85rem;">Factura del proveedor: su <strong>ISV se descuenta</strong> del ISV de tus ventas y entra al Libro de Compras del SAR.</span>'
                                : '<span style="font-size:.85rem; color:#d97706;">Compra sin factura: queda registrada como gasto, pero <strong>no descuenta ISV</strong> ni entra al Libro de Compras. Solo se te pide el total.</span>'
                        )),
                ])->columns(2),

            // ── Paso 2: de quién y cuándo ───────────────────────────────
            Section::make('2 · Proveedor')
                ->schema([
                    DatePicker::make('fecha')
                        ->label('Fecha de compra')
                        ->required()
                        ->native(false)
                        ->default(now())
                        ->maxDate(now()),

                    // Doble ancho: un correlativo del SAR (000-001-01-00000657)
                    // no entra cómodo en una columna.
                    TextInput::make('numero_factura')
                        ->label(fn (Get $get): string => self::esFactura($get) ? 'N° de factura' : 'N° de recibo (si tiene)')
                        ->required(fn (Get $get): bool => self::esFactura($get))
                        ->maxLength(50)
                        ->columnSpan(2)
                        ->live(onBlur: true)
                        ->placeholder('000-001-01-00000657'),

                    Select::make('categoria')
                        ->label('¿En qué se gastó?')
                        ->required()
                        ->default('otros')
                        ->native(false)
                        ->options([
                            'insumos'   => 'Insumos',
                            'empaques'  => 'Empaques / descartables',
                            'equipo'    => 'Equipo / utensilios',
                            'servicios' => 'Servicios',
                            'limpieza'  => 'Limpieza',
                            'otros'     => 'Otros',
                        ]),

                    // Autocompletado: al escribir el nombre de un proveedor ya
                    // registrado, su RTN se llena solo. El catálogo se alimenta
                    // al guardar cada compra (ver Compra::booted).
                    TextInput::make('proveedor_nombre')
                        ->label('Proveedor (empresa)')
                        ->required()
                        ->maxLength(255)
                        ->columnSpan(2)
                        ->datalist(fn (): array => Proveedor::nombres())
                        ->dehydrateStateUsing(fn (?string $state): string => mb_strtoupper(trim((string) $state)))
                        ->live(onBlur: true)
                        ->afterStateUpdated(function (?string $state, Set $set): void {
                            $rtn = Proveedor::rtnDe($state);

                            if ($rtn !== null && $rtn !== '') {
                                $set('proveedor_rtn', $rtn);
                            }
                        })
                        ->helperText('Escribí las primeras letras: si ya lo registraste antes, aparece en la lista y su RTN se llena solo.'),

                    TextInput::make('proveedor_rtn')
                        ->label('RTN del proveedor')
                        ->maxLength(14)
                        ->columnSpan(2)
                        ->live(onBlur: true)
                        ->helperText(fn (Get $get): string => self::esFactura($get)
                            ? 'Necesario para que el SAR acepte el descuento del ISV.'
                            : 'Opcional en un recibo.'),

                    // Aviso (no bloquea): registrar dos veces la misma factura
                    // descontaría el ISV por duplicado.
                    Placeholder::make('aviso_duplicada')
                        ->hiddenLabel()
                        ->columnSpanFull()
                        ->visible(fn (Get $get, ?Compra $record): bool => self::facturaDuplicada($get, $record) !== null)
                        ->content(function (Get $get, ?Compra $record): HtmlString {
                            $previa = self::facturaDuplicada($get, $record);

                            if ($previa === null) {
                                return new HtmlString('');
                            }

                            return new HtmlString(
                                '<span style="font-size:.85rem; color:#d97706;">⚠ Ese número ya está registrado para este proveedor el <strong>'
                                .$previa->fecha->format('d/m/Y')
                                .'</strong> por <strong>L. '.number_format((float) $previa->total, 2)
                                .'</strong>. Si es la misma compra no la guardes otra vez: descontarías el ISV dos veces.</span>'
                            );
                        }),
                ])->columns(4),

            // ── Paso 3: montos ──────────────────────────────────────────
            Section::make('3 · Montos')
                ->description(fn (Get $get): string => self::esFactura($get)
                    ? 'Copiá los montos de la factura: al escribir el gravado, el ISV y el total se calculan solos.'
                    : 'Solo el total de lo que pagaste.')
                ->schema([
                    MontoField::make('gravado', 'Importe gravado 15%')
                        ->visible(fn (Get $get): bool => self::esFactura($get))
                        ->live(onBlur: true)
                        ->helperText('Lo que paga impuesto.')
                        ->afterStateUpdated(function ($state, Get $get, Set $set): void {
                            // El ISV se sugiere al 15%; queda editable por si la
                            // factura del proveedor redondea distinto.
                            $set('isv', round((is_numeric($state) ? (float) $state : 0.0) * 0.15, 2));
                            self::recalcularTotal($get, $set);
                        }),

                    MontoField::make('exento', 'Importe exento')
                        ->visible(fn (Get $get): bool => self::esFactura($get))
                        ->live(onBlur: true)
                        ->helperText('Lo que no paga impuesto.')
                        ->afterStateUpdated(fn (Get $get, Set $set) => self::recalcularTotal($get, $set)),

                    MontoField::make('isv', 'ISV que se descuenta')
                        ->visible(fn (Get $get): bool => self::esFactura($get))
                        ->live(onBlur: true)
                        ->helperText('15% del gravado. Ajustalo si difiere.')
                        ->afterStateUpdated(fn (Get $get, Set $set) => self::recalcularTotal($get, $set)),

                    // En un recibo es el único campo: ocupa media fila para que
                    // no quede un cuadrito suelto contra el borde.
                    MontoField::make('total', 'Total pagado')
                        ->columnSpan(fn (Get $get): int => self::esFactura($get) ? 1 : 2)
                        ->helperText(fn (Get $get): ?string => self::esFactura($get)
                            ? 'Debe cuadrar con la factura.'
                            : null),

                    // Aviso sin bloquear: una factura con ISV pero sin RTN del
                    // proveedor es un crédito que el SAR puede rechazar.
                    Placeholder::make('aviso_rtn')
                        ->hiddenLabel()
                        ->columnSpanFull()
                        ->visible(fn (Get $get): bool => self::esFactura($get)
                            && is_numeric($get('isv')) && (float) $get('isv') > 0
                            && trim((string) $get('proveedor_rtn')) === '')
                        ->content(new HtmlString(
                            '<span style="font-size:.85rem; color:#d97706;">⚠ Estás descontando ISV sin el RTN del proveedor. Si el SAR audita, puede rechazar ese crédito.</span>'
                        )),

                    TextInput::make('notas')
                        ->label('Notas (opcional)')
                        ->maxLength(255)
                        ->columnSpanFull(),
                ])->columns(4),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('fecha')->label('Fecha')->date('d/m/Y')->sortable(),
                TextColumn::make('tipo_documento')->label('Tipo')->badge()
                    ->formatStateUsing(fn (Compra $record): string => $record->tipoLabel())
                    ->color(fn (Compra $record): string => $record->esFactura() ? 'success' : 'warning'),
                TextColumn::make('numero_factura')->label('N° documento')->searchable()->placeholder('—'),
                TextColumn::make('proveedor_nombre')->label('Proveedor')->searchable()
                    ->description(fn (Compra $record): ?string => $record->proveedor_rtn),
                TextColumn::make('categoria')->label('Categoría')->badge()
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->toggleable(),
                TextColumn::make('gravado')->label('Gravado')->money('HNL')->toggleable(isToggledHiddenByDefault: true),
                // En un recibo se muestra un guion, no "L. 0.00": se lee como
                // "no aplica" y no como un dato en cero.
                TextColumn::make('isv')->label('ISV que descuenta')
                    ->money('HNL')->weight('bold')->color('success')
                    ->state(fn (Compra $record): ?float => $record->esFactura() ? (float) $record->isv : null)
                    ->placeholder('—'),
                TextColumn::make('total')->label('Total')->money('HNL'),
            ])
            ->defaultSort('fecha', 'desc')
            ->filters([
                SelectFilter::make('tipo_documento')->label('Tipo de documento')->options([
                    'factura' => 'Facturas (descuentan ISV)',
                    'recibo'  => 'Recibos (no descuentan)',
                ]),
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
