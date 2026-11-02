<?php

namespace App\Http\Controllers;

use App\Models\Gasto;
use App\Models\Reserva;
use App\Services\Importador;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/** Importar CSV y descargar el reporte para el contador. */
class DatosController extends Controller
{
    public function index()
    {
        $hoy = CarbonImmutable::today();

        return view('datos', [
            'desde' => $hoy->subMonthNoOverflow()->startOfMonth()->toDateString(),
            'hasta' => $hoy->subMonthNoOverflow()->endOfMonth()->toDateString(),
        ]);
    }

    public function importarAirbnb(Request $request, Importador $importador)
    {
        $request->validate(['archivo' => 'required|file|max:10240|mimes:csv,txt']);
        $r = $importador->airbnb($request->file('archivo')->get());

        return back()->with('ok', "Listo: {$r['nuevas']} reservas nuevas y {$r['actualizadas']} actualizadas ({$r['filas']} filas leídas).");
    }

    public function importarGastos(Request $request, Importador $importador)
    {
        $request->validate(['archivo' => 'required|file|max:10240|mimes:csv,txt']);
        $r = $importador->gastos($request->file('archivo')->get(), $request->user()->id);
        $extra = $r['omitidos'] ? ", {$r['omitidos']} filas omitidas (sin fecha o monto)" : '';

        return back()->with('ok', "Listo: {$r['importados']} gastos importados{$extra}.");
    }

    public function exportar(Request $request, string $tipo)
    {
        $datos = $request->validate(['desde' => 'required|date', 'hasta' => 'required|date|after_or_equal:desde']);
        abort_unless(in_array($tipo, ['reservas', 'gastos'], true), 404);
        $nombre = "{$tipo}_{$datos['desde']}_{$datos['hasta']}.csv";

        return response()->streamDownload(function () use ($tipo, $datos) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");   // BOM para que Excel lea bien los acentos
            if ($tipo === 'reservas') {
                fputcsv($out, ['Código', 'Cuarto', 'Huésped', 'Check-in', 'Noches', 'Ingreso bruto',
                    'Comisión Airbnb', 'Impuestos retenidos', 'Neto depositado', 'Fecha de pago'], ',', '"', '');
                Reserva::with('cuarto')->whereBetween('check_in', [$datos['desde'], $datos['hasta']])
                    ->orderBy('check_in')->each(function (Reserva $r) use ($out) {
                        fputcsv($out, [$r->codigo, $r->cuarto->nombre ?? '', $r->huesped, $r->check_in->toDateString(),
                            $r->noches, self::n($r->bruto), self::n($r->comision), self::n($r->impuestos),
                            self::n($r->neto), $r->fecha_pago?->toDateString()], ',', '"', '');
                    });
            } else {
                fputcsv($out, ['Fecha', 'Categoría', 'Cuarto', 'Descripción', 'Monto', 'Tiene factura',
                    'Del negocio', 'Pagado con', 'Pagó', 'Comprobantes'], ',', '"', '');
                Gasto::with(['cuarto', 'cuenta', 'user'])->withCount('comprobantes')
                    ->whereBetween('fecha', [$datos['desde'], $datos['hasta']])->orderBy('fecha')
                    ->each(function (Gasto $g) use ($out) {
                        fputcsv($out, [$g->fecha->toDateString(), $g->categoria,
                            $g->personal ? '' : ($g->cuarto->nombre ?? 'General'), $g->descripcion, self::n($g->monto),
                            $g->factura ? 'Sí' : 'No', $g->personal ? 'No' : 'Sí', $g->cuenta->nombre ?? 'Dinero propio (por reembolsar)',
                            $g->user->name ?? '', $g->comprobantes_count], ',', '"', '');
                    });
            }
            fclose($out);
        }, $nombre, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private static function n(float $v): string
    {
        return number_format($v, 2, '.', '');
    }
}
