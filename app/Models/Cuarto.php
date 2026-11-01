<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cuarto extends Model
{
    protected $table = 'cuartos';

    protected $fillable = ['nombre', 'anuncio_airbnb'];

    public function reservas(): HasMany
    {
        return $this->hasMany(Reserva::class);
    }
}
