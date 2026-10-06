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
        Schema::create('devolucion', function (Blueprint $table) {
            $table->id('id_devolucion');
            $table->foreignId('id_reserva')->unique()->constrained('reserva', 'id_reserva')->restrictOnDelete();
            $table->date('fecha');
            $table->decimal('monto', 10, 2);
            $table->enum('motivo', ['Reintegro', 'Reembolso']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('devolucion');
    }
};
