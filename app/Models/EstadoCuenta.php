<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/** Archivo del estado de cuenta de una tarjeta o cuenta en un mes. */
class EstadoCuenta extends Model
{
    protected $table = 'estados_cuenta';

    protected $fillable = ['cuenta_id', 'mes', 'ruta', 'nombre_original', 'mime', 'tamano', 'user_id'];

    protected static function booted(): void
    {
        static::deleting(fn (EstadoCuenta $e) => Storage::disk('local')->delete($e->ruta));
    }

    public function cuenta(): BelongsTo
    {
        return $this->belongsTo(Cuenta::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
