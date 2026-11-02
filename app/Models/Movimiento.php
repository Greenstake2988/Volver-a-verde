<?php

namespace App\Models;

use App\Casts\Fecha;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ajuste manual del flujo de efectivo (comisiones bancarias, retiros, aportaciones). */
class Movimiento extends Model
{
    protected $table = 'movimientos';

    protected $fillable = ['cuenta_id', 'fecha', 'monto', 'concepto'];

    protected function casts(): array
    {
        return ['fecha' => Fecha::class, 'monto' => 'float'];
    }

    public function cuenta(): BelongsTo
    {
        return $this->belongsTo(Cuenta::class);
    }
}
