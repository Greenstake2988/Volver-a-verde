<?php

namespace App\Models;

use App\Casts\Fecha;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Reserva extends Model
{
    protected $table = 'reservas';

    protected $fillable = ['cuarto_id', 'codigo', 'huesped', 'check_in', 'noches', 'fecha_salida', 'bruto',
        'comision', 'iva_trasladado', 'impuestos', 'retencion_iva', 'retencion_isr', 'ish', 'origen',
        'fecha_pago', 'notas', 'cuenta_id', 'capturado_por'];   // origen: airbnb | manual | efectivo

    protected function casts(): array
    {
        return [
            'check_in' => Fecha::class,
            'fecha_pago' => Fecha::class,
            'fecha_salida' => Fecha::class,
            'iva_trasladado' => 'float',
            'retencion_iva' => 'float',
            'retencion_isr' => 'float',
            'ish' => 'float',
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

    /** Dónde entró el dinero de una renta fuera de Airbnb (vacío = la cuenta que recibe Airbnb). */
    public function cuenta(): BelongsTo
    {
        return $this->belongsTo(Cuenta::class);
    }

    public function capturadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'capturado_por');
    }

    /**
     * Lo que Airbnb deposita: cobrado + IVA que pagó el huésped − comisión − impuestos retenidos.
     * (impuestos = retención de IVA + retención de ISR)
     */
    public function getNetoAttribute(): float
    {
        return round($this->bruto + $this->iva_trasladado - $this->comision - $this->impuestos, 2);
    }
}
