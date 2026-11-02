<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Las fechas se guardaban como "Y-m-d 00:00:00"; ahora van como "Y-m-d" (ver App\Casts\Fecha). */
    public function up(): void
    {
        $columnas = ['reservas' => ['check_in', 'fecha_pago'], 'gastos' => ['fecha'], 'movimientos' => ['fecha'],
            'cuentas' => ['fecha_saldo'], 'transferencias' => ['fecha']];
        foreach ($columnas as $tabla => $cols) {
            foreach ($cols as $col) {
                DB::table($tabla)->whereNotNull($col)->update([$col => DB::raw("substr({$col}, 1, 10)")]);
            }
        }
    }

    public function down(): void
    {
        //
    }
};
