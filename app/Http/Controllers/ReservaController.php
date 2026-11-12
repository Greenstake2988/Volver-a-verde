<?php

namespace App\Http\Controllers;

use App\Models\Cuarto;
use App\Models\Cuenta;
use App\Models\Reserva;
use Illuminate\Http\Request;

class ReservaController extends Controller
{
    public function index(Request $request)
    {
        $f = $request->validate([
            'cuarto_id' => 'nullable|integer',
            'desde' => 'nullable|date',
            'hasta' => 'nullable|date',
        ]);
        $q = self::filtrar(Reserva::query(), $f);
        // Totales de todo lo filtrado (no solo de la página que se ve).
        $t = (clone $q)->selectRaw('count(*) as reservas, coalesce(sum(noches),0) as noches, coalesce(sum(bruto),0) as bruto,
            coalesce(sum(iva_trasladado),0) as iva, coalesce(sum(comision),0) as comision, coalesce(sum(impuestos),0) as impuestos,
            coalesce(sum(ish),0) as ish')->first();

        return view('reservas', [
            'reservas' => $q->with(['cuarto', 'capturadoPor'])->orderByDesc('check_in')->paginate(100)->withQueryString(),
            'cuartos' => Cuarto::activos()->orderBy('id')->get(),
            'caja' => Cuenta::caja(),
            'todosCuartos' => Cuarto::orderBy('id')->get(),
            'f' => $f + ['cuarto_id' => null, 'desde' => null, 'hasta' => null],
            'tot' => $t,
            'neto' => round($t->bruto + $t->iva - $t->comision - $t->impuestos, 2),
        ]);
    }

    /** Filtro por cuarto y por fecha de check-in (como los informes de Airbnb). */
    public static function filtrar($q, array $f)
    {
        return $q->when($f['cuarto_id'] ?? null, fn ($q, $id) => $q->where('cuarto_id', $id))
            ->when($f['desde'] ?? null, fn ($q, $d) => $q->where('check_in', '>=', substr($d, 0, 10)))
            ->when($f['hasta'] ?? null, fn ($q, $d) => $q->where('check_in', '<=', substr($d, 0, 10)));
    }

    public function store(Request $request)
    {
        $datos = $request->validate([
            'cuarto_id' => 'required|exists:cuartos,id',
            'huesped' => 'nullable|string|max:100',
            'check_in' => 'required|date',
            'noches' => 'required|integer|min:1|max:365',
            'bruto' => 'required|numeric|min:0',
            'comision' => 'nullable|numeric|min:0',
            'impuestos' => 'nullable|numeric|min:0',
            'fecha_pago' => 'nullable|date',
            'codigo' => 'nullable|string|max:40|unique:reservas,codigo',
        ]);
        Reserva::create(array_merge(['huesped' => '', 'comision' => 0, 'impuestos' => 0],
            array_filter($datos, fn ($v) => $v !== null)));

        return back()->with('ok', 'Reserva guardada.');
    }

    /**
     * Renta de un cuarto cobrada en efectivo, fuera de Airbnb. Cualquier socio la puede capturar.
     * Cuenta como ingreso del cuarto (utilidad y ocupación) y el dinero entra a la caja chica.
     */
    public function efectivo(Request $request)
    {
        $caja = Cuenta::caja();
        abort_unless($caja, 422, 'Primero crea una cuenta de efectivo (caja chica) en Ajustes.');
        $d = $request->validate([
            'cuarto_id' => 'required|exists:cuartos,id',
            'huesped' => 'nullable|string|max:100',
            'check_in' => 'required|date',
            'noches' => 'required|integer|min:1|max:365',
            'monto' => 'required|numeric|min:0.01',
            'fecha_pago' => 'required|date',
            'notas' => 'nullable|string|max:500',
        ]);
        $r = Reserva::create(['cuarto_id' => $d['cuarto_id'], 'huesped' => $d['huesped'] ?? '', 'check_in' => $d['check_in'],
            'noches' => $d['noches'], 'bruto' => $d['monto'], 'comision' => 0, 'impuestos' => 0,
            'fecha_pago' => $d['fecha_pago'], 'notas' => $d['notas'] ?? null, 'origen' => 'efectivo',
            'cuenta_id' => $caja->id, 'capturado_por' => $request->user()->id]);

        return back()->with('ok', 'Renta en efectivo guardada: '.dinero($r->bruto)." de {$r->cuarto->nombre}, entró a {$caja->nombre}.");
    }

    public function destroy(Reserva $reserva)
    {
        $reserva->delete();

        return back()->with('ok', 'Reserva borrada.');
    }
}
