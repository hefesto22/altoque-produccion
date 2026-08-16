<?php

declare(strict_types=1);

namespace App\Filament\Resources\PagosSistema\Pages;

use App\Filament\Resources\PagosSistema\PagoSistemaResource;
use App\Filament\Resources\PagosSistema\Widgets\ResumenContrato;
use App\Filament\Schemas\Components\MontoField;
use App\Models\PagoSistema;
use App\Services\Pagos\PagoSistemaService;
use App\Support\Acceso;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Carbon;

class ListPagosSistema extends ListRecords
{
    protected static string $resource = PagoSistemaResource::class;

    /**
     * El plan se pone al día al entrar, no en un seeder: si se renegocia el
     * trato basta con tocar config/cobro.php y abrir la pantalla. Lo ya
     * pagado nunca se reescribe.
     */
    public function mount(): void
    {
        parent::mount();

        app(PagoSistemaService::class)->sincronizarPlan();
    }

    protected function getHeaderWidgets(): array
    {
        return [ResumenContrato::class];
    }

    protected function getHeaderActions(): array
    {
        return [
            // Un módulo nuevo se cobra aparte y NO deforma el contrato: entra
            // como cargo extra, con su mes y su concepto.
            Action::make('nuevoCargo')
                ->label('Nuevo cargo')
                ->icon('heroicon-o-plus-circle')
                ->color('primary')
                ->modalHeading('Cobro fuera del contrato')
                ->modalDescription('Para un módulo nuevo o un trabajo aparte. No toca las cuotas mensuales.')
                ->modalSubmitActionLabel('Agregar cargo')
                ->fillForm(fn (): array => ['periodo' => now()->startOfMonth()->toDateString()])
                ->schema([
                    TextInput::make('concepto')
                        ->label('Concepto')
                        ->required()
                        ->maxLength(60)
                        ->placeholder('Módulo de inventario'),
                    MontoField::make('monto', 'Monto a cobrar'),
                    DatePicker::make('periodo')
                        ->label('Mes en que se cobra')
                        ->required()
                        ->displayFormat('m/Y')
                        ->helperText('Se guarda como el mes completo; el día no importa.'),
                    TextInput::make('notas')->label('Nota (opcional)')->maxLength(255),
                ])
                ->visible(fn (): bool => Acceso::puede('Update:PagoSistema'))
                ->action(function (array $data): void {
                    abort_unless(Acceso::puede('Update:PagoSistema'), 403);

                    app(PagoSistemaService::class)->agregarCargo(
                        $data['concepto'],
                        (float) $data['monto'],
                        Carbon::parse($data['periodo']),
                        $data['notas'] ?? null,
                    );

                    Notification::make()
                        ->title('Cargo agregado')
                        ->body('Total a cobrar: L. '.number_format(PagoSistema::resumen()['total'], 2))
                        ->success()
                        ->send();
                }),
        ];
    }

    /** El contrato dicho en palabras, armado desde config/cobro.php. */
    public function getSubheading(): ?string
    {
        return PagoSistema::contratoEnPalabras()
            .' No es plata del negocio: no entra a cortes de caja ni a los libros fiscales.';
    }
}
