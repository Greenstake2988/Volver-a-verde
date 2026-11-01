<?php

namespace App\Http\Controllers;

use App\Models\Cuarto;
use App\Models\Reserva;
use Illuminate\Http\Request;

class ReservaController extends Controller
{
    public function index()
    {
        return view('reservas', [
            'reservas' => Reserva::with('cuarto')->orderByDesc('check_in')->paginate(100),
            'cuartos' => Cuarto::orderBy('id')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $datos = $request->validate([
            'cuarto_id' => 'required|exists:cuartos,id',
            'huesped' => 'nullable|string|max:100',
            'check_in' => 'required|date',
            'noches' => 'required|integer|min:1|max:365',
            'bruto' => 'required|numeric|min:0',
            'comision' => 'nullable|numeric|min:0',
            'impuestos' => 'nullable|numeric|min:0',
            'fecha_pago' => 'nullable|date',
            'codigo' => 'nullable|string|max:40|unique:reservas,codigo',
        ]);
        Reserva::create(array_merge(['huesped' => '', 'comision' => 0, 'impuestos' => 0],
            array_filter($datos, fn ($v) => $v !== null)));

        return back()->with('ok', 'Reserva guardada.');
    }

    public function destroy(Reserva $reserva)
    {
        $reserva->delete();

        return back()->with('ok', 'Reserva borrada.');
    }
}
