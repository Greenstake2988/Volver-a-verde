<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cuartos', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('anuncio_airbnb')->default('');
            $table->timestamps();
        });

        Schema::create('reservas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cuarto_id')->nullable()->constrained('cuartos')->nullOnDelete();
            $table->string('codigo')->nullable()->unique();
            $table->string('huesped')->default('');
            $table->date('check_in');
            $table->unsignedInteger('noches');
            $table->decimal('bruto', 12, 2)->default(0);
            $table->decimal('comision', 12, 2)->default(0);
            $table->decimal('impuestos', 12, 2)->default(0);
            $table->date('fecha_pago')->nullable();
            $table->text('notas')->nullable();
            $table->timestamps();
            $table->index('check_in');
        });

        Schema::create('gastos', function (Blueprint $table) {
            $table->id();
            $table->date('fecha');
            $table->decimal('monto', 12, 2);
            $table->string('categoria');
            $table->foreignId('cuarto_id')->nullable()->constrained('cuartos')->nullOnDelete();
            $table->string('descripcion')->default('');
            $table->boolean('personal')->default(false);      // no es del negocio: no cuenta en la rentabilidad
            $table->boolean('factura')->default(false);       // tiene factura (CFDI)
            $table->boolean('de_la_cuenta')->default(true);   // se pagó desde la cuenta del negocio
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('fecha');
        });

        Schema::create('comprobantes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gasto_id')->constrained('gastos')->cascadeOnDelete();
            $table->string('ruta');
            $table->string('nombre_original');
            $table->string('mime');
            $table->unsignedBigInteger('tamano');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('movimientos', function (Blueprint $table) {
            $table->id();
            $table->date('fecha');
            $table->decimal('monto', 12, 2);   // positivo = entró, negativo = salió
            $table->string('concepto')->default('');
            $table->timestamps();
        });

        Schema::create('ajustes', function (Blueprint $table) {
            $table->string('clave')->primary();
            $table->string('valor')->nullable();
        });

        // Los cuartos se crean en Ajustes o solos al importar el CSV de Airbnb (uno por anuncio).
        $ahora = now();
        DB::table('ajustes')->insert([
            ['clave' => 'saldo_inicial', 'valor' => '0'],
            ['clave' => 'fecha_saldo', 'valor' => $ahora->startOfYear()->toDateString()],
        ]);
    }

    public function down(): void
    {
        foreach (['ajustes', 'movimientos', 'comprobantes', 'gastos', 'reservas', 'cuartos'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
