<?php

namespace Database\Seeders;

use App\Models\Cuarto;
use App\Models\Cuenta;
use App\Models\Gasto;
use App\Models\Reserva;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/** Reservas y gastos inventados para ver cómo se ve la app: php artisan db:seed --class=DemoSeeder */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (Reserva::exists()) {
            $this->command?->warn('Ya hay reservas; no se cargan datos de ejemplo.');

            return;
        }
        foreach (range(Cuarto::count() + 1, 5) as $i) {
            Cuarto::create(['nombre' => "Cuarto {$i}"]);
        }
        mt_srand(7);
        $hoy = CarbonImmutable::today();
        $inicio = $hoy->subMonthsNoOverflow(5)->startOfMonth();
        $ids = Cuarto::orderBy('id')->limit(5)->pluck('id')->all();
        $tarifas = array_combine($ids, [650, 700, 800, 550, 900]);
        $demanda = array_combine($ids, [0.75, 0.65, 0.8, 0.4, 0.6]);
        $huespedes = ['Ana', 'Luis', 'Marta', 'John', 'Sofía', 'Pedro'];
        $k = 0;
        foreach ($tarifas as $cuarto => $tarifa) {
            $d = $inicio;
            while ($d < $hoy->addDays(20)) {
                if (mt_rand() / mt_getrandmax() < $demanda[$cuarto]) {
                    $n = [1, 2, 2, 3, 4, 5][mt_rand(0, 5)];
                    $bruto = $tarifa * $n + 250;
                    $comision = round($bruto * 0.03, 2);
                    Reserva::create([
                        'cuarto_id' => $cuarto, 'codigo' => sprintf('HM%06d', ++$k),
                        'huesped' => $huespedes[mt_rand(0, 5)], 'check_in' => $d, 'noches' => $n,
                        'bruto' => $bruto, 'comision' => $comision,
                        'impuestos' => round(($bruto - $comision) * 0.04 + $bruto * 0.08, 2),
                        'fecha_pago' => $d->addDay(),
                    ]);
                    $d = $d->addDays($n);
                } else {
                    $d = $d->addDays(mt_rand(1, 3));
                }
            }
        }
        Cuenta::where('tipo', 'debito')->first()?->update(['saldo_inicial' => 15000, 'fecha_saldo' => $inicio]);
        (new GastosDemoSeeder)->cargar($inicio);
        $this->command?->info("Datos de ejemplo cargados: {$k} reservas desde {$inicio->toDateString()}.");
    }
}
