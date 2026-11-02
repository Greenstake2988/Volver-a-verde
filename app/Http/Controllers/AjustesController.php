<?php

namespace App\Http\Controllers;

use App\Models\Cuarto;
use App\Models\Cuenta;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class AjustesController extends Controller
{
    public function index()
    {
        return view('ajustes', [
            'usuarios' => User::orderBy('id')->get(),
            'cuartos' => Cuarto::orderBy('id')->get(),
            'cuentas' => Cuenta::orderBy('id')->get(),
        ]);
    }

    public function cuentas(Request $request)
    {
        $d = $request->validate([
            'cuentas' => 'required|array',
            'cuentas.*.nombre' => 'required|string|max:60',
            'cuentas.*.saldo' => 'required|numeric',
            'cuentas.*.fecha_saldo' => 'required|date',
            'cuentas.*.user_id' => 'nullable|exists:users,id',
            'recibe_airbnb' => 'nullable|exists:cuentas,id',
        ]);
        foreach ($d['cuentas'] as $id => $c) {
            $cuenta = Cuenta::findOrFail($id);
            // En crédito se captura lo que se debe (positivo) y se guarda como saldo negativo.
            $saldo = $cuenta->esCredito() ? -abs((float) $c['saldo']) : (float) $c['saldo'];
            $cuenta->update(['nombre' => $c['nombre'], 'saldo_inicial' => $saldo, 'fecha_saldo' => $c['fecha_saldo'],
                'user_id' => $c['user_id'] ?? null,
                'recibe_airbnb' => (int) ($d['recibe_airbnb'] ?? 0) === $cuenta->id]);
        }

        return back()->with('ok', 'Cuentas guardadas.');
    }

    public function crearCuenta(Request $request)
    {
        $d = $request->validate([
            'nombre' => 'required|string|max:60',
            'tipo' => 'required|in:'.implode(',', array_keys(Cuenta::TIPOS)),
            'saldo' => 'required|numeric',
            'fecha_saldo' => 'required|date',
            'user_id' => 'nullable|exists:users,id',
        ]);
        Cuenta::create(['nombre' => $d['nombre'], 'tipo' => $d['tipo'], 'fecha_saldo' => $d['fecha_saldo'], 'user_id' => $d['user_id'] ?? null,
            'saldo_inicial' => $d['tipo'] === 'credito' ? -abs((float) $d['saldo']) : (float) $d['saldo']]);

        return back()->with('ok', "Cuenta {$d['nombre']} creada.");
    }

    public function cuartos(Request $request)
    {
        $d = $request->validate([
            'cuartos' => 'required|array',
            'cuartos.*.nombre' => 'required|string|max:60',
            'cuartos.*.anuncio_airbnb' => 'nullable|string|max:200',
            'cuartos.*.activo' => 'nullable|boolean',
        ]);
        foreach ($d['cuartos'] as $id => $c) {
            Cuarto::whereKey($id)->update(['nombre' => $c['nombre'], 'anuncio_airbnb' => $c['anuncio_airbnb'] ?? '',
                'activo' => (bool) ($c['activo'] ?? false)]);
        }

        return back()->with('ok', 'Cuartos guardados.');
    }

    public function crearCuarto(Request $request)
    {
        $d = $request->validate([
            'nombre' => 'required|string|max:60',
            'anuncio_airbnb' => 'nullable|string|max:200',
        ]);
        Cuarto::create(['nombre' => $d['nombre'], 'anuncio_airbnb' => $d['anuncio_airbnb'] ?? '']);

        return back()->with('ok', "Cuarto {$d['nombre']} agregado.");
    }

    public function borrarCuarto(Cuarto $cuarto)
    {
        // Con historial no se borra: se perderían de qué cuarto eran sus reservas y gastos.
        if ($cuarto->reservas()->exists() || $cuarto->gastos()->exists()) {
            return back()->withErrors(['cuarto' => "{$cuarto->nombre} tiene reservas o gastos; desactívalo en lugar de borrarlo."]);
        }
        $cuarto->delete();

        return back()->with('ok', "Cuarto {$cuarto->nombre} borrado.");
    }

    public function limites(Request $request)
    {
        $d = $request->validate(['limites' => 'array', 'limites.*' => 'nullable|numeric|min:0']);
        foreach ($d['limites'] ?? [] as $id => $limite) {
            User::whereKey($id)->update(['limite_mensual' => $limite === null || $limite === '' ? null : (float) $limite]);
        }

        return back()->with('ok', 'Topes guardados.');
    }

    public function crearUsuario(Request $request)
    {
        $request->merge(['usuario' => mb_strtolower(trim((string) $request->input('usuario')))]);
        $d = $request->validate([
            'name' => 'required|string|max:100',
            'usuario' => 'required|alpha_dash|max:40|unique:users,usuario',
            'password' => ['required', Password::min(8)],
        ]);
        User::create($d + ['admin' => $request->boolean('admin')]);

        return back()->with('ok', "Usuario {$d['usuario']} creado. Pásale su contraseña para que entre.");
    }

    public function borrarUsuario(Request $request, User $user)
    {
        abort_if($user->is($request->user()), 422, 'No puedes borrar tu propio usuario.');
        $user->delete();

        return back()->with('ok', 'Usuario borrado. Sus gastos se conservan.');
    }

    public function password(Request $request)
    {
        $d = $request->validate([
            'actual' => 'required|current_password',
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);
        $request->user()->update(['password' => $d['password']]);

        return back()->with('ok', 'Contraseña cambiada.');
    }
}
