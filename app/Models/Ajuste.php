<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Ajustes clave-valor: saldo_inicial, fecha_saldo. */
class Ajuste extends Model
{
    protected $table = 'ajustes';

    protected $primaryKey = 'clave';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = ['clave', 'valor'];

    public static function valor(string $clave, ?string $default = null): ?string
    {
        return static::find($clave)?->valor ?? $default;
    }

    public static function guardar(string $clave, ?string $valor): void
    {
        static::updateOrCreate(['clave' => $clave], ['valor' => $valor]);
    }
}
