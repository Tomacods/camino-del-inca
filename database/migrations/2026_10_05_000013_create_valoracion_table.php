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
        Schema::create('valoracion', function (Blueprint $table) {
            $table->id('id_valoracion');
            $table->foreignId('id_reserva')->unique()->constrained('reserva', 'id_reserva')->restrictOnDelete();
            $table->dateTime('fecha');
            $table->smallInteger('puntaje_experiencia_general');
            $table->string('comentario', 500)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('valoracion');
    }
};
