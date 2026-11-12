<?php

use App\Http\Controllers\AjustesController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DatosController;
use App\Http\Controllers\EstadoCuentaController;
use App\Http\Controllers\GastoController;
use App\Http\Controllers\PanelController;
use App\Http\Controllers\ReservaController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'login'])->name('login');
    Route::post('/login', [AuthController::class, 'entrar'])->middleware('throttle:login');
    Route::get('/primer-usuario', [AuthController::class, 'primerUsuario'])->name('primer-usuario');
    Route::post('/primer-usuario', [AuthController::class, 'crearPrimerUsuario'])->middleware('throttle:login');
});

Route::middleware(['auth', 'cambiar-password'])->group(function () {
    Route::post('/logout', [AuthController::class, 'salir'])->name('logout');

    // Todos los usuarios (socios y administradores)
    Route::get('/', [PanelController::class, 'resumen'])->name('resumen');
    Route::get('/flujo', [PanelController::class, 'flujo'])->name('flujo');
    Route::get('/reservas', [ReservaController::class, 'index'])->name('reservas.index');
    Route::post('/reservas/efectivo', [ReservaController::class, 'efectivo'])->name('reservas.efectivo');
    Route::get('/gastos', [GastoController::class, 'index'])->name('gastos.index');
    Route::post('/gastos', [GastoController::class, 'store'])->name('gastos.store');
    Route::delete('/gastos/{gasto}', [GastoController::class, 'destroy'])->name('gastos.destroy');
    Route::post('/gastos/{gasto}/comprobantes', [GastoController::class, 'subirComprobante'])->name('comprobantes.store');
    Route::get('/comprobantes/{comprobante}', [GastoController::class, 'verComprobante'])->name('comprobantes.show');
    Route::delete('/comprobantes/{comprobante}', [GastoController::class, 'borrarComprobante'])->name('comprobantes.destroy');
    Route::get('/estados-de-cuenta', [EstadoCuentaController::class, 'index'])->name('estados.index');
    Route::post('/estados-de-cuenta', [EstadoCuentaController::class, 'store'])->name('estados.store');
    Route::get('/estados-de-cuenta/{estado}', [EstadoCuentaController::class, 'show'])->name('estados.show');
    Route::delete('/estados-de-cuenta/{estado}', [EstadoCuentaController::class, 'destroy'])->name('estados.destroy');
    Route::get('/ajustes', [AjustesController::class, 'index'])->name('ajustes');
    Route::post('/ajustes/password', [AjustesController::class, 'password'])->name('ajustes.password');

    // Solo administradores
    Route::middleware('can:admin')->group(function () {
        Route::get('/gastos/{gasto}/editar', [GastoController::class, 'edit'])->name('gastos.edit');
        Route::put('/gastos/{gasto}', [GastoController::class, 'update'])->name('gastos.update');
        Route::post('/movimientos', [PanelController::class, 'guardarMovimiento'])->name('movimientos.store');
        Route::post('/reservas', [ReservaController::class, 'store'])->name('reservas.store');
        Route::delete('/reservas/{reserva}', [ReservaController::class, 'destroy'])->name('reservas.destroy');
        Route::get('/datos', [DatosController::class, 'index'])->name('datos');
        Route::post('/datos/airbnb', [DatosController::class, 'importarAirbnb'])->name('datos.airbnb');
        Route::post('/datos/gastos', [DatosController::class, 'importarGastos'])->name('datos.gastos');
        Route::get('/datos/exportar/{tipo}', [DatosController::class, 'exportar'])->name('datos.exportar');
        Route::post('/transferencias', [PanelController::class, 'guardarTransferencia'])->name('transferencias.store');
        Route::post('/caja/contar', [PanelController::class, 'contarCaja'])->name('caja.contar');
        Route::delete('/transferencias/{transferencia}', [PanelController::class, 'borrarTransferencia'])->name('transferencias.destroy');
        Route::delete('/movimientos/{movimiento}', [PanelController::class, 'borrarMovimiento'])->name('movimientos.destroy');
        Route::post('/reembolsos', [PanelController::class, 'guardarReembolso'])->name('reembolsos.store');
        Route::post('/abonos', [PanelController::class, 'guardarAbono'])->name('abonos.store');
        Route::post('/ajustes/cuentas', [AjustesController::class, 'cuentas'])->name('ajustes.cuentas');
        Route::post('/ajustes/cuentas/nueva', [AjustesController::class, 'crearCuenta'])->name('ajustes.cuentas.store');
        Route::post('/ajustes/cuartos', [AjustesController::class, 'cuartos'])->name('ajustes.cuartos');
        Route::post('/ajustes/cuartos/nuevo', [AjustesController::class, 'crearCuarto'])->name('ajustes.cuartos.store');
        Route::delete('/ajustes/cuartos/{cuarto}', [AjustesController::class, 'borrarCuarto'])->name('ajustes.cuartos.destroy');
        Route::post('/ajustes/usuarios', [AjustesController::class, 'crearUsuario'])->name('ajustes.usuarios');
        Route::post('/ajustes/limites', [AjustesController::class, 'limites'])->name('ajustes.limites');
        Route::delete('/ajustes/usuarios/{user}', [AjustesController::class, 'borrarUsuario'])->name('ajustes.usuarios.destroy');
    });
});
