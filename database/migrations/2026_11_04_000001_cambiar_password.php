<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('cambiar_password')->default(false);
        });
        // Los usuarios que ya existen se crearon con la regla vieja (8 caracteres): que la cambien al entrar.
        DB::table('users')->update(['cambiar_password' => true]);
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('cambiar_password'));
    }
};
