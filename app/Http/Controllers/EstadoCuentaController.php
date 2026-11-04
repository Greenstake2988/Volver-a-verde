<?php

namespace App\Http\Controllers;

use App\Models\Cuenta;
use App\Models\EstadoCuenta;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class EstadoCuentaController extends Controller
{
    public const REGLA_ARCHIVO = 'file|max:15360|mimes:pdf,jpg,jpeg,png,webp,heic,heif,csv,txt,xls,xlsx,xml';

    public function index(Request $request)
    {
        $anio = (int) $request->integer('anio', (int) now()->year);
        $anio = max(2000, min($anio, (int) now()->year + 1));
        $meses = collect(range(1, 12))->map(fn ($m) => sprintf('%04d-%02d', $anio, $m));

        $cuentas = Cuenta::with('usuario')->orderBy('id')->get();
        $estados = EstadoCuenta::with('user')->where('mes', 'like', "$anio-%")->orderBy('created_at')->get()
            ->groupBy(fn ($e) => $e->cuenta_id.'|'.$e->mes);

        $yo = $request->user();
        $puedeSubir = $cuentas->filter(fn ($c) => self::puedeSubir($yo, $c))->values();

        return view('estados', [
            'anio' => $anio, 'meses' => $meses, 'cuentas' => $cuentas, 'estados' => $estados,
            'puedeSubir' => $puedeSubir, 'mesActual' => now()->format('Y-m'),
            'nombresMes' => ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'],
        ]);
    }

    public function store(Request $request)
    {
        $d = $request->validate([
            'cuenta_id' => 'required|exists:cuentas,id',
            'mes' => 'required|date_format:Y-m',
            'archivos' => 'required|array|max:5',
            'archivos.*' => self::REGLA_ARCHIVO,
        ], ['archivos.required' => 'Elige el archivo del estado de cuenta.']);

        $cuenta = Cuenta::findOrFail($d['cuenta_id']);
        abort_unless(self::puedeSubir($request->user(), $cuenta), 403, 'Esa tarjeta la usa otro socio.');

        foreach ($request->file('archivos') as $archivo) {
            EstadoCuenta::create([
                'cuenta_id' => $cuenta->id,
                'mes' => $d['mes'],
                'ruta' => $archivo->store('estados/'.$d['mes'], 'local'),
                'nombre_original' => mb_substr($archivo->getClientOriginalName(), 0, 200),
                'mime' => $archivo->getMimeType() ?: 'application/octet-stream',
                'tamano' => $archivo->getSize(),
                'user_id' => $request->user()->id,
            ]);
        }

        return redirect()->route('estados.index', ['anio' => substr($d['mes'], 0, 4)])
            ->with('ok', "Estado de cuenta de {$cuenta->nombre} ({$d['mes']}) subido.");
    }

    public function show(EstadoCuenta $estado)
    {
        abort_unless(Storage::disk('local')->exists($estado->ruta), 404);

        return Storage::disk('local')->response($estado->ruta, $estado->nombre_original, [
            'Content-Type' => $estado->mime,
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }

    public function destroy(Request $request, EstadoCuenta $estado)
    {
        abort_unless($request->user()->admin || $estado->user_id === $request->user()->id, 403);
        $estado->delete();

        return back()->with('ok', 'Estado de cuenta borrado.');
    }

    /** El admin sube de todas; un socio, de las tarjetas que usa él o que no tienen a nadie asignado. */
    public static function puedeSubir(User $user, Cuenta $cuenta): bool
    {
        return $user->admin || $cuenta->user_id === null || $cuenta->user_id === $user->id;
    }
}
