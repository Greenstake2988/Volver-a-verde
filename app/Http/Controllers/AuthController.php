<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    public function login()
    {
        return User::exists() ? view('auth.login') : redirect()->route('primer-usuario');
    }

    public function entrar(Request $request)
    {
        $datos = $request->validate(['usuario' => 'required|string', 'password' => 'required|string']);
        $datos['usuario'] = mb_strtolower(trim($datos['usuario']));
        if (! Auth::attempt($datos, remember: true)) {
            return back()->withErrors(['usuario' => 'Usuario o contraseña incorrectos.'])->onlyInput('usuario');
        }
        $request->session()->regenerate();

        // Los socios casi siempre entran a registrar un gasto.
        return Auth::user()->admin
            ? redirect()->intended(route('resumen'))
            : redirect()->route('gastos.index');
    }

    public function salir(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    /** La primera vez que se abre la app se crea el administrador. */
    public function primerUsuario()
    {
        return User::exists() ? redirect()->route('login') : view('auth.primer-usuario');
    }

    public function crearPrimerUsuario(Request $request)
    {
        abort_if(User::exists(), 403);
        $datos = $request->validate([
            'name' => 'required|string|max:100',
            'usuario' => 'required|alpha_dash|max:40',
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);
        $user = User::create(['name' => $datos['name'], 'usuario' => mb_strtolower($datos['usuario']),
            'password' => $datos['password'], 'admin' => true]);
        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->route('ajustes')->with('ok', 'Listo. Ahora da de alta a los socios.');
    }
}
