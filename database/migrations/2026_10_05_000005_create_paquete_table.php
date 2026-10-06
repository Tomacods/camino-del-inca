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
        Schema::create('paquete', function (Blueprint $table) {
            $table->id('id_paquete');
            $table->foreignId('id_recorrido')->constrained('recorrido', 'id_recorrido')->restrictOnDelete();
            $table->string('nombre', 80)->unique();
            $table->decimal('precio_base', 10, 2);
            $table->decimal('costo_noche_extra_cusco', 10, 2);
            $table->decimal('costo_equipo_camping', 10, 2);
            $table->smallInteger('cantidad_porteadores');
            $table->enum('estado', ['Activo', 'Inactivo'])->default('Activo');
            $table->date('fecha_creacion');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('paquete');
    }
};
