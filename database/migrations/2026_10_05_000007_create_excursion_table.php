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
        Schema::create('excursion', function (Blueprint $table) {
            $table->id('id_excursion');
            $table->foreignId('id_paquete')->constrained('paquete', 'id_paquete')->cascadeOnDelete();
            $table->foreignId('id_guia')->constrained('guia', 'id_usuario')->restrictOnDelete();
            $table->foreignId('id_etapa_actual')->nullable()->constrained('etapa', 'id_etapa')->restrictOnDelete();
            $table->date('fecha_salida');
            $table->smallInteger('cupo');
            $table->smallInteger('plazas_retenidas')->default(0);
            $table->dateTime('fecha_hora_inicio_recorrido')->nullable();

            $table->unique(['id_paquete', 'fecha_salida']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('excursion');
    }
};
