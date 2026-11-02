<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cuentas del negocio. En una de crédito el saldo es negativo cuando se debe.
        Schema::create('cuentas', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('tipo');                       // debito | credito
            $table->decimal('saldo_inicial', 12, 2)->default(0);
            $table->date('fecha_saldo');
            $table->boolean('recibe_airbnb')->default(false);
            $table->timestamps();
        });

        // Transferencias entre cuentas (pago de la tarjeta) y reembolsos a socios (destino vacío).
        Schema::create('transferencias', function (Blueprint $table) {
            $table->id();
            $table->date('fecha');
            $table->decimal('monto', 12, 2);
            $table->foreignId('origen_id')->constrained('cuentas')->cascadeOnDelete();
            $table->foreignId('destino_id')->nullable()->constrained('cuentas')->cascadeOnDelete();
            $table->foreignId('reembolso_a')->nullable()->constrained('users')->nullOnDelete();
            $table->string('concepto')->default('');
            $table->timestamps();
        });

        // Con qué se pagó cada gasto. Vacío = dinero propio del socio (queda por reembolsar).
        Schema::table('gastos', function (Blueprint $table) {
            $table->foreignId('cuenta_id')->nullable()->after('cuarto_id')->constrained('cuentas')->nullOnDelete();
        });
        Schema::table('movimientos', function (Blueprint $table) {
            $table->foreignId('cuenta_id')->nullable()->after('id')->constrained('cuentas')->cascadeOnDelete();
        });

        // Pasar el saldo único que había a la cuenta de débito y crear la de crédito.
        $ahora = now();
        $saldo = DB::table('ajustes')->where('clave', 'saldo_inicial')->value('valor') ?? 0;
        $fecha = DB::table('ajustes')->where('clave', 'fecha_saldo')->value('valor') ?? $ahora->startOfMonth()->toDateString();
        $debito = DB::table('cuentas')->insertGetId(['nombre' => 'Débito negocio', 'tipo' => 'debito',
            'saldo_inicial' => $saldo, 'fecha_saldo' => $fecha, 'recibe_airbnb' => true,
            'created_at' => $ahora, 'updated_at' => $ahora]);
        DB::table('cuentas')->insert(['nombre' => 'Crédito negocio', 'tipo' => 'credito',
            'saldo_inicial' => 0, 'fecha_saldo' => $fecha, 'recibe_airbnb' => false,
            'created_at' => $ahora, 'updated_at' => $ahora]);
        DB::table('gastos')->where('de_la_cuenta', true)->update(['cuenta_id' => $debito]);
        DB::table('movimientos')->update(['cuenta_id' => $debito]);
        DB::table('ajustes')->whereIn('clave', ['saldo_inicial', 'fecha_saldo'])->delete();

        Schema::table('gastos', function (Blueprint $table) {
            $table->dropColumn('de_la_cuenta');
        });
    }

    public function down(): void
    {
        Schema::table('gastos', function (Blueprint $table) {
            $table->boolean('de_la_cuenta')->default(true);
        });
        DB::table('gastos')->update(['de_la_cuenta' => DB::raw('cuenta_id IS NOT NULL')]);
        Schema::table('gastos', fn (Blueprint $t) => $t->dropConstrainedForeignId('cuenta_id'));
        Schema::table('movimientos', fn (Blueprint $t) => $t->dropConstrainedForeignId('cuenta_id'));
        Schema::dropIfExists('transferencias');
        Schema::dropIfExists('cuentas');
    }
};
