<?php

namespace App\Http\Controllers;

use App\Models\Comprobante;
use App\Models\Cuarto;
use App\Models\Cuenta;
use App\Models\Gasto;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class GastoController extends Controller
{
    public const REGLA_ARCHIVO = 'file|max:15360|mimes:jpg,jpeg,png,webp,heic,heif,pdf,xml';

    public function index(Request $request)
    {
        $f = self::validarFiltros($request);
        $q = self::filtrar(Gasto::query(), $f, $request->user()->id);

        // Reporte por socio de todo lo filtrado (no solo de la página).
        $porSocio = (clone $q)->leftJoin('users', 'users.id', '=', 'gastos.user_id')
            ->groupBy('gastos.user_id', 'users.name')
            ->selectRaw('gastos.user_id, users.name as nombre, count(*) as gastos, sum(gastos.monto) as total,
                sum(case when gastos.personal = 0 then gastos.monto else 0 end) as negocio,
                sum(case when gastos.personal = 1 then gastos.monto else 0 end) as no_negocio,
                sum(case when gastos.cuenta_id is null then gastos.monto else 0 end) as propio')
            ->orderByDesc('total')->get();
        // Lo que se le debe hoy a cada socio (todos sus gastos con dinero propio menos lo ya reembolsado).
        $pendiente = collect((new \App\Services\Finanzas)->porReembolsar())->pluck('monto', 'user_id');

        return view('gastos', [
            'gastos' => $q->with(['cuarto', 'cuenta', 'user', 'capturadoPor', 'comprobantes'])
                ->orderByDesc('fecha')->orderByDesc('id')->paginate(100)->withQueryString(),
            'cuartos' => Cuarto::activos()->orderBy('id')->get(),
            'cuentas' => Cuenta::orderBy('id')->get(),
            'usuarios' => $request->user()->admin ? User::orderBy('name')->get() : collect(),
            'socios' => User::orderBy('name')->get(),
            'filtro' => $f['filtro'],
            'f' => $f,
            'porSocio' => $porSocio,
            'pendiente' => $pendiente,
            'rapidos' => [
                'Este mes' => [now()->startOfMonth(), now()->endOfMonth()],
                'Mes pasado' => [now()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow()->endOfMonth()],
                'Este año' => [now()->startOfYear(), now()->endOfYear()],
            ],
            // Filtros que se conservan en los atajos y en la descarga.
            'base' => array_filter(['filtro' => $f['filtro'] !== 'todos' ? $f['filtro'] : null, 'user_id' => $f['user_id']]),
        ]);
    }

    public static function validarFiltros(Request $request): array
    {
        $f = $request->validate([
            'filtro' => 'nullable|string|max:20',
            'user_id' => 'nullable|integer',
            'desde' => 'nullable|date',
            'hasta' => 'nullable|date',
        ]);

        return ['filtro' => $f['filtro'] ?? 'todos', 'user_id' => $f['user_id'] ?? null,
            'desde' => $f['desde'] ?? null, 'hasta' => $f['hasta'] ?? null];
    }

    /** Filtros de la lista de gastos; los usa también la descarga en CSV. */
    public static function filtrar($q, array $f, ?int $yo = null)
    {
        match ($f['filtro'] ?? 'todos') {
            'negocio' => $q->where('gastos.personal', false),
            'personal' => $q->where('gastos.personal', true),
            'factura' => $q->where('gastos.factura', true),
            'sin-comprobante' => $q->doesntHave('comprobantes'),
            'propio' => $q->whereNull('gastos.cuenta_id'),
            'mios' => $q->where('gastos.user_id', $yo),
            default => null,
        };

        return $q->when($f['user_id'] ?? null, fn ($q, $id) => $q->where('gastos.user_id', $id))
            ->when($f['desde'] ?? null, fn ($q, $d) => $q->where('gastos.fecha', '>=', substr($d, 0, 10)))
            ->when($f['hasta'] ?? null, fn ($q, $d) => $q->where('gastos.fecha', '<=', substr($d, 0, 10)));
    }

    public function store(Request $request)
    {
        $datos = $request->validate([
            'fecha' => 'required|date|before_or_equal:+1 day',
            'monto' => 'required|numeric|min:0.01',
            'categoria' => ['required', Rule::in(Gasto::CATEGORIAS)],
            'cuarto_id' => 'nullable|exists:cuartos,id',
            'descripcion' => 'nullable|string|max:200',
            // Si dice que tiene factura, hay que subirla (PDF o XML del CFDI, o foto).
            'comprobantes' => 'required_if_accepted:factura|nullable|array|max:5',
            'comprobantes.*' => self::REGLA_ARCHIVO,
            'user_id' => 'nullable|exists:users,id',
            'cuenta_id' => 'nullable|exists:cuentas,id',   // vacío = dinero propio, por reembolsar
        ], ['comprobantes.required_if_accepted' => 'Si el gasto tiene factura, sube la factura (PDF, XML o foto).']);
        // Un administrador puede registrar el gasto a nombre de otro socio; un socio, solo a su nombre.
        $duenoId = $request->user()->admin && ! empty($datos['user_id']) ? (int) $datos['user_id'] : $request->user()->id;
        $gasto = Gasto::create([
            'fecha' => $datos['fecha'],
            'monto' => $datos['monto'],
            'categoria' => $datos['categoria'],
            'cuarto_id' => $request->boolean('personal') ? null : ($datos['cuarto_id'] ?? null),
            'descripcion' => $datos['descripcion'] ?? '',
            'personal' => $request->boolean('personal'),
            'factura' => $request->boolean('factura'),
            'cuenta_id' => $datos['cuenta_id'] ?? null,
            'user_id' => $duenoId,
            'capturado_por' => $request->user()->id,
        ]);
        foreach ($request->file('comprobantes', []) as $archivo) {
            $this->guardarArchivo($gasto, $archivo, $request->user()->id);
        }

        return back()->with('ok', 'Gasto guardado.');
    }

    public function destroy(Gasto $gasto)
    {
        Gate::authorize('modificar-gasto', $gasto);
        $gasto->comprobantes->each->delete();   // borra también los archivos
        $gasto->delete();

        return back()->with('ok', 'Gasto borrado.');
    }

    public function subirComprobante(Request $request, Gasto $gasto)
    {
        $request->validate(['comprobantes' => 'required|array|max:5', 'comprobantes.*' => self::REGLA_ARCHIVO]);
        foreach ($request->file('comprobantes') as $archivo) {
            $this->guardarArchivo($gasto, $archivo, $request->user()->id);
        }

        return back()->with('ok', 'Comprobante subido.');
    }

    public function verComprobante(Comprobante $comprobante)
    {
        abort_unless(Storage::disk('local')->exists($comprobante->ruta), 404);

        return Storage::disk('local')->response($comprobante->ruta, $comprobante->nombre_original, [
            'Content-Type' => $comprobante->mime,
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }

    public function borrarComprobante(Comprobante $comprobante)
    {
        Gate::authorize('modificar-gasto', $comprobante->gasto);
        $comprobante->delete();

        return back()->with('ok', 'Comprobante borrado.');
    }

    private function guardarArchivo(Gasto $gasto, UploadedFile $archivo, int $userId): void
    {
        $ruta = $archivo->store('comprobantes/'.$gasto->fecha->format('Y-m'), 'local');
        $gasto->comprobantes()->create([
            'ruta' => $ruta,
            'nombre_original' => mb_substr($archivo->getClientOriginalName(), 0, 200),
            'mime' => $archivo->getMimeType() ?: 'application/octet-stream',
            'tamano' => $archivo->getSize(),
            'user_id' => $userId,
        ]);
    }
}
