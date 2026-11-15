<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Compras a meses con tarjeta de crédito: el gasto es por el total, pero el banco lo cobra en N mensualidades.
        Schema::table('gastos', fn (Blueprint $t) => $t->unsignedTinyInteger('meses')->nullable());
    }

    public function down(): void
    {
        Schema::table('gastos', fn (Blueprint $t) => $t->dropColumn('meses'));
    }
};
