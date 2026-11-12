<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Rentas fuera de Airbnb: en qué cuenta entró el dinero (vacío = la que recibe Airbnb)
// y quién la capturó (los socios también pueden registrar rentas en efectivo).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservas', function (Blueprint $table) {
            $table->foreignId('cuenta_id')->nullable()->constrained('cuentas')->nullOnDelete();
            $table->foreignId('capturado_por')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('reservas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cuenta_id');
            $table->dropConstrainedForeignId('capturado_por');
        });
    }
};
