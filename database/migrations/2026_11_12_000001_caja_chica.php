<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Caja chica: una cuenta más, de tipo "efectivo". Se llena con retiros del banco (traspaso,
// no es gasto) y de ella salen los gastos pagados en efectivo.
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('cuentas')->where('tipo', 'efectivo')->exists()) {
            return;
        }
        $ahora = now();
        DB::table('cuentas')->insert(['nombre' => 'Caja chica', 'tipo' => 'efectivo', 'saldo_inicial' => 0,
            'fecha_saldo' => $ahora->toDateString(), 'recibe_airbnb' => false,
            'created_at' => $ahora, 'updated_at' => $ahora]);
    }

    public function down(): void
    {
        // Solo se quita si nadie la usó.
        $ids = DB::table('cuentas')->where('tipo', 'efectivo')->pluck('id');
        foreach ($ids as $id) {
            $usada = DB::table('gastos')->where('cuenta_id', $id)->exists()
                || DB::table('movimientos')->where('cuenta_id', $id)->exists()
                || DB::table('transferencias')->where('origen_id', $id)->orWhere('destino_id', $id)->exists();
            if (! $usada) {
                DB::table('cuentas')->where('id', $id)->delete();
            }
        }
    }
};
