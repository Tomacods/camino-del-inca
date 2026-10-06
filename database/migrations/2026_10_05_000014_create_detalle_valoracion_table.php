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
        Schema::create('detalle_valoracion', function (Blueprint $table) {
            $table->foreignId('id_valoracion')->constrained('valoracion', 'id_valoracion')->cascadeOnDelete();
            $table->enum('categoria', [
                'Hotel',
                'Camping de Etapa',
                'Transporte en Bus',
                'Transporte Ferroviario',
                'Porteadores',
                'Guía',
                'Equipo de Camping',
            ]);
            $table->smallInteger('puntaje');

            $table->primary(['id_valoracion', 'categoria']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('detalle_valoracion');
    }
};
