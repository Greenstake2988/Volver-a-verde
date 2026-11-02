<?php

namespace App\Models;

use App\Casts\Fecha;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Reserva extends Model
{
    protected $table = 'reservas';

    protected $fillable = ['cuarto_id', 'codigo', 'huesped', 'check_in', 'noches', 'bruto',
        'comision', 'impuestos', 'fecha_pago', 'notas'];

    protected function casts(): array
    {
        return [
            'check_in' => Fecha::class,
            'fecha_pago' => Fecha::class,
            'noches' => 'integer',
            'bruto' => 'float',
            'comision' => 'float',
            'impuestos' => 'float',
        ];
    }

    public function cuarto(): BelongsTo
    {
        return $this->belongsTo(Cuarto::class);
    }

    /** Lo que Airbnb deposita: cobrado − comisión − impuestos retenidos. */
    public function getNetoAttribute(): float
    {
        return round($this->bruto - $this->comision - $this->impuestos, 2);
    }
}
