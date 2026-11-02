<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** user_id = de quién es el gasto (quién lo pagó); capturado_por = quién lo registró en la app. */
    public function up(): void
    {
        Schema::table('gastos', function (Blueprint $table) {
            $table->foreignId('capturado_por')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('gastos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('capturado_por');
        });
    }
};
