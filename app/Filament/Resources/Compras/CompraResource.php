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

    private static function num(mixed $valor): float
    {
        return is_numeric($valor) ? (float) $valor : 0.0;
    }

    /**
     * El total se arma de lo que escribe el contador, no al revés.
     *
     * Pedido del contador (2026-08-17): quiere copiar la factura del proveedor
     * tal cual —gravado, ISV y exento por separado— en vez de escribir el
     * gravado con el impuesto adentro y que el sistema derive el ISV. Son
     * exactamente las columnas que la base ya guardaba, así que lo de antes
     * era una vuelta de mas.
     */
    private static function recalcularTotal(Get $get, Set $set): void
    {
        $set('total', round(
            self::num($get('gravado')) + self::num($get('isv')) + self::num($get('exento')),
            2,
        ));
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
                    // Campo de fecha NATIVO del navegador, a propósito: el
                    // picker de Filament obliga a navegar mes/año con el mouse
                    // y el contador carga decenas de facturas de golpe. El
                    // nativo se teclea de corrido (17 08 2026) sin soltar el
                    // teclado. Y sin default: con "hoy" puesto de entrada se le
                    // colaban facturas mal fechadas.
                    DatePicker::make('fecha')
                        ->label('Fecha de compra')
                        ->required()
                        ->native()
                        ->helperText('Escribila con el teclado: día, mes y año.')
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
                    ? 'Copiá los montos tal como vienen en la factura del proveedor, cada uno en su casilla. El total se suma solo.'
                    : 'Solo el total de lo que pagaste.')
                ->schema([
                    // Los tres montos van tal cual vienen en la factura del
                    // proveedor y son las columnas reales de la base. Sin
                    // `default(0)`: el contador pidió que las casillas nazcan
                    // vacías, porque el cero puesto de entrada se quedaba
                    // pegado y terminaba guardando montos en cero.
                    MontoField::make('gravado', 'Gravado 15%')
                        ->visible(fn (Get $get): bool => self::esFactura($get))
                        ->default(null)
                        ->live(onBlur: true)
                        ->rules(['nullable'])
                        ->dehydrateStateUsing(fn (mixed $state): float => self::num($state))
                        ->helperText('El importe gravado SIN el impuesto, como lo separa la factura.')
                        ->afterStateUpdated(fn (Get $get, Set $set) => self::recalcularTotal($get, $set)),

                    MontoField::make('isv', 'I.S.V. 15%')
                        ->visible(fn (Get $get): bool => self::esFactura($get))
                        ->default(null)
                        ->required(false)
                        ->rules(['nullable'])
                        ->live(onBlur: true)
                        ->dehydrateStateUsing(fn (mixed $state): float => self::num($state))
                        ->helperText('El impuesto tal como lo dice la factura. Es el que se descuenta.')
                        ->afterStateUpdated(fn (Get $get, Set $set) => self::recalcularTotal($get, $set)),

                    MontoField::make('exento', 'Exento')
                        ->visible(fn (Get $get): bool => self::esFactura($get))
                        ->default(null)
                        ->required(false)
                        ->rules(['nullable'])
                        ->live(onBlur: true)
                        ->dehydrateStateUsing(fn (mixed $state): float => self::num($state))
                        ->helperText('Lo que no paga impuesto (si la factura lo separa).')
                        ->afterStateUpdated(fn (Get $get, Set $set) => self::recalcularTotal($get, $set)),

                    // En factura no se escribe: es la suma de los tres de arriba,
                    // así no puede discrepar. En recibo es el único campo.
                    MontoField::make('total', 'Total')
                        ->default(null)
                        ->rules(['nullable'])
                        ->disabled(fn (Get $get): bool => self::esFactura($get))
                        ->dehydrated(true)
                        ->dehydrateStateUsing(fn (mixed $state): float => self::num($state))
                        ->helperText(fn (Get $get): string => self::esFactura($get)
                            ? 'Gravado + ISV + exento. Se suma solo.'
                            : 'Lo que pagaste en total.'),

                    // Aviso, no freno: si el ISV escrito no es ~15% del gravado
                    // casi siempre es un dígito mal tecleado. Se avisa y el
                    // contador decide (hay facturas con retenciones o redondeos).
                    Placeholder::make('chequeo_isv')
                        ->hiddenLabel()
                        ->columnSpanFull()
                        ->visible(function (Get $get): bool {
                            if (! self::esFactura($get) || self::num($get('gravado')) <= 0) {
                                return false;
                            }

                            return abs(self::num($get('isv')) - round(self::num($get('gravado')) * 0.15, 2)) > 1.00;
                        })
                        ->content(function (Get $get): HtmlString {
                            $esperado = round(self::num($get('gravado')) * 0.15, 2);

                            return new HtmlString(
                                '<span style="font-size:.85rem; color:#d97706;">⚠ El 15% de ese gravado daría L. '
                                .number_format($esperado, 2).'. Revisá el ISV contra la factura por si se coló un dígito.</span>'
                            );
                        }),

                    // Aviso sin bloquear: una factura con ISV pero sin RTN del
                    // proveedor es un crédito que el SAR puede rechazar.
                    Placeholder::make('aviso_rtn')
                        ->hiddenLabel()
                        ->columnSpanFull()
                        ->visible(fn (Get $get): bool => self::esFactura($get)
                            && self::num($get('gravado')) > 0
                            && trim((string) $get('proveedor_rtn')) === '')
                        ->content(new HtmlString(
                            '<span style="font-size:.85rem; color:#d97706;">⚠ Estás descontando ISV sin el RTN del proveedor. Si el SAR audita, puede rechazar ese crédito.</span>'
                        )),

                    TextInput::make('notas')
                        ->label('Notas (opcional)')
                        ->maxLength(255)
                        ->columnSpanFull(),
                ])->columns(3),
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
                TextColumn::make('isv')->label('I.S.V.')->money('HNL')->toggleable(),
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
