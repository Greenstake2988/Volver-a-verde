<?php

namespace App\Models;

use App\Casts\Fecha;
use Illuminate\Database\Eloquent\Model;

/** Cuenta del negocio. En una de crédito el saldo es negativo cuando se debe. */
class Cuenta extends Model
{
    public const TIPOS = ['debito' => 'Débito', 'credito' => 'Crédito'];

    protected $table = 'cuentas';

    protected $fillable = ['nombre', 'tipo', 'saldo_inicial', 'fecha_saldo', 'recibe_airbnb'];

    protected function casts(): array
    {
        return ['saldo_inicial' => 'float', 'fecha_saldo' => Fecha::class, 'recibe_airbnb' => 'boolean'];
    }

    public function esCredito(): bool
    {
        return $this->tipo === 'credito';
    }

    /** La cuenta donde Airbnb deposita (la primera marcada, o la primera de débito). */
    public static function deAirbnb(): ?self
    {
        return static::where('recibe_airbnb', true)->orderBy('id')->first()
            ?? static::where('tipo', 'debito')->orderBy('id')->first();
    }
}
