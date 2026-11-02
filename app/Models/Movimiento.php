<?php

namespace App\Models;

use App\Casts\Fecha;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ajuste manual del flujo de efectivo (comisiones bancarias, retiros, aportaciones). */
class Movimiento extends Model
{
    protected $table = 'movimientos';

    protected $fillable = ['cuenta_id', 'fecha', 'monto', 'concepto', 'user_id'];   // user_id: pago de un socio

    protected function casts(): array
    {
        return ['fecha' => Fecha::class, 'monto' => 'float'];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function cuenta(): BelongsTo
    {
        return $this->belongsTo(Cuenta::class);
    }
}
