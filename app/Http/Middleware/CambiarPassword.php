<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Quien tiene una contraseña vieja o temporal solo puede ir a Ajustes a cambiarla (o salir). */
class CambiarPassword
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->cambiar_password && ! $request->routeIs('ajustes', 'ajustes.password', 'logout')) {
            return redirect()->route('ajustes');
        }

        return $next($request);
    }
}
