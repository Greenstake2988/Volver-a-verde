<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cuarto extends Model
{
    protected $table = 'cuartos';

    protected $fillable = ['nombre', 'anuncio_airbnb', 'activo'];

    protected $casts = ['activo' => 'boolean'];

    public function scopeActivos($q)
    {
        return $q->where('activo', true);
    }

    public function gastos(): HasMany
    {
        return $this->hasMany(Gasto::class);
    }

    public function reservas(): HasMany
    {
        return $this->hasMany(Reserva::class);
    }
}
