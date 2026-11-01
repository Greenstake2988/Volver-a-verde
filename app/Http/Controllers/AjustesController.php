<?php

namespace App\Http\Controllers;

use App\Models\Ajuste;
use App\Models\Cuarto;
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
            'saldo' => Ajuste::valor('saldo_inicial', '0'),
            'fechaSaldo' => Ajuste::valor('fecha_saldo'),
        ]);
    }

    public function saldo(Request $request)
    {
        $d = $request->validate(['saldo_inicial' => 'required|numeric', 'fecha_saldo' => 'required|date']);
        Ajuste::guardar('saldo_inicial', (string) $d['saldo_inicial']);
        Ajuste::guardar('fecha_saldo', $d['fecha_saldo']);

        return back()->with('ok', 'Saldo guardado.');
    }

    public function cuartos(Request $request)
    {
        $d = $request->validate([
            'cuartos' => 'required|array',
            'cuartos.*.nombre' => 'required|string|max:60',
            'cuartos.*.anuncio_airbnb' => 'nullable|string|max:200',
        ]);
        foreach ($d['cuartos'] as $id => $c) {
            Cuarto::whereKey($id)->update(['nombre' => $c['nombre'], 'anuncio_airbnb' => $c['anuncio_airbnb'] ?? '']);
        }

        return back()->with('ok', 'Cuartos guardados.');
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
