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
            'caja' => Cuenta::caja(),
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
        $destino = Cuenta::find($d['destino_id']);
        $origen = Cuenta::find($d['origen_id']);

        return back()->with('ok', match (true) {
            $destino->esEfectivo() => 'Listo: '.dinero($d['monto'])." pasaron a {$destino->nombre}.",
            $origen->esEfectivo() => 'Listo: '.dinero($d['monto'])." salieron de {$origen->nombre} a {$destino->nombre}.",
            $destino->esCredito() => 'Pago registrado.',
            default => 'Traspaso registrado.',
        });
    }

    /**
     * Contar la caja chica: se captura cuánto efectivo hay de verdad y la diferencia con lo que
     * dice la app queda como un ajuste (lo que faltó sale, lo que sobró entra).
     */
    public function contarCaja(Request $request, Finanzas $finanzas)
    {
        $d = $request->validate([
            'cuenta_id' => 'required|exists:cuentas,id',
            'fecha' => 'required|date',
            'contado' => 'required|numeric|min:0',
        ]);
        $caja = Cuenta::findOrFail($d['cuenta_id']);
        abort_unless($caja->esEfectivo(), 422, 'Solo se cuenta una caja de efectivo.');
        $saldo = collect($finanzas->flujo(CarbonImmutable::parse($d['fecha']))['cuentas'])->firstWhere('id', $caja->id)['saldo'];
        $diferencia = round((float) $d['contado'] - $saldo, 2);
        if ($diferencia == 0) {
            return back()->with('ok', 'La caja cuadra: hay '.dinero($saldo).'.');
        }
        Movimiento::create(['cuenta_id' => $caja->id, 'fecha' => $d['fecha'], 'monto' => $diferencia,
            'concepto' => $diferencia < 0 ? 'Faltante al contar la caja' : 'Sobrante al contar la caja']);

        return back()->with('ok', $diferencia < 0
            ? 'Faltaron '.dinero(-$diferencia).' en la caja; quedó registrado como salida. Si sabes en qué se fue, captúralo como gasto y borra este ajuste.'
            : 'Sobraron '.dinero($diferencia).' en la caja; quedó registrado como entrada.');
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

    /** Borrar un pago de tarjeta o un reembolso capturado por error (quita sus dos renglones). */
    public function borrarTransferencia(Transferencia $transferencia)
    {
        $transferencia->delete();

        return back()->with('ok', $transferencia->destino_id ? 'Traspaso borrado.' : 'Reembolso borrado.');
    }

    /** Borrar un ajuste con el banco o el pago de un socio capturado por error. */
    public function borrarMovimiento(Movimiento $movimiento)
    {
        $movimiento->delete();

        return back()->with('ok', $movimiento->user_id ? 'Pago del socio borrado.' : 'Ajuste borrado.');
    }
}
