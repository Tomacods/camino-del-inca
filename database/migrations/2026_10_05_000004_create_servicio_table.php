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
        Schema::create('servicio', function (Blueprint $table) {
            $table->id('id_servicio');
            $table->enum('tipo', ['Hotel', 'Transporte en Bus', 'Transporte Ferroviario', 'Camping de Etapa']);
            $table->string('nombre', 80);

            $table->unique(['tipo', 'nombre']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('servicio');
    }
};
