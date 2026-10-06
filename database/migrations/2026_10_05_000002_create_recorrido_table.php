<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('recorrido', function (Blueprint $table) {
            $table->id('id_recorrido');
            $table->string('nombre', 60)->unique();
            $table->smallInteger('duracion_dias');
            $table->smallInteger('cantidad_campings');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recorrido');
    }
};
