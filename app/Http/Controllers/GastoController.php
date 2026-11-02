<?php

namespace App\Http\Controllers;

use App\Models\Comprobante;
use App\Models\Cuarto;
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
        $filtro = $request->query('filtro', 'todos');
        $q = Gasto::with(['cuarto', 'user', 'capturadoPor', 'comprobantes'])->orderByDesc('fecha')->orderByDesc('id');
        match ($filtro) {
            'negocio' => $q->where('personal', false),
            'personal' => $q->where('personal', true),
            'factura' => $q->where('factura', true),
            'sin-comprobante' => $q->doesntHave('comprobantes'),
            'mios' => $q->where('user_id', $request->user()->id),
            default => null,
        };

        return view('gastos', [
            'gastos' => $q->paginate(100)->withQueryString(),
            'cuartos' => Cuarto::orderBy('id')->get(),
            'usuarios' => $request->user()->admin ? User::orderBy('name')->get() : collect(),
            'filtro' => $filtro,
        ]);
    }

    public function store(Request $request)
    {
        $datos = $request->validate([
            'fecha' => 'required|date|before_or_equal:+1 day',
            'monto' => 'required|numeric|min:0.01',
            'categoria' => ['required', Rule::in(Gasto::CATEGORIAS)],
            'cuarto_id' => 'nullable|exists:cuartos,id',
            'descripcion' => 'nullable|string|max:200',
            'comprobantes' => 'nullable|array|max:5',
            'comprobantes.*' => self::REGLA_ARCHIVO,
            'user_id' => 'nullable|exists:users,id',
        ]);
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
            'de_la_cuenta' => $request->boolean('de_la_cuenta'),
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
