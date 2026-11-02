<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Un cuarto que ya no se renta se desactiva: deja de contar para la ocupación y de salir
// en los formularios, pero sus reservas y gastos viejos se quedan con él.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cuartos', function (Blueprint $table) {
            $table->boolean('activo')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('cuartos', function (Blueprint $table) {
            $table->dropColumn('activo');
        });
    }
};
