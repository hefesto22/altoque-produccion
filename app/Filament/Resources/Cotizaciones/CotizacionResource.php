<?php

declare(strict_types=1);

namespace App\Filament\Resources\Cotizaciones;

use App\Filament\Resources\Cotizaciones\Pages\CreateCotizacion;
use App\Filament\Resources\Cotizaciones\Pages\EditCotizacion;
use App\Filament\Resources\Cotizaciones\Pages\ListCotizaciones;
use App\Filament\Schemas\Components\MontoField;
use App\Filament\Schemas\Components\RTNField;
use App\Filament\Schemas\Components\TelefonoHondurasField;
use App\Models\Cotizacion;
use App\Models\Producto;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

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
                ->schema([
                    TextInput::make('cliente_nombre')->label('Cliente / Empresa')->required()
                        ->dehydrateStateUsing(fn (string $state): string => mb_strtoupper(trim($state))),
                    TelefonoHondurasField::make('cliente_telefono', 'Teléfono'),
                    RTNField::make('cliente_rtn'),
                    DatePicker::make('evento_fecha')->label('Fecha del evento')->native(false),
                    TextInput::make('evento_lugar')->label('Lugar del evento')->maxLength(255),
                    TextInput::make('personas')->label('N° de personas')->numeric()->minValue(1),
                ])->columns(3),

            Section::make('Ítems de la cotización')
                ->description('Escribí lo que sea con su precio personalizado (panas, cazuelas, carnes…), o elegí del catálogo para autocompletar y ajustá el precio para el evento.')
                ->schema([
                    Repeater::make('items')
                        ->relationship()
                        ->hiddenLabel()
                        ->schema([
                            Select::make('catalogo')
                                ->label('Del catálogo (opcional)')
                                ->placeholder('Buscar producto o platillo…')
                                ->options(fn (): array => Producto::query()->activos()
                                    ->orderBy('nombre')->pluck('nombre', 'id')->all())
                                ->searchable()
                                ->live()
                                ->dehydrated(false)
                                // Prellena descripción, precio e ISV; todo queda editable:
                                // el precio del evento es personalizado, no el del menú.
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
                                })
                                ->columnSpan(3),
                            TextInput::make('descripcion')->label('Descripción')->required()
                                ->placeholder('Ej: Pana de arroz imperial para 50 personas')
                                ->columnSpan(4),
                            TextInput::make('cantidad')->label('Cant.')->numeric()->required()
                                ->default(1)->minValue(0.01)->columnSpan(1),
                            MontoField::make('precio_unitario', 'Precio unit.')->columnSpan(2),
                            Toggle::make('grava_isv')->label('ISV')->default(true)->inline(false)
                                ->columnSpan(1),
                        ])
                        ->columns(11)
                        ->orderColumn('orden')
                        ->reorderable()
                        ->defaultItems(1)
                        ->addActionLabel('Agregar ítem')
                        ->minItems(1),
                ]),

            Section::make('Condiciones')
                ->description('El desglose (gravado, exento, ISV y total) se calcula solo al guardar, con ISV incluido en los precios.')
                ->schema([
                    MontoField::make('descuento', 'Descuento global')->default(0)
                        ->helperText('Se resta del total y sale desglosado en el PDF.'),
                    MontoField::make('anticipo', 'Anticipo para reservar')->required(false)
                        ->helperText('Opcional: monto que el cliente deja para apartar la fecha.'),
                    TextInput::make('validez_dias')->label('Validez de precios')->numeric()
                        ->default(15)->minValue(1)->suffix('días'),
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
                        ->required(),
                    Textarea::make('notas')->label('Notas / condiciones (salen en el PDF)')
                        ->placeholder('Ej: Incluye montaje y meseros. No incluye local. 50% de anticipo para reservar.')
                        ->rows(3)->columnSpanFull(),
                ])->columns(3),
        ]);
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
