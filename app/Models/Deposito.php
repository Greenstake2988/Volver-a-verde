<?php

namespace App\Models;

use App\Casts\Fecha;
use Illuminate\Database\Eloquent\Model;

/** Depósito de Airbnb a la cuenta (renglón "Payout" del CSV). */
class Deposito extends Model
{
    protected $table = 'depositos';

    protected $fillable = ['fecha', 'fecha_envio', 'monto', 'referencia', 'detalles'];

    protected function casts(): array
    {
        return ['fecha' => Fecha::class, 'fecha_envio' => Fecha::class, 'monto' => 'float'];
    }
}
