<?php

namespace App\Services;

use App\Models\Cuarto;
use App\Models\Cuenta;
use App\Models\Deposito;
use App\Models\Gasto;
use App\Models\Movimiento;
use App\Models\Reserva;
use App\Models\Transferencia;
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
        // Los cuartos desactivados no cuentan para la ocupación ni reciben gastos generales,
        // pero sí salen en la tabla si tuvieron reservas o gastos en el periodo.
        $cuartos = Cuarto::orderBy('id')->get();
        $nCuartos = max(1, $cuartos->where('activo', true)->count());
        $dias = (int) $desde->diffInDays($hasta) + 1;

        $porCuarto = [];
        foreach ($cuartos as $c) {
            $porCuarto[$c->id] = ['id' => $c->id, 'nombre' => $c->nombre, 'noches' => 0, 'reservas' => 0,
                'bruto' => 0.0, 'neto' => 0.0, 'gastos_directos' => 0.0, 'gastos_generales' => 0.0];
        }
        $tot = ['bruto' => 0.0, 'iva_trasladado' => 0.0, 'comision' => 0.0, 'impuestos' => 0.0, 'neto' => 0.0, 'noches' => 0, 'reservas' => 0];

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
            $tot['iva_trasladado'] += $r->iva_trasladado * $f;
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

        $activos = $cuartos->where('activo', true)->pluck('id')->flip();
        $porCuarto = array_filter($porCuarto, fn ($pc) => isset($activos[$pc['id']]) || $pc['noches'] || $pc['gastos_directos']);
        foreach ($porCuarto as &$pc) {
            $pc['gastos_generales'] = isset($activos[$pc['id']]) ? $generales / $nCuartos : 0.0;
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

    /**
     * Saldo de cada cuenta, deuda de la tarjeta, lo que se debe a socios y entradas/salidas por mes.
     *
     * - Los depósitos de Airbnb entran a la cuenta que los recibe, en su fecha de pago.
     * - Un gasto con tarjeta de crédito cuenta como salida el día que se hizo (sube la deuda).
     * - Pagar la tarjeta es una transferencia entre cuentas: no es entrada ni salida del negocio.
     * - Un gasto pagado con dinero propio de un socio no mueve ninguna cuenta hasta que se le reembolsa.
     */
    /** Lo que el negocio le debe a cada socio por gastos que pagó con su dinero, a la fecha $hoy. */
    public function porReembolsar(?CarbonImmutable $hoy = null): array
    {
        $hoy = ($hoy ?? CarbonImmutable::today())->toDateString();
        $porReembolsar = [];
        foreach (Gasto::whereNull('cuenta_id')->where('fecha', '<=', $hoy)->with('user')->get() as $g) {
            $porReembolsar[$g->user_id] ??= ['user_id' => $g->user_id, 'nombre' => $g->user->name ?? 'Sin nombre', 'monto' => 0.0];
            $porReembolsar[$g->user_id]['monto'] += $g->monto;
        }
        foreach (Transferencia::whereNotNull('reembolso_a')->where('fecha', '<=', $hoy)->get() as $t) {
            if (isset($porReembolsar[$t->reembolso_a])) {
                $porReembolsar[$t->reembolso_a]['monto'] -= $t->monto;
            }
        }

        return array_values(array_filter($porReembolsar, fn ($p) => round($p['monto'], 2) > 0));
    }

    public function flujo(?CarbonImmutable $hoy = null): array
    {
        $hoy = ($hoy ?? CarbonImmutable::today())->toDateString();
        $cuentas = Cuenta::orderBy('id')->get()->keyBy('id');
        $airbnb = Cuenta::deAirbnb();
        $desde = fn (?int $cuentaId) => $cuentaId && isset($cuentas[$cuentaId])
            ? $cuentas[$cuentaId]->fecha_saldo->toDateString() : '9999-12-31';

        // Cada renglón: fecha, cuenta, monto (+ entra / − sale) y si es interno (entre cuentas propias).
        $lineas = collect();
        // Reservas importadas de Airbnb: entran por sus depósitos reales (Payout). Las capturadas a mano, por su fecha de pago.
        foreach (Deposito::all() as $d) {
            $lineas->push(['fecha' => $d->fecha->toDateString(), 'cuenta_id' => $airbnb?->id, 'monto' => $d->monto,
                'interno' => false, 'concepto' => 'Depósito de Airbnb']);
        }
        foreach (Reserva::with('cuarto')->where('origen', '!=', 'airbnb')->get() as $r) {
            $fecha = ($r->fecha_pago ?? CarbonImmutable::parse($r->check_in)->addDay())->toDateString();
            $lineas->push(['fecha' => $fecha, 'cuenta_id' => $airbnb?->id, 'monto' => $r->neto, 'interno' => false,
                'concepto' => trim("Airbnb {$r->codigo} ".($r->cuarto->nombre ?? '')." {$r->huesped}")]);
        }
        foreach (Gasto::whereNotNull('cuenta_id')->get() as $g) {
            $lineas->push(['fecha' => $g->fecha->toDateString(), 'cuenta_id' => $g->cuenta_id, 'monto' => -$g->monto,
                'interno' => false,
                'concepto' => trim("{$g->categoria}: {$g->descripcion}", ': ').($g->personal ? ' (no es del negocio)' : '')]);
        }
        foreach (Transferencia::with(['destino', 'socio'])->get() as $t) {
            $fecha = $t->fecha->toDateString();
            $concepto = $t->destino_id
                ? "Pago a {$t->destino->nombre}"
                : 'Reembolso a '.($t->socio->name ?? 'socio');
            $concepto .= $t->concepto ? " ({$t->concepto})" : '';
            $lineas->push(['fecha' => $fecha, 'cuenta_id' => $t->origen_id, 'monto' => -$t->monto,
                'interno' => (bool) $t->destino_id, 'concepto' => $concepto]);
            if ($t->destino_id) {
                $lineas->push(['fecha' => $fecha, 'cuenta_id' => $t->destino_id, 'monto' => $t->monto,
                    'interno' => true, 'concepto' => "Pago desde {$cuentas[$t->origen_id]->nombre}"]);
            }
        }
        foreach (Movimiento::all() as $mv) {
            $lineas->push(['fecha' => $mv->fecha->toDateString(), 'cuenta_id' => $mv->cuenta_id, 'monto' => $mv->monto,
                'interno' => false, 'concepto' => $mv->concepto ?: 'Ajuste']);
        }

        // Solo cuenta lo que pasó desde la fecha del saldo inicial de cada cuenta.
        $lineas = $lineas->filter(fn ($l) => $l['fecha'] >= $desde($l['cuenta_id']))->sortBy('fecha')->values();
        $porCobrar = $lineas->filter(fn ($l) => $l['fecha'] > $hoy && $l['monto'] > 0)->sum('monto');
        $lineas = $lineas->filter(fn ($l) => $l['fecha'] <= $hoy)->values();

        $saldos = $cuentas->map(fn (Cuenta $c) => $c->saldo_inicial)->all();
        $meses = [];
        foreach ($lineas as $l) {
            $saldos[$l['cuenta_id']] += $l['monto'];
            if ($l['interno']) {
                continue;
            }
            $k = substr($l['fecha'], 0, 7);
            $meses[$k] ??= ['mes' => $k, 'entradas' => 0.0, 'salidas' => 0.0];
            $meses[$k][$l['monto'] >= 0 ? 'entradas' : 'salidas'] += abs($l['monto']);
        }
        ksort($meses);
        $s = $cuentas->sum('saldo_inicial');
        foreach ($meses as &$mes) {
            $s += $mes['entradas'] - $mes['salidas'];
            $mes['saldo_final'] = $s;
        }
        unset($mes);

        $porReembolsar = $this->porReembolsar(CarbonImmutable::parse($hoy));
        $totalReembolsar = array_sum(array_column($porReembolsar, 'monto'));

        $listaCuentas = $cuentas->map(fn (Cuenta $c) => [
            'id' => $c->id, 'nombre' => $c->nombre, 'tipo' => $c->tipo, 'saldo' => $saldos[$c->id],
            'saldo_inicial' => $c->saldo_inicial, 'fecha_saldo' => $c->fecha_saldo->toDateString(),
        ])->values()->all();

        return [
            'cuentas' => $listaCuentas,
            'efectivo' => collect($listaCuentas)->where('tipo', 'debito')->sum('saldo'),
            'deuda_tarjetas' => -collect($listaCuentas)->where('tipo', 'credito')->sum('saldo'),
            'por_reembolsar' => $porReembolsar,
            'total_reembolsar' => $totalReembolsar,
            'disponible' => array_sum($saldos) - $totalReembolsar,
            'por_cobrar' => $porCobrar,
            'meses' => array_values($meses),
            'movimientos' => $lineas->reverse()->take(200)->map(fn ($l) => $l + [
                'cuenta' => $cuentas[$l['cuenta_id']]->nombre ?? '—',
            ])->values()->all(),
        ];
    }
}
