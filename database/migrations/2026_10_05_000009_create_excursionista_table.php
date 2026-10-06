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
        Schema::create('excursionista', function (Blueprint $table) {
            $table->id('id_excursionista');
            $table->foreignId('id_reserva')->constrained('reserva', 'id_reserva')->cascadeOnDelete();
            $table->string('nombre', 50);
            $table->string('apellido', 50);
            $table->string('documento_pasaporte', 20);
            $table->boolean('equipo_camping')->default(false);
            $table->enum('estado_permiso', ['Pendiente', 'Obtenido', 'No Obtenido'])->default('Pendiente');

            $table->unique(['id_reserva', 'documento_pasaporte']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('excursionista');
    }
};
