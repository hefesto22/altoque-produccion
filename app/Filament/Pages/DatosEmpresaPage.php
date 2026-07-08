<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\EmpresaSetting;
use App\Support\Acceso;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Datos de la empresa: razón social, RTN, dirección, contacto, horario y
 * opciones de facturación. Aparecen en las facturas y en la pantalla de
 * menú. Separada de la "Configuración del Sistema" (que es solo branding).
 *
 * @property Schema $form
 */
class DatosEmpresaPage extends Page
{
    protected string $view = 'filament.pages.datos-empresa';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?int $navigationSort = 1;

    public function getTitle(): string
    {
        return 'Datos de la Empresa';
    }

    public static function getNavigationLabel(): string
    {
        return 'Datos de la Empresa';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Sistema';
    }

    public static function canAccess(): bool
    {
        return Acceso::puede('View:DatosEmpresaPage');
    }

    /** @var array<string, mixed> */
    public array $data = [];

    public function mount(): void
    {
        $this->form->fill(EmpresaSetting::actual()->attributesToArray());
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Datos legales / fiscales')
                    ->description('Aparecen en todas las facturas.')
                    ->schema([
                        TextInput::make('razon_social')->label('Razón social')->required(),
                        TextInput::make('nombre_comercial')->label('Nombre comercial')->helperText('Si es distinto a la razón social.'),
                        TextInput::make('rtn')->label('RTN')->required()->maxLength(14),
                        TextInput::make('giro')->label('Giro del negocio'),
                    ])->columns(2),

                Section::make('Ubicación y contacto')
                    ->schema([
                        TextInput::make('direccion')->label('Dirección')->required()->columnSpanFull(),
                        TextInput::make('telefono')->label('Teléfono principal'),
                        TextInput::make('telefono2')->label('Teléfono secundario'),
                        TextInput::make('correo')->label('Correo electrónico')->email(),
                        TextInput::make('sitio_web')->label('Sitio web'),
                    ])->columns(2),

                Section::make('Pantalla de menú')
                    ->description('Texto que se muestra en la pantalla pública del menú.')
                    ->schema([
                        TextInput::make('horario')->label('Horario')->placeholder('Lunes a Sábado de 7:00 am a 8:30 pm'),
                        TextInput::make('formas_pago_texto')->label('Formas de pago (texto)')->placeholder('Aceptamos tarjetas y transferencias'),
                    ])->columns(2),

                Section::make('Facturación')
                    ->schema([
                        TextInput::make('factura_concepto')->label('Concepto único de factura')->default('Alimentación')
                            ->helperText('Texto que se imprime cuando la factura no se detalla.'),
                        Toggle::make('factura_detallada')->label('Detallar productos por defecto')->inline(false),
                        TextInput::make('dia_limite_anulacion')->label('Día límite de anulación')->numeric()->minValue(1)->maxValue(28)
                            ->helperText('Día del mes siguiente hasta el cual se puede anular.'),
                        Toggle::make('comanda_en_local')->label('Imprimir comanda en ventas de local')->inline(false)
                            ->helperText('Al cobrar en el local, la comanda sale junto a la factura en la misma impresión. No aparece en la pantalla de cocina.'),
                    ])->columns(3),
            ]);
    }

    public function save(): void
    {
        EmpresaSetting::actual()->update($this->form->getState());

        Notification::make()->title('Datos de la empresa guardados')->success()->send();
    }
}
