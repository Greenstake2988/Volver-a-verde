<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Ajuste manual del flujo de efectivo (comisiones bancarias, retiros, aportaciones). */
class Movimiento extends Model
{
    protected $table = 'movimientos';

    protected $fillable = ['fecha', 'monto', 'concepto'];

    protected function casts(): array
    {
        return ['fecha' => 'date', 'monto' => 'float'];
    }
}
