<?php

namespace Database\Seeders;

use App\Models\Cuenta;
use App\Models\Gasto;
use App\Models\Transferencia;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Solo gastos de ejemplo (sin reservas), desde enero del año en curso.
 * Sirve para probar con las reservas reales importadas de Airbnb:
 *   php artisan db:seed --class=GastosDemoSeeder
 * Para borrarlos después: php artisan tinker --execute="App\Models\Gasto::where('descripcion', 'like', '[demo]%')->delete()"
 */
class GastosDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (Gasto::where('descripcion', 'like', '[demo]%')->exists()) {
            $this->command?->warn('Ya hay gastos de ejemplo; no se cargan otra vez.');

            return;
        }
        $n = $this->cargar(CarbonImmutable::today()->startOfYear());
        $this->command?->info("Gastos de ejemplo cargados: {$n}.");
    }

    /** Gastos mensuales típicos desde $inicio hasta hoy; algunos con la tarjeta de crédito, que se paga cada mes. */
    public function cargar(CarbonImmutable $inicio): int
    {
        mt_srand(11);
        $hoy = CarbonImmutable::today();
        $debito = Cuenta::where('tipo', 'debito')->orderBy('id')->first();
        $credito = Cuenta::where('tipo', 'credito')->orderBy('id')->first();
        // Que las cuentas cuenten desde el inicio de los gastos de ejemplo.
        Cuenta::where('fecha_saldo', '>', $inicio->toDateString())->update(['fecha_saldo' => $inicio->toDateString()]);
        $total = 0;
        for ($m = $inicio->startOfMonth(); $m <= $hoy; $m = $m->addMonthNoOverflow()) {
            $g = function (int $dia, float $monto, string $cat, string $desc, ?Cuenta $cuenta, ?int $cuarto = null) use ($m, $hoy, &$total) {
                if ($m->day($dia) <= $hoy) {
                    Gasto::create(['fecha' => $m->day($dia), 'monto' => $monto, 'categoria' => $cat,
                        'descripcion' => "[demo] {$desc}", 'cuarto_id' => $cuarto, 'cuenta_id' => $cuenta?->id]);
                    $total++;
                }
            };
            $g(5, 1400 + mt_rand(0, 600), 'Luz', 'CFE', $debito);
            $g(8, 450, 'Agua', 'Recibo de agua', $debito);
            $g(10, 389, 'Internet', 'Internet fibra', $credito);
            $g(12, 700, 'Gas', 'Tanque de gas', $debito);
            $g(15, 9000, 'Limpieza', 'Sueldo de limpieza', $debito);
            $g(20, 900 + mt_rand(0, 500), 'Insumos', 'Papel, jabón, café', $credito);
            $g(25, 1200, 'Lavandería', 'Sábanas y toallas', $debito);
            $g(28, 8000, 'Sueldos', 'Encargado / recepción', $debito);
            if (mt_rand(0, 1)) {
                $g(18, [800, 1500, 2500][mt_rand(0, 2)], 'Mantenimiento', 'Reparación', $credito, mt_rand(1, 5));
            }

            // Pago de la tarjeta el día 3 por lo que se gastó con ella el mes anterior.
            $anterior = $m->subMonthNoOverflow();
            if ($debito && $credito && $m->day(3) <= $hoy && $m > $inicio) {
                $deuda = Gasto::where('cuenta_id', $credito->id)
                    ->whereBetween('fecha', [$anterior->startOfMonth()->toDateString(), $anterior->endOfMonth()->toDateString()])
                    ->sum('monto');
                if ($deuda > 0) {
                    Transferencia::create(['fecha' => $m->day(3), 'monto' => $deuda, 'origen_id' => $debito->id,
                        'destino_id' => $credito->id, 'concepto' => '[demo] Pago mensual']);
                }
            }
        }

        return $total;
    }
}
