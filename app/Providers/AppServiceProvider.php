<?php

namespace App\Providers;

use App\Models\Gasto;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Administradores: importan, capturan reservas, cambian ajustes y usuarios.
        Gate::define('admin', fn (User $user) => $user->admin);

        // Un socio solo puede borrar lo que él registró; el administrador, todo.
        Gate::define('modificar-gasto', fn (User $user, Gasto $gasto) => $user->admin || $gasto->user_id === $user->id);

        // Contraseñas de 8 o más caracteres, sin reglas de mayúsculas ni números.
        // Lo que protege el login en internet es el bloqueo tras 5 intentos fallidos (abajo).
        Password::defaults(fn () => Password::min(8));

        // Máximo 5 intentos de login por minuto por usuario + IP.
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)
            ->by(mb_strtolower((string) $request->input('usuario')).'|'.$request->ip()));
    }
}
