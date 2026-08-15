<?php

declare(strict_types=1);

namespace App\Filament\Schemas\Components;

use Filament\Forms\Components\TextInput;
use Illuminate\Support\Str;

/**
 * Campo de teléfono para clientes — prefijo +504 visible y nada más.
 *
 * ANTES exigía 8 dígitos empezando en 2, 3 o 9 (`regex:/^[239][0-9]{7}$/`
 * + `minLength(8)` + máscara fija). Eso dejaba afuera clientes REALES:
 * las líneas Claro viejas empiezan en 8, y las anteriores a la migración a
 * ocho dígitos tienen solo siete. El cajero no podía registrar la
 * cotización de un cliente que sí existe y sí contesta ese número.
 *
 * Decisión con Mauricio (2026-08-15): validar el formato del teléfono NO
 * vale lo que cuesta. Se acepta cualquier número — viejo, con guiones o de
 * otro país. El único límite es el largo de la columna (`varchar(30)`).
 *
 * Uso:
 *   TelefonoHondurasField::make('telefono')
 *   TelefonoHondurasField::make('whatsapp', 'WhatsApp', required: true)
 */
final class TelefonoHondurasField
{
    public static function make(string $name = 'telefono', ?string $label = null, bool $required = false): TextInput
    {
        return TextInput::make($name)
            ->label($label ?? Str::headline($name))
            ->prefix('+504')
            ->placeholder('99887766')
            // Sin máscara ni largo mínimo: la máscara bloqueaba guiones y
            // cualquier número que no fueran exactamente 8 dígitos.
            ->maxLength(20)
            ->tel()
            ->rules([
                $required ? 'required' : 'nullable',
                'string',
                'max:20',
            ])
            ->required($required)
            ->helperText('Normalmente 8 dígitos, pero se acepta cualquier número (líneas viejas, con guiones o de otro país).');
    }
}
