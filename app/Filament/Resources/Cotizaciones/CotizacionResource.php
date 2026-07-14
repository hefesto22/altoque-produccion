<?php

declare(strict_types=1);

namespace App\Filament\Resources\Cotizaciones;

use App\Filament\Resources\Cotizaciones\Pages\CreateCotizacion;
use App\Filament\Resources\Cotizaciones\Pages\EditCotizacion;
use App\Filament\Resources\Cotizaciones\Pages\ListCotizaciones;
use App\Filament\Schemas\Components\MontoField;
use App\Filament\Schemas\Components\RTNField;
use App\Filament\Schemas\Components\TelefonoHondurasField;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\Producto;
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
                        ->dehydrateStateUsing(fn (string $state): string => mb_strtoupper(trim($state))),
                    TelefonoHondurasField::make('cliente_telefono', 'Teléfono'),
                    RTNField::make('cliente_rtn'),
                    DatePicker::make('evento_fecha')->label('Fecha del evento')->native(false),
                    TextInput::make('evento_lugar')->label('Lugar del evento')->maxLength(255),
                    TextInput::make('personas')->label('N° de personas')->numeric()->minValue(1),
                ])->columns(3),

            Section::make('Ítems de la cotización')
                ->icon('heroicon-o-clipboard-document-list')
                ->description('Escribí lo que sea con su precio personalizado (panas, cazuelas, carnes…), o elegí del catálogo para autocompletar y ajustá el precio del evento.')
                ->schema([
                    Repeater::make('items')
                        ->relationship()
                        ->hiddenLabel()
                        ->table([
                            TableColumn::make('Del catálogo (opcional)'),
                            TableColumn::make('Descripción')->markAsRequired(),
                            TableColumn::make('Cant.')->markAsRequired(),
                            TableColumn::make('Precio unit.')->markAsRequired(),
                            TableColumn::make('ISV'),
                        ])
                        ->schema([
                            Select::make('catalogo')
                                ->placeholder('Buscar…')
                                ->options(fn (): array => Producto::query()->activos()
                                    ->orderBy('nombre')->pluck('nombre', 'id')->all())
                                ->searchable()
                                ->live()
                                ->dehydrated(false)
                                // Prellena descripción, precio e ISV; todo queda
                                // editable: el precio del evento es personalizado.
                                ->afterStateUpdated(function (Set $set, ?string $state): void {
                                    if ($state === null || $state === '') {
                                        return;
                                    }

                                    $p = Producto::query()->find((int) $state);

                                    if ($p === null) {
                                        return;
                                    }

                                    $set('descripcion', $p->nombre);
                                    $set('precio_unitario', (float) $p->precio);
                                    $set('grava_isv', (bool) $p->grava_isv);
                                }),
                            TextInput::make('descripcion')->required()
                                ->placeholder('Ej: Pana de arroz imperial para 50 personas'),
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
                        ->options(Cotizacion::ESTADOS)
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
                Action::make('estado')
                    ->label('Estado')
                    ->icon('heroicon-o-arrow-path')
                    ->color('gray')
                    ->modalHeading('Cambiar estado')
                    ->modalDescription(fn (Cotizacion $record): string => $record->numero.' — '.$record->cliente_nombre)
                    ->fillForm(fn (Cotizacion $record): array => ['estado' => $record->estado])
                    ->schema([
                        ToggleButtons::make('estado')->hiddenLabel()
                            ->options(Cotizacion::ESTADOS)
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
                    ->action(function (Cotizacion $record, array $data): void {
                        $record->update(['estado' => $data['estado']]);

                        Notification::make()
                            ->title('Cotización '.mb_strtolower(Cotizacion::ESTADOS[$data['estado']] ?? ''))
                            ->success()
                            ->send();
                    }),
                EditAction::make(),
                DeleteAction::make(),
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
