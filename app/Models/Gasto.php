<?php

namespace App\Models;

use App\Casts\Fecha;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Gasto extends Model
{
    public const CATEGORIAS = [
        'Limpieza', 'Lavandería', 'Luz', 'Agua', 'Gas', 'Internet', 'Gasolina',
        'Mantenimiento', 'Insumos', 'Mobiliario y equipo', 'Predial',
        'Contador', 'Sueldos', 'Impuestos', 'Otros',
    ];

    protected $table = 'gastos';

    protected $fillable = ['fecha', 'monto', 'categoria', 'cuarto_id', 'descripcion',
        'personal', 'factura', 'cuenta_id', 'user_id', 'capturado_por'];

    protected function casts(): array
    {
        return [
            'fecha' => Fecha::class,
            'monto' => 'float',
            'personal' => 'boolean',
            'factura' => 'boolean',
        ];
    }

    public function cuarto(): BelongsTo
    {
        return $this->belongsTo(Cuarto::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Vacía = lo pagó el socio con su dinero y queda por reembolsar. */
    public function cuenta(): BelongsTo
    {
        return $this->belongsTo(Cuenta::class);
    }

    public function capturadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'capturado_por');
    }

    public function comprobantes(): HasMany
    {
        return $this->hasMany(Comprobante::class);
    }

    /** Gastos que sí cuentan para la rentabilidad. */
    public function scopeDelNegocio(Builder $q): void
    {
        $q->where('personal', false);
    }
}
