<?php

namespace App\Casts;

use Carbon\Carbon;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Fecha sin hora, guardada como "Y-m-d".
 *
 * El cast "date" de Laravel guarda "Y-m-d 00:00:00", y entonces un whereBetween('fecha', [desde, hasta])
 * deja fuera el último día del periodo. Con esta cast las comparaciones por texto funcionan.
 */
class Fecha implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Carbon
    {
        return $value === null ? null : Carbon::parse($value)->startOfDay();
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null || $value === '' ? null : Carbon::parse($value)->toDateString();
    }
}
