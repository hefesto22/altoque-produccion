<?php

declare(strict_types=1);

namespace App\Filament\Resources\CorteCajas\Pages;

use App\Filament\Resources\CorteCajas\CorteCajaResource;
use App\Filament\Schemas\Components\MontoField;
use App\Models\User;
use App\Services\Caja\CorteCajaService;
use App\Support\Acceso;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListCorteCajas extends ListRecords
{
    protected static string $resource = CorteCajaResource::class;

    /**
     * Apertura de turno por el encargado: quien entrega el fondo abre el
     * turno a nombre del cajero. El cajero sin `abrir_turno` no puede
     * abrirlo desde el POS — este es el único camino.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('AbrirTurno')
                ->label('Abrir turno')
                ->icon('heroicon-o-play')
                ->color('success')
                ->visible(fn (): bool => Acceso::puede('AbrirTurno'))
                ->modalHeading('Abrir turno de caja')
                ->modalDescription('Elegí el cajero y con cuánto arranca su caja. El turno queda a nombre del cajero.')
                ->schema([
                    Select::make('cajero_id')
                        ->label('Cajero')
                        ->options(fn (): array => User::query()
                            ->where('is_active', true)
                            ->role(['cajero', 'gerente', 'administrador'])
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->searchable()
                        ->required()
                        ->native(false),

                    MontoField::make('fondo_inicial', 'Efectivo inicial en caja')
                        ->helperText('El efectivo (vuelto) con que arranca la gaveta.'),

                    MontoField::make('fondo_terminal', 'Saldo inicial del terminal POS')
                        ->helperText('Lo que quedó en el terminal de tarjeta/transferencias sin cortar (si aplica).'),
                ])
                ->action(function (array $data): void {
                    abort_unless(Acceso::puede('AbrirTurno'), 403);

                    $corte = app(CorteCajaService::class)->abrir(
                        (int) $data['cajero_id'],
                        (float) ($data['fondo_inicial'] ?? 0),
                        (float) ($data['fondo_terminal'] ?? 0),
                    );

                    if (! $corte->wasRecentlyCreated) {
                        Notification::make()
                            ->title('Ya hay un turno abierto — la caja es una sola')
                            ->body('Turno de '.($corte->cajero?->name ?? '—').' abierto desde '.$corte->abierto_at?->format('d/m/Y h:i A').'. Cerralo antes de abrir otro.')
                            ->warning()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Turno abierto')
                        ->body('El cajero ya puede cobrar en el POS.')
                        ->success()
                        ->send();
                }),
        ];
    }
}
