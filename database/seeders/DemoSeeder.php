<?php

namespace Database\Seeders;

use App\Models\Ajuste;
use App\Models\Gasto;
use App\Models\Reserva;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/** Datos inventados para ver cómo se ve la app: php artisan db:seed --class=DemoSeeder */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (Reserva::exists()) {
            $this->command?->warn('Ya hay reservas; no se cargan datos de ejemplo.');

            return;
        }
        mt_srand(7);
        $hoy = CarbonImmutable::today();
        $inicio = $hoy->subMonthsNoOverflow(5)->startOfMonth();
        $tarifas = [1 => 650, 2 => 700, 3 => 800, 4 => 550, 5 => 900];
        $demanda = [1 => 0.75, 2 => 0.65, 3 => 0.8, 4 => 0.4, 5 => 0.6];
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
        for ($m = $inicio; $m <= $hoy; $m = $m->addMonthNoOverflow()) {
            $g = function (int $dia, float $monto, string $cat, string $desc, ?int $cuarto = null) use ($m, $hoy) {
                if ($m->day($dia) <= $hoy) {
                    Gasto::create(['fecha' => $m->day($dia), 'monto' => $monto, 'categoria' => $cat,
                        'descripcion' => $desc, 'cuarto_id' => $cuarto]);
                }
            };
            $g(5, 1400 + mt_rand(0, 600), 'Luz', 'CFE');
            $g(8, 450, 'Agua', 'Recibo de agua');
            $g(10, 389, 'Internet', 'Internet fibra');
            $g(12, 700, 'Gas', 'Tanque de gas');
            $g(15, 9000, 'Limpieza', 'Sueldo de limpieza');
            $g(20, 900 + mt_rand(0, 500), 'Insumos', 'Papel, jabón, café');
            $g(25, 1200, 'Lavandería', 'Sábanas y toallas');
            $g(28, 8000, 'Sueldos', 'Encargado / recepción');
            if (mt_rand(0, 1)) {
                $g(18, [800, 1500, 2500][mt_rand(0, 2)], 'Mantenimiento', 'Reparación', mt_rand(1, 5));
            }
        }
        Ajuste::guardar('saldo_inicial', '15000');
        Ajuste::guardar('fecha_saldo', $inicio->toDateString());
        $this->command?->info("Datos de ejemplo cargados: {$k} reservas desde {$inicio->toDateString()}.");
    }
}
