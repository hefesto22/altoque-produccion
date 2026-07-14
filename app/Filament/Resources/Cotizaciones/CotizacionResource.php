<?php

declare(strict_types=1);

namespace App\Filament\Resources\Cotizaciones;

use App\Domain\Exceptions\RestauranteException;
use App\Filament\Resources\CorteCajas\CorteCajaResource;
use App\Filament\Resources\Cotizaciones\Pages\CreateCotizacion;
use App\Filament\Resources\Cotizaciones\Pages\EditCotizacion;
use App\Filament\Resources\Cotizaciones\Pages\ListCotizaciones;
use App\Filament\Schemas\Components\MontoField;
use App\Filament\Schemas\Components\RTNField;
use App\Filament\Schemas\Components\TelefonoHondurasField;
use App\Models\Cliente;
use App\Models\CorteCaja;
use App\Models\Cotizacion;
use App\Models\CotizacionPago;
use App\Models\EventoArticulo;
use App\Services\Eventos\FacturadorEvento;
use App\Support\Acceso;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;

/**
 * Cotizaciones de eventos: presupuestos con precios personalizados
 * (platillos completos, panas/cazuelas, carnes, etc.) que se entregan
 * al cliente como PDF profesional o por WhatsApp. No tocan lo fiscal.
 */
class CotizacionResource extends Resource
{
    protected static ?string $model = Cotizacion::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-sparkles';

    protected static ?string $modelLabel = 'Cotización';

    protected static ?string $pluralModelLabel = 'Cotizaciones';

    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): ?string
    {
        return 'Eventos';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Cliente y evento')
                ->icon('heroicon-o-user-circle')
                ->schema([
                    // Autocompletar desde Clientes: escribí el nombre o RTN y
                    // se llenan los datos. Si es nuevo, llená los campos y se
                    // guarda solo en Clientes al crear (cuando trae RTN).
                    Select::make('cliente_existente')
                        ->label('Buscar cliente guardado')
                        ->placeholder('Escribí el nombre o RTN para autocompletar…')
                        ->searchable()
                        ->dehydrated(false)
                        ->live()
                        ->getSearchResultsUsing(fn (string $search): array => Cliente::query()
                            ->where(function ($q) use ($search): void {
                                $q->where('nombre', 'ilike', "%{$search}%")
                                    ->orWhere('rtn', 'like', "%{$search}%");
                            })
                            ->orderBy('nombre')
                            ->limit(15)
                            ->get()
                            ->mapWithKeys(fn (Cliente $c): array => [$c->id => $c->nombre.' · RTN '.$c->rtn])
                            ->all())
                        ->getOptionLabelUsing(fn ($value): ?string => Cliente::query()->find($value)?->nombre)
                        ->afterStateUpdated(function (Set $set, ?string $state): void {
                            $c = $state !== null && $state !== '' ? Cliente::query()->find((int) $state) : null;

                            if ($c === null) {
                                return;
                            }

                            $set('cliente_nombre', $c->nombre);
                            $set('cliente_rtn', $c->rtn);
                        })
                        ->columnSpanFull(),
                    TextInput::make('cliente_nombre')->label('Cliente / Empresa')->required()
                        ->placeholder('Nombre del cliente o empresa')
                        ->extraInputAttributes(['style' => 'text-transform:uppercase'])
                        ->dehydrateStateUsing(fn (string $state): string => mb_strtoupper(trim($state))),
                    TelefonoHondurasField::make('cliente_telefono', 'Teléfono'),
                    RTNField::make('cliente_rtn'),
                    DatePicker::make('evento_fecha')->label('Fecha del evento')->native(false),
                    TextInput::make('evento_lugar')->label('Lugar del evento')->maxLength(255),
                    TextInput::make('personas')->label('N° de personas')->numeric()->minValue(1),
                ])->columns(3),

            Section::make('Ítems de la cotización')
                ->icon('heroicon-o-clipboard-document-list')
                ->description('Escribí lo que sea con su precio personalizado (panas, cazuelas, carnes…). Cada ítem nuevo se guarda solo en el catálogo de eventos con su precio, para autocompletarlo la próxima vez.')
                ->schema([
                    Repeater::make('items')
                        ->relationship()
                        ->hiddenLabel()
                        ->table([
                            TableColumn::make('Del catálogo de eventos (opcional)'),
                            TableColumn::make('Descripción')->markAsRequired(),
                            TableColumn::make('Cant.')->markAsRequired(),
                            TableColumn::make('Precio unit.')->markAsRequired(),
                            TableColumn::make('ISV'),
                        ])
                        ->schema([
                            Select::make('catalogo')
                                ->placeholder('Buscar…')
                                ->searchable()
                                ->live()
                                ->dehydrated(false)
                                // Busca en el catálogo PROPIO de eventos (precios
                                // personalizados, no los del menú) y prellena.
                                // Si lo buscado no existe, el mismo dropdown ofrece
                                // agregarlo como personalizado: pone la descripción
                                // en MAYÚSCULAS y solo falta escribirle el precio —
                                // al guardar la cotización entra al catálogo.
                                ->getSearchResultsUsing(function (string $search): array {
                                    $resultados = EventoArticulo::query()
                                        ->activos()
                                        ->where('nombre', 'ilike', "%{$search}%")
                                        ->orderBy('nombre')
                                        ->limit(15)
                                        ->pluck('nombre', 'id')
                                        ->all();

                                    $termino = mb_strtoupper(trim($search));

                                    if ($termino !== '' && ! in_array($termino, $resultados, true)) {
                                        $resultados["nuevo:{$termino}"] = "➕ Agregar \"{$termino}\" (nuevo)";
                                    }

                                    return $resultados;
                                })
                                ->getOptionLabelUsing(fn ($value): ?string => str_starts_with((string) $value, 'nuevo:')
                                    ? mb_substr((string) $value, 6)
                                    : EventoArticulo::query()->find($value)?->nombre)
                                ->afterStateUpdated(function (Set $set, ?string $state): void {
                                    if ($state === null || $state === '') {
                                        return;
                                    }

                                    // Artículo nuevo: descripción lista, el precio lo
                                    // escribe el usuario (personalizado por definición).
                                    if (str_starts_with($state, 'nuevo:')) {
                                        $set('descripcion', mb_substr($state, 6));

                                        return;
                                    }

                                    $a = EventoArticulo::query()->find((int) $state);

                                    if ($a === null) {
                                        return;
                                    }

                                    $set('descripcion', $a->nombre);
                                    $set('precio_unitario', (float) $a->precio);
                                    $set('grava_isv', (bool) $a->grava_isv);
                                }),
                            TextInput::make('descripcion')->required()
                                ->placeholder('Ej: Pana de arroz imperial para 50 personas')
                                // Todo en MAYÚSCULAS: visual al escribir y real al guardar.
                                ->extraInputAttributes(['style' => 'text-transform:uppercase'])
                                ->dehydrateStateUsing(fn (string $state): string => mb_strtoupper(trim($state))),
                            TextInput::make('cantidad')->numeric()->required()
                                ->default(1)->minValue(0.01)
                                ->live(onBlur: true),
                            MontoField::make('precio_unitario', 'Precio unit.')
                                ->live(onBlur: true),
                            Toggle::make('grava_isv')->default(true)->inline(false),
                        ])
                        ->orderColumn('orden')
                        ->reorderable()
                        ->defaultItems(1)
                        ->minItems(1)
                        ->addActionLabel('Agregar ítem'),
                ]),

            Section::make('Condiciones y total')
                ->icon('heroicon-o-banknotes')
                ->schema([
                    MontoField::make('descuento', 'Descuento global')->default(0)
                        ->live(onBlur: true)
                        ->helperText('Se resta del total y sale desglosado en el PDF.'),
                    MontoField::make('anticipo', 'Anticipo para reservar')->required(false)
                        ->helperText('Opcional: monto para apartar la fecha.'),
                    TextInput::make('validez_dias')->label('Validez de precios')->numeric()
                        ->default(15)->minValue(1)->suffix('días'),
                    // Total en vivo mientras se arma (el desglose exacto de ISV
                    // lo calcula el servidor al guardar).
                    Placeholder::make('total_estimado')
                        ->label('Total de la cotización')
                        ->content(function (Get $get): HtmlString {
                            $suma = 0.0;

                            foreach ((array) ($get('items') ?? []) as $item) {
                                $suma += (float) ($item['cantidad'] ?? 0) * (float) ($item['precio_unitario'] ?? 0);
                            }

                            $descuento = min(max((float) $get('descuento'), 0.0), $suma);
                            $total = number_format(max($suma - $descuento, 0.0), 2);

                            return new HtmlString(
                                '<span style="font-size:1.6rem; font-weight:800; color:#16a34a;">L. '.$total.'</span>'
                                .'<span style="display:block; font-size:.72rem; opacity:.6;">ISV incluido — el desglose sale en el PDF</span>'
                            );
                        }),
                    ToggleButtons::make('estado')->label('Estado')
                        // Solo estados manuales: 'completada' lo asigna la
                        // facturación del evento, nunca la mano.
                        ->options(Cotizacion::ESTADOS_MANUALES)
                        ->colors(Cotizacion::ESTADO_COLORES)
                        ->icons([
                            'borrador'  => 'heroicon-o-pencil',
                            'enviada'   => 'heroicon-o-paper-airplane',
                            'aceptada'  => 'heroicon-o-check-circle',
                            'rechazada' => 'heroicon-o-x-circle',
                        ])
                        ->inline()
                        ->default('borrador')
                        ->required()
                        // En crear siempre nace como borrador: menos ruido.
                        ->hiddenOn('create'),
                    Textarea::make('notas')->label('Notas / condiciones (salen en el PDF)')
                        ->placeholder('Ej: Incluye montaje y meseros. No incluye local. 50% de anticipo para reservar.')
                        ->rows(3)->columnSpanFull(),
                ])->columns(4),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('N°')->sortable()
                    ->formatStateUsing(fn (Cotizacion $record): string => $record->numero),
                TextColumn::make('cliente_nombre')->label('Cliente')->searchable()->weight('bold'),
                TextColumn::make('evento_fecha')->label('Evento')->date('d/m/Y')
                    ->placeholder('—')->sortable(),
                TextColumn::make('personas')->label('Personas')->placeholder('—')->toggleable(),
                TextColumn::make('total')->label('Total')->money('HNL')->weight('bold')->sortable(),
                TextColumn::make('pagado')->label('Pagado')
                    ->state(fn (Cotizacion $record): string => 'L. '.number_format($record->pagado(), 2))
                    ->description(fn (Cotizacion $record): ?string => $record->saldo() > 0.009
                        ? 'Saldo: L. '.number_format($record->saldo(), 2)
                        : null)
                    ->color(fn (Cotizacion $record): string => $record->saldo() > 0.009 ? 'warning' : 'success'),
                TextColumn::make('estado')->label('Estado')->badge()
                    ->formatStateUsing(fn (string $state): string => Cotizacion::ESTADOS[$state] ?? $state)
                    ->color(fn (string $state): string => Cotizacion::ESTADO_COLORES[$state] ?? 'gray'),
                TextColumn::make('created_at')->label('Creada')->date('d/m/Y')->sortable()->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('estado')->options(Cotizacion::ESTADOS),
            ])
            ->recordActions([
                Action::make('pdf')
                    ->label('PDF')
                    ->icon('heroicon-o-document-arrow-down')
                    ->url(fn (Cotizacion $record): string => $record->urlPdf(), shouldOpenInNewTab: true),
                Action::make('whatsapp')
                    ->label('WhatsApp')
                    ->icon('heroicon-o-chat-bubble-left-right')
                    ->color('success')
                    ->url(fn (Cotizacion $record): string => $record->urlWhatsApp(), shouldOpenInNewTab: true),
                // Abonos internos (anticipo hoy, resto después). NO son
                // documento fiscal: la factura sale al completar el evento.
                Action::make('abonar')
                    ->label('Abono')
                    ->icon('heroicon-o-banknotes')
                    ->color('warning')
                    ->modalHeading('Registrar abono')
                    ->modalIcon('heroicon-o-banknotes')
                    ->modalDescription(fn (Cotizacion $record): string => $record->numero.' — '.$record->cliente_nombre
                        .' · Total L. '.number_format((float) $record->total, 2)
                        .' · Abonado L. '.number_format($record->pagado(), 2)
                        .' · Saldo L. '.number_format($record->saldo(), 2))
                    ->modalContent(fn (Cotizacion $record) => view('filament.cotizaciones.abonos', [
                        'pagos' => $record->pagos()->with('receptor')->get(),
                    ]))
                    ->schema(fn (Cotizacion $record): array => [
                        MontoField::make('monto', 'Monto del abono')
                            ->maxValue($record->saldo())
                            ->helperText('Máximo el saldo pendiente: L. '.number_format($record->saldo(), 2)),
                        ToggleButtons::make('forma_pago')->label('Forma de pago')
                            ->options(['efectivo' => 'Efectivo', 'tarjeta' => 'Tarjeta', 'transferencia' => 'Transferencia'])
                            ->icons([
                                'efectivo'      => 'heroicon-o-banknotes',
                                'tarjeta'       => 'heroicon-o-credit-card',
                                'transferencia' => 'heroicon-o-building-library',
                            ])
                            ->inline()
                            ->default('efectivo')
                            ->required()
                            ->live(),
                        Select::make('banco')->label('Banco')
                            ->options(array_combine(config('empresa.bancos', []), config('empresa.bancos', [])))
                            ->visible(fn (Get $get): bool => in_array($get('forma_pago'), ['tarjeta', 'transferencia'], true))
                            ->required(fn (Get $get): bool => in_array($get('forma_pago'), ['tarjeta', 'transferencia'], true)),
                        TextInput::make('notas')->label('Nota (opcional)')->maxLength(255),
                    ])
                    ->visible(fn (Cotizacion $record): bool => ! in_array($record->estado, ['completada', 'rechazada'], true)
                        && $record->saldo() > 0.009)
                    ->action(function (Cotizacion $record, array $data): void {
                        CotizacionPago::create([
                            'cotizacion_id' => $record->id,
                            'monto'         => round((float) $data['monto'], 2),
                            'forma_pago'    => $data['forma_pago'],
                            'banco'         => in_array($data['forma_pago'], ['tarjeta', 'transferencia'], true) ? ($data['banco'] ?? null) : null,
                            'notas'         => $data['notas'] ?? null,
                            'recibido_por'  => Auth::id(),
                            'recibido_at'   => now(),
                        ]);

                        Notification::make()
                            ->title('Abono registrado')
                            ->body('L. '.number_format((float) $data['monto'], 2).' — saldo restante: L. '.number_format($record->fresh()->saldo(), 2))
                            ->success()
                            ->send();
                    }),
                // Completar el evento: emite LA factura SAR por el total vía
                // el flujo normal de ventas (corte del turno, correlativo,
                // libros y declaración solitas). Si queda saldo, se cobra acá.
                Action::make('facturar')
                    ->label('Facturar')
                    ->icon('heroicon-o-document-check')
                    ->color('primary')
                    ->modalHeading('Completar y facturar evento')
                    ->modalIcon('heroicon-o-document-check')
                    ->modalDescription(fn (Cotizacion $record): string => $record->numero.' — '.$record->cliente_nombre
                        .' · Total L. '.number_format((float) $record->total, 2)
                        .' · Abonado L. '.number_format($record->pagado(), 2)
                        .($record->saldo() > 0.009
                            ? ' · Se cobrará el saldo de L. '.number_format($record->saldo(), 2).' y se emitirá la factura SAR por el total.'
                            : ' · Saldo en cero: se emitirá la factura SAR por el total.'))
                    ->schema(fn (Cotizacion $record): array => $record->saldo() <= 0.009 ? [] : [
                        ToggleButtons::make('forma_pago_saldo')->label('Forma de pago del saldo (L. '.number_format($record->saldo(), 2).')')
                            ->options(['efectivo' => 'Efectivo', 'tarjeta' => 'Tarjeta', 'transferencia' => 'Transferencia'])
                            ->icons([
                                'efectivo'      => 'heroicon-o-banknotes',
                                'tarjeta'       => 'heroicon-o-credit-card',
                                'transferencia' => 'heroicon-o-building-library',
                            ])
                            ->inline()
                            ->default('efectivo')
                            ->required()
                            ->live(),
                        Select::make('banco_saldo')->label('Banco')
                            ->options(array_combine(config('empresa.bancos', []), config('empresa.bancos', [])))
                            ->visible(fn (Get $get): bool => in_array($get('forma_pago_saldo'), ['tarjeta', 'transferencia'], true))
                            ->required(fn (Get $get): bool => in_array($get('forma_pago_saldo'), ['tarjeta', 'transferencia'], true)),
                    ])
                    ->visible(fn (Cotizacion $record): bool => $record->estado === 'aceptada'
                        && $record->venta_id === null
                        && Acceso::puede('FacturarEvento'))
                    ->action(function (Cotizacion $record, array $data): void {
                        abort_unless(Acceso::puede('FacturarEvento'), 403);

                        // Sin turno abierto no hay nada que intentar: el aviso
                        // te lleva directo a abrirlo (el cobro del evento entra
                        // al corte del turno de quien factura).
                        $turnoAbierto = CorteCaja::query()
                            ->where('cajero_id', Auth::id())
                            ->where('estado', 'abierto')
                            ->exists();

                        if (! $turnoAbierto) {
                            Notification::make()
                                ->title('Abrí tu turno de caja primero')
                                ->body('El cobro del evento entra al corte del turno de quien factura. Abrí tu turno y volvé a darle Facturar — la cotización sigue lista.')
                                ->warning()
                                ->persistent()
                                ->actions([
                                    Action::make('abrir_turno')
                                        ->label('Ir a abrir turno')
                                        ->icon('heroicon-o-play')
                                        ->url(CorteCajaResource::getUrl('index')),
                                ])
                                ->send();

                            return;
                        }

                        try {
                            $factura = DB::transaction(function () use ($record, $data) {
                                // El pago del saldo y la factura son atómicos:
                                // si la factura falla, el pago no queda registrado.
                                if ($record->saldo() > 0.009) {
                                    CotizacionPago::create([
                                        'cotizacion_id' => $record->id,
                                        'monto'         => $record->saldo(),
                                        'forma_pago'    => $data['forma_pago_saldo'],
                                        'banco'         => in_array($data['forma_pago_saldo'], ['tarjeta', 'transferencia'], true) ? ($data['banco_saldo'] ?? null) : null,
                                        'notas'         => 'Pago del saldo al facturar el evento',
                                        'recibido_por'  => Auth::id(),
                                        'recibido_at'   => now(),
                                    ]);
                                }

                                return app(FacturadorEvento::class)->facturar($record->fresh(), (int) Auth::id());
                            });
                        } catch (RestauranteException $e) {
                            Notification::make()->title('No se pudo facturar')->body($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()
                            ->title('Evento facturado')
                            ->body('Factura '.$factura->numero.' — L. '.number_format((float) $factura->total, 2).'. Entró al corte de tu turno y a los libros.')
                            ->success()
                            ->persistent()
                            ->actions([
                                Action::make('imprimir')
                                    ->label('Imprimir factura')
                                    ->icon('heroicon-o-printer')
                                    ->url($factura->urlTicket(), shouldOpenInNewTab: true),
                            ])
                            ->send();
                    }),
                Action::make('estado')
                    ->label('Estado')
                    ->icon('heroicon-o-arrow-path')
                    ->color('gray')
                    ->modalHeading('Cambiar estado')
                    ->modalDescription(fn (Cotizacion $record): string => $record->numero.' — '.$record->cliente_nombre)
                    ->fillForm(fn (Cotizacion $record): array => ['estado' => $record->estado])
                    ->schema([
                        ToggleButtons::make('estado')->hiddenLabel()
                            // Sin 'completada': ese estado solo lo pone la facturación.
                            ->options(Cotizacion::ESTADOS_MANUALES)
                            ->colors(Cotizacion::ESTADO_COLORES)
                            ->icons([
                                'borrador'  => 'heroicon-o-pencil',
                                'enviada'   => 'heroicon-o-paper-airplane',
                                'aceptada'  => 'heroicon-o-check-circle',
                                'rechazada' => 'heroicon-o-x-circle',
                            ])
                            ->inline()
                            ->required(),
                    ])
                    ->visible(fn (Cotizacion $record): bool => $record->estado !== 'completada')
                    ->action(function (Cotizacion $record, array $data): void {
                        $record->update(['estado' => $data['estado']]);

                        Notification::make()
                            ->title('Cotización '.mb_strtolower(Cotizacion::ESTADOS[$data['estado']] ?? ''))
                            ->success()
                            ->send();
                    }),
                // Una cotización COMPLETADA tiene factura SAR emitida: queda
                // congelada (ni editar ni borrar). Con abonos registrados
                // tampoco se borra: primero se resuelve la plata.
                EditAction::make()
                    ->visible(fn (Cotizacion $record): bool => $record->estado !== 'completada'),
                DeleteAction::make()
                    ->visible(fn (Cotizacion $record): bool => $record->estado !== 'completada'
                        && $record->pagos()->doesntExist()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListCotizaciones::route('/'),
            'create' => CreateCotizacion::route('/create'),
            'edit'   => EditCotizacion::route('/{record}/edit'),
        ];
    }
}
