<?php

namespace App\Http\Controllers;

use App\Models\Movimiento;
use App\Services\Finanzas;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class PanelController extends Controller
{
    public static function periodo(Request $request): array
    {
        $hoy = CarbonImmutable::today();
        $p = $request->query('periodo', 'mes');

        return match ($p) {
            'mes-1' => [$p, $hoy->subMonthNoOverflow()->startOfMonth(), $hoy->subMonthNoOverflow()->endOfMonth()->startOfDay()],
            '3m' => [$p, $hoy->subMonthsNoOverflow(2)->startOfMonth(), $hoy],
            'anio' => [$p, $hoy->startOfYear(), $hoy],
            '12m' => [$p, $hoy->subMonthsNoOverflow(11)->startOfMonth(), $hoy],
            'custom' => [$p, CarbonImmutable::parse($request->query('desde', $hoy->startOfMonth()->toDateString())),
                CarbonImmutable::parse($request->query('hasta', $hoy->toDateString()))],
            default => ['mes', $hoy->startOfMonth(), $hoy],
        };
    }

    public function resumen(Request $request, Finanzas $finanzas)
    {
        [$periodo, $desde, $hasta] = self::periodo($request);
        $r = $finanzas->resumen($desde, $hasta);

        return view('resumen', ['r' => $r, 'periodo' => $periodo, 'conclusion' => $finanzas->conclusiones($r)]);
    }

    public function flujo(Finanzas $finanzas)
    {
        return view('flujo', ['f' => $finanzas->flujo()]);
    }

    public function guardarMovimiento(Request $request)
    {
        Movimiento::create($request->validate([
            'fecha' => 'required|date',
            'monto' => 'required|numeric|not_in:0',
            'concepto' => 'nullable|string|max:200',
        ]) + ['concepto' => '']);

        return back()->with('ok', 'Ajuste agregado.');
    }
}
