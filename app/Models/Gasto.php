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
        'Administración', 'Contador', 'Sueldos', 'Impuestos', 'Otros',
    ];

    public const OPCIONES_MESES = [3, 6, 9, 12, 18, 24];

    protected $table = 'gastos';

    protected $fillable = ['fecha', 'monto', 'categoria', 'cuarto_id', 'descripcion',
        'personal', 'factura', 'cuenta_id', 'user_id', 'capturado_por', 'meses'];

    protected function casts(): array
    {
        return [
            'fecha' => Fecha::class,
            'monto' => 'float',
            'personal' => 'boolean',
            'factura' => 'boolean',
            'meses' => 'integer',
        ];
    }

    /** Mensualidades que el banco todavía no cobra al día $hoy (0 si es de contado). La 1ª se cobra el mes siguiente a la compra. */
    public function porCobrarAMeses(\DateTimeInterface $hoy): float
    {
        if (! $this->meses) {
            return 0.0;
        }
        $compra = $this->fecha;
        $cobradas = ((int) $hoy->format('Y') - (int) $compra->format('Y')) * 12 + (int) $hoy->format('n') - (int) $compra->format('n');
        $faltan = $this->meses - max(0, min($this->meses, $cobradas));

        return round($this->monto * $faltan / $this->meses, 2);
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
