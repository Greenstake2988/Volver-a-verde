<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estados_cuenta', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cuenta_id')->constrained('cuentas')->cascadeOnDelete();
            $table->string('mes', 7);   // "2026-10"
            $table->string('ruta');
            $table->string('nombre_original', 200);
            $table->string('mime', 100);
            $table->unsignedInteger('tamano');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['cuenta_id', 'mes']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estados_cuenta');
    }
};
