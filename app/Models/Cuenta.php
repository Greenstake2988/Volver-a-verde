<?php

namespace App\Models;

use App\Casts\Fecha;
use Illuminate\Database\Eloquent\Model;

/** Cuenta del negocio. En una de crédito el saldo es negativo cuando se debe. */
class Cuenta extends Model
{
    public const TIPOS = ['debito' => 'Débito', 'credito' => 'Crédito'];

    protected $table = 'cuentas';

    protected $fillable = ['nombre', 'tipo', 'saldo_inicial', 'fecha_saldo', 'recibe_airbnb', 'user_id'];

    /** El socio que usa esta tarjeta o cuenta (la cuenta sigue siendo del negocio). */
    public function usuario(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** La cuenta con la que normalmente paga un socio: la suya, o si no tiene, la primera. */
    public static function dePago(?int $userId): ?self
    {
        return ($userId ? static::where('user_id', $userId)->orderBy('id')->first() : null)
            ?? static::orderBy('id')->first();
    }

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
