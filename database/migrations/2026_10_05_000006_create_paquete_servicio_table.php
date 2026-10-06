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
        Schema::create('paquete_servicio', function (Blueprint $table) {
            $table->foreignId('id_paquete')->constrained('paquete', 'id_paquete')->cascadeOnDelete();
            $table->foreignId('id_servicio')->constrained('servicio', 'id_servicio')->restrictOnDelete();

            $table->primary(['id_paquete', 'id_servicio']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('paquete_servicio');
    }
};
