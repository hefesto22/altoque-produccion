<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Mes cuyo recordatorio de pago ya fue marcado como recibido por el gerente.
 *
 * @property int $id
 * @property Carbon $periodo
 * @property int|null $user_id
 * @property Carbon $confirmado_en
 */
class AvisoPago extends Model
{
    protected $table = 'avisos_pago';

    /** @var array<int, string> */
    protected $fillable = ['periodo', 'user_id', 'confirmado_en'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'periodo'       => 'date',
            'confirmado_en' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
