<?php

namespace App\Http\Controllers;

use App\Models\Cuenta;
use App\Models\Movimiento;
use App\Models\Transferencia;
use App\Models\User;
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
        return view('flujo', [
            'f' => $finanzas->flujo(),
            'cuentas' => Cuenta::orderBy('id')->get(),
            'usuarios' => User::orderBy('name')->get(),
        ]);
    }

    /** Pago de la tarjeta de crédito (o cualquier traspaso entre cuentas propias). */
    public function guardarTransferencia(Request $request)
    {
        $d = $request->validate([
            'fecha' => 'required|date',
            'monto' => 'required|numeric|min:0.01',
            'origen_id' => 'required|exists:cuentas,id',
            'destino_id' => 'required|exists:cuentas,id|different:origen_id',
            'concepto' => 'nullable|string|max:200',
        ]);
        Transferencia::create($d + ['concepto' => '']);

        return back()->with('ok', 'Pago registrado.');
    }

    /** Devolverle a un socio lo que pagó con su dinero. */
    public function guardarReembolso(Request $request)
    {
        $d = $request->validate([
            'fecha' => 'required|date',
            'monto' => 'required|numeric|min:0.01',
            'origen_id' => 'required|exists:cuentas,id',
            'reembolso_a' => 'required|exists:users,id',
            'concepto' => 'nullable|string|max:200',
        ]);
        Transferencia::create($d + ['concepto' => '']);

        return back()->with('ok', 'Reembolso registrado.');
    }

    /** Un socio le paga al negocio lo que cargó de gastos personales a una tarjeta del negocio. */
    public function guardarAbono(Request $request)
    {
        $d = $request->validate([
            'fecha' => 'required|date',
            'monto' => 'required|numeric|min:0.01',
            'cuenta_id' => 'required|exists:cuentas,id',
            'user_id' => 'required|exists:users,id',
            'concepto' => 'nullable|string|max:200',
        ]);
        $socio = User::find($d['user_id']);
        Movimiento::create($d + ['concepto' => '']);

        return back()->with('ok', "Pago de {$socio->name} registrado.");
    }

    public function guardarMovimiento(Request $request)
    {
        Movimiento::create($request->validate([
            'cuenta_id' => 'required|exists:cuentas,id',
            'fecha' => 'required|date',
            'monto' => 'required|numeric|not_in:0',
            'concepto' => 'nullable|string|max:200',
        ]) + ['concepto' => '']);

        return back()->with('ok', 'Ajuste agregado.');
    }
}
