<?php

namespace App\Models;

use App\Casts\Fecha;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Pago de la tarjeta (origen → destino) o reembolso a un socio (sin destino). */
class Transferencia extends Model
{
    protected $table = 'transferencias';

    protected $fillable = ['fecha', 'monto', 'origen_id', 'destino_id', 'reembolso_a', 'concepto'];

    protected function casts(): array
    {
        return ['fecha' => Fecha::class, 'monto' => 'float'];
    }

    public function origen(): BelongsTo
    {
        return $this->belongsTo(Cuenta::class, 'origen_id');
    }

    public function destino(): BelongsTo
    {
        return $this->belongsTo(Cuenta::class, 'destino_id');
    }

    public function socio(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reembolso_a');
    }
}
