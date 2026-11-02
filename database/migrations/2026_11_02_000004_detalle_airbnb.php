<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Desglose de impuestos como viene en el CSV de Airbnb México.
        Schema::table('reservas', function (Blueprint $table) {
            $table->date('fecha_salida')->nullable()->after('noches');
            $table->decimal('iva_trasladado', 12, 2)->default(0)->after('comision');  // IVA que pagó el huésped
            $table->decimal('retencion_iva', 12, 2)->default(0)->after('impuestos');
            $table->decimal('retencion_isr', 12, 2)->default(0)->after('retencion_iva');
            $table->decimal('ish', 12, 2)->default(0)->after('retencion_isr');         // hospedaje, lo paga Airbnb
            $table->string('origen')->default('manual')->after('ish');                // manual | airbnb
        });

        // Depósitos reales de Airbnb al banco (renglones "Payout" del CSV).
        Schema::create('depositos', function (Blueprint $table) {
            $table->id();
            $table->date('fecha');                 // fecha estimada de llegada al banco
            $table->date('fecha_envio')->nullable();
            $table->decimal('monto', 12, 2);
            $table->string('referencia')->nullable()->unique();
            $table->string('detalles')->default('');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('depositos');
        Schema::table('reservas', function (Blueprint $table) {
            $table->dropColumn(['fecha_salida', 'iva_trasladado', 'retencion_iva', 'retencion_isr', 'ish', 'origen']);
        });
    }
};
