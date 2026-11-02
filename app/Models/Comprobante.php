<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class Comprobante extends Model
{
    protected $table = 'comprobantes';

    protected $fillable = ['gasto_id', 'ruta', 'nombre_original', 'mime', 'tamano', 'user_id'];

    protected static function booted(): void
    {
        // Al borrar el registro también se borra el archivo.
        static::deleting(fn (Comprobante $c) => Storage::disk('local')->delete($c->ruta));
    }

    public function gasto(): BelongsTo
    {
        return $this->belongsTo(Gasto::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
