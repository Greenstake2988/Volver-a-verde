<?php

namespace App\Services;

use App\Models\Ajuste;
use App\Models\Cuarto;
use App\Models\Gasto;
use App\Models\Movimiento;
use App\Models\Reserva;
use Carbon\CarbonImmutable;

/** Cálculos de rentabilidad y flujo de efectivo. */
class Finanzas
{
    /** Noches de una reserva que caen entre $desde y $hasta (ambos inclusive). */
    public static function nochesEnRango(CarbonImmutable $checkIn, int $noches, CarbonImmutable $desde, CarbonImmutable $hasta): int
    {
        $salida = $checkIn->addDays($noches);           // el día de salida no cuenta
        $a = $checkIn->max($desde);
        $b = $salida->min($hasta->addDay());

        return max(0, (int) $a->diffInDays($b, false));
    }

    public function resumen(CarbonImmutable $desde, CarbonImmutable $hasta): array
    {
        $cuartos = Cuarto::orderBy('id')->get();
        $nCuartos = max(1, $cuartos->count());
        $dias = (int) $desde->diffInDays($hasta) + 1;

        $porCuarto = [];
        foreach ($cuartos as $c) {
            $porCuarto[$c->id] = ['id' => $c->id, 'nombre' => $c->nombre, 'noches' => 0, 'reservas' => 0,
                'bruto' => 0.0, 'neto' => 0.0, 'gastos_directos' => 0.0, 'gastos_generales' => 0.0];
        }
        $tot = ['bruto' => 0.0, 'comision' => 0.0, 'impuestos' => 0.0, 'neto' => 0.0, 'noches' => 0, 'reservas' => 0];

        // Solo reservas que pueden tocar el periodo (las de hasta 1 año antes por estancias largas).
        $reservas = Reserva::whereBetween('check_in', [$desde->subYear()->toDateString(), $hasta->toDateString()])->get();
        foreach ($reservas as $r) {
            $n = self::nochesEnRango(CarbonImmutable::parse($r->check_in), $r->noches, $desde, $hasta);
            if ($n === 0) {
                continue;
            }
            $f = $r->noches ? $n / $r->noches : 0;   // parte proporcional que cae en el periodo
            $tot['noches'] += $n;
            $tot['reservas']++;
            $tot['bruto'] += $r->bruto * $f;
            $tot['comision'] += $r->comision * $f;
            $tot['impuestos'] += $r->impuestos * $f;
            $tot['neto'] += $r->neto * $f;
            if (isset($porCuarto[$r->cuarto_id])) {
                $porCuarto[$r->cuarto_id]['noches'] += $n;
                $porCuarto[$r->cuarto_id]['reservas']++;
                $porCuarto[$r->cuarto_id]['bruto'] += $r->bruto * $f;
                $porCuarto[$r->cuarto_id]['neto'] += $r->neto * $f;
            }
        }

        $gastos = Gasto::whereBetween('fecha', [$desde->toDateString(), $hasta->toDateString()])->get();
        $totalGastos = 0.0;
        $generales = 0.0;
        $personales = 0.0;
        $porCategoria = [];
        foreach ($gastos as $g) {
            if ($g->personal) {
                $personales += $g->monto;

                continue;
            }
            $totalGastos += $g->monto;
            $porCategoria[$g->categoria] = ($porCategoria[$g->categoria] ?? 0) + $g->monto;
            if (isset($porCuarto[$g->cuarto_id])) {
                $porCuarto[$g->cuarto_id]['gastos_directos'] += $g->monto;
            } else {
                $generales += $g->monto;
            }
        }

        foreach ($porCuarto as &$pc) {
            $pc['gastos_generales'] = $generales / $nCuartos;
            $pc['utilidad'] = $pc['neto'] - $pc['gastos_directos'] - $pc['gastos_generales'];
            $pc['ocupacion'] = $dias ? $pc['noches'] / $dias : 0;
            $pc['tarifa_promedio'] = $pc['noches'] ? $pc['bruto'] / $pc['noches'] : 0;
            $pc['margen'] = $pc['neto'] ? $pc['utilidad'] / $pc['neto'] : 0;
        }
        unset($pc);

        arsort($porCategoria);
        $utilidad = $tot['neto'] - $totalGastos;
        $disponibles = $nCuartos * $dias;

        return [
            'desde' => $desde, 'hasta' => $hasta, 'dias' => $dias, 'n_cuartos' => $nCuartos,
            'totales' => $tot + [
                'gastos' => $totalGastos,
                'gastos_personales' => $personales,
                'utilidad' => $utilidad,
                'margen' => $tot['neto'] ? $utilidad / $tot['neto'] : 0,
                'ocupacion' => $disponibles ? $tot['noches'] / $disponibles : 0,
                'tarifa_promedio' => $tot['noches'] ? $tot['bruto'] / $tot['noches'] : 0,
                'costo_por_noche' => $tot['noches'] ? $totalGastos / $tot['noches'] : 0,
                'utilidad_por_noche' => $tot['noches'] ? $utilidad / $tot['noches'] : 0,
            ],
            'por_cuarto' => array_values($porCuarto),
            'por_categoria' => $porCategoria,
        ];
    }

    /** Conclusiones en lenguaje simple para la cabecera del resumen. */
    public function conclusiones(array $r): array
    {
        $t = $r['totales'];
        if (! $t['noches'] && ! $t['gastos']) {
            return ['nivel' => 'meh', 'titulo' => 'Todavía no hay datos en este periodo.', 'notas' => []];
        }
        $m = fn ($v) => '$'.number_format($v, 0);
        $p = fn ($v) => round($v * 100).'%';
        $nivel = $t['utilidad'] > 0 ? ($t['margen'] >= 0.25 ? 'bien' : 'meh') : 'mal';
        $titulo = $t['utilidad'] > 0
            ? "Sí hay ganancia: quedaron {$m($t['utilidad'])} libres ({$p($t['margen'])} de lo que depositó Airbnb)."
            : "En este periodo se perdió dinero: los gastos superaron lo depositado por {$m(-$t['utilidad'])}.";
        $notas = [];
        if ($t['ocupacion'] < 0.5) {
            $notas[] = "La ocupación es de {$p($t['ocupacion'])}. Llenar más noches (precio, fotos, estancias mínimas) es la palanca más grande.";
        }
        $cuartos = collect($r['por_cuarto'])->filter(fn ($c) => $c['neto'] > 0);
        if ($cuartos->count() > 1) {
            $mejor = collect($r['por_cuarto'])->sortByDesc('utilidad')->first();
            $peor = collect($r['por_cuarto'])->sortBy('utilidad')->first();
            $notas[] = "El cuarto que más deja es {$mejor['nombre']} ({$m($mejor['utilidad'])}); el que menos, {$peor['nombre']} ({$m($peor['utilidad'])}, ocupación {$p($peor['ocupacion'])}).";
        }
        if ($r['por_categoria'] && $t['gastos']) {
            $cat = array_key_first($r['por_categoria']);
            $notas[] = "El gasto más grande es {$cat}: {$p($r['por_categoria'][$cat] / $t['gastos'])} de todos los gastos.";
        }
        if ($t['noches']) {
            $notas[] = "Cada noche ocupada cuesta {$m($t['costo_por_noche'])} en gastos y deja {$m($t['utilidad_por_noche'])} de utilidad.";
        }

        return compact('nivel', 'titulo', 'notas');
    }

    /** Entradas y salidas de la cuenta, por mes, y el saldo actual. */
    public function flujo(?CarbonImmutable $hoy = null): array
    {
        $hoy = ($hoy ?? CarbonImmutable::today())->toDateString();
        $saldoInicial = (float) Ajuste::valor('saldo_inicial', '0');
        $fechaSaldo = Ajuste::valor('fecha_saldo', '1900-01-01');

        $movs = collect();
        foreach (Reserva::with('cuarto')->get() as $r) {
            $fecha = ($r->fecha_pago ?? CarbonImmutable::parse($r->check_in)->addDay())->toDateString();
            $movs->push(['fecha' => $fecha, 'monto' => $r->neto,
                'concepto' => trim("Airbnb {$r->codigo} ".($r->cuarto->nombre ?? '')." {$r->huesped}")]);
        }
        foreach (Gasto::where('de_la_cuenta', true)->get() as $g) {
            $movs->push(['fecha' => $g->fecha->toDateString(), 'monto' => -$g->monto,
                'concepto' => trim("{$g->categoria}: {$g->descripcion}", ': ').($g->personal ? ' (no es del negocio)' : '')]);
        }
        foreach (Movimiento::all() as $mv) {
            $movs->push(['fecha' => $mv->fecha->toDateString(), 'monto' => $mv->monto, 'concepto' => $mv->concepto ?: 'Ajuste']);
        }

        $movs = $movs->filter(fn ($m) => $m['fecha'] >= $fechaSaldo)->sortBy('fecha')->values();
        $porCobrar = $movs->filter(fn ($m) => $m['fecha'] > $hoy && $m['monto'] > 0)->sum('monto');
        $movs = $movs->filter(fn ($m) => $m['fecha'] <= $hoy)->values();

        $saldo = $saldoInicial;
        $meses = [];
        $lista = [];
        foreach ($movs as $m) {
            $saldo += $m['monto'];
            $lista[] = $m + ['saldo' => $saldo];
            $k = substr($m['fecha'], 0, 7);
            $meses[$k] ??= ['mes' => $k, 'entradas' => 0.0, 'salidas' => 0.0];
            $meses[$k][$m['monto'] >= 0 ? 'entradas' : 'salidas'] += abs($m['monto']);
        }
        ksort($meses);
        $s = $saldoInicial;
        foreach ($meses as &$mes) {
            $s += $mes['entradas'] - $mes['salidas'];
            $mes['saldo_final'] = $s;
        }
        unset($mes);

        return [
            'saldo_inicial' => $saldoInicial,
            'fecha_saldo' => $fechaSaldo,
            'saldo_actual' => $saldo,
            'por_cobrar' => $porCobrar,
            'meses' => array_values($meses),
            'movimientos' => array_slice(array_reverse($lista), 0, 200),
        ];
    }
}
