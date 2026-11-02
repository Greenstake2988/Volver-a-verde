<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Todas las tarjetas son del negocio, pero cada una la usa un socio.
// Si un socio carga a una tarjeta del negocio algo que no es del negocio, le debe ese dinero
// al negocio; cuando lo paga se registra como un movimiento de entrada con su nombre.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cuentas', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();   // quién la usa
        });
        Schema::table('movimientos', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();   // pago de un socio
        });
        // Tope mensual de gastos personales con cuentas del negocio (vacío = sin tope).
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('limite_mensual', 12, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cuentas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
        Schema::table('movimientos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('limite_mensual');
        });
    }
};
