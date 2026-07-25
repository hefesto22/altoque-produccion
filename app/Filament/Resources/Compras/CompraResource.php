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
use Filament\Forms\Components\Hidden;
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
     * Desglosa un importe que YA trae el ISV incluido (así viene en la
     * factura: "L. 1,000" con el 15% adentro).
     *
     * El ISV se saca por DIFERENCIA (bruto − base) y no como base × 0.15:
     * de esa forma base + ISV da exactamente el bruto y nunca se pierde un
     * centavo por redondeo, que en lo fiscal tiene que cuadrar al centavo.
     *
     * @return array{base: float, isv: float}
     */
    private static function desglosar(float $bruto): array
    {
        $base = round($bruto / 1.15, 2);

        return ['base' => $base, 'isv' => round($bruto - $base, 2)];
    }

    /**
     * Recalcula lo que se guarda (gravado sin impuesto, ISV y total) a partir
     * de lo que el usuario escribe (gravado con ISV incluido + exento).
     */
    private static function recalcularTotal(Get $get, Set $set): void
    {
        $bruto = self::num($get('gravado_bruto'));
        $exento = self::num($get('exento'));
        $d = self::desglosar($bruto);

        $set('gravado', $d['base']);
        $set('isv', $d['isv']);
        $set('total', round($bruto + $exento, 2));
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
                    ? 'Copiá los montos tal como vienen en la factura: el gravado con el ISV ya incluido. El sistema calcula solo cuánto de eso es impuesto.'
                    : 'Solo el total de lo que pagaste.')
                ->schema([
                    // Lo que el usuario escribe: el monto CON impuesto, tal
                    // cual lo lee de la factura. No se guarda: de acá salen
                    // el gravado sin impuesto y el ISV (columnas reales).
                    MontoField::make('gravado_bruto', 'Gravado (con ISV incluido)')
                        ->visible(fn (Get $get): bool => self::esFactura($get))
                        ->dehydrated(false)
                        ->live(onBlur: true)
                        ->helperText('Ej.: si la factura dice L. 1,000, escribí 1000 — el 15% ya va adentro.')
                        ->afterStateHydrated(function (TextInput $component, ?Compra $record): void {
                            // Al editar, se reconstruye desde lo guardado.
                            if ($record !== null) {
                                $component->state(round((float) $record->gravado + (float) $record->isv, 2));
                            }
                        })
                        ->afterStateUpdated(fn (Get $get, Set $set) => self::recalcularTotal($get, $set)),

                    MontoField::make('exento', 'Exento')
                        ->visible(fn (Get $get): bool => self::esFactura($get))
                        ->live(onBlur: true)
                        ->helperText('Lo que no paga impuesto (si la factura lo separa).')
                        ->afterStateUpdated(fn (Get $get, Set $set) => self::recalcularTotal($get, $set)),

                    // En un recibo es el único campo: ocupa media fila para que
                    // no quede un cuadrito suelto contra el borde.
                    MontoField::make('total', 'Total pagado')
                        ->columnSpan(fn (Get $get): int => self::esFactura($get) ? 1 : 2)
                        ->helperText(fn (Get $get): ?string => self::esFactura($get)
                            ? 'Gravado + exento. Debe cuadrar con la factura.'
                            : null),

                    // El desglose se muestra, no se escribe: así el cajero
                    // verifica de un vistazo contra la factura.
                    Placeholder::make('desglose_isv')
                        ->label('ISV que se descuenta')
                        ->visible(fn (Get $get): bool => self::esFactura($get))
                        ->content(function (Get $get): HtmlString {
                            $bruto = self::num($get('gravado_bruto'));

                            if ($bruto <= 0) {
                                return new HtmlString('<span style="opacity:.55;">—</span>');
                            }

                            $d = self::desglosar($bruto);

                            return new HtmlString(
                                '<span style="color:#16a34a; font-weight:800; font-size:1.15rem;">L. '.number_format($d['isv'], 2).'</span>'
                                .'<br><span style="font-size:.75rem; opacity:.7;">Base L. '.number_format($d['base'], 2)
                                .' + ISV L. '.number_format($d['isv'], 2).'</span>'
                            );
                        }),

                    // Columnas reales que se guardan, calculadas arriba.
                    Hidden::make('gravado')->default(0),
                    Hidden::make('isv')->default(0),

                    // Aviso sin bloquear: una factura con ISV pero sin RTN del
                    // proveedor es un crédito que el SAR puede rechazar.
                    Placeholder::make('aviso_rtn')
                        ->hiddenLabel()
                        ->columnSpanFull()
                        ->visible(fn (Get $get): bool => self::esFactura($get)
                            && self::num($get('gravado_bruto')) > 0
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
