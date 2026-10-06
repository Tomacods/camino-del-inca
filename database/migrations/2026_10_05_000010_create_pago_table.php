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
        Schema::create('pago', function (Blueprint $table) {
            $table->id('id_pago');
            $table->foreignId('id_reserva')->constrained('reserva', 'id_reserva')->restrictOnDelete();
            $table->date('fecha');
            $table->decimal('monto', 10, 2);
            $table->enum('tipo_pago', ['Seña', 'Saldo', 'Total']);
            $table->string('medio_pago', 40);

            $table->unique(['id_reserva', 'tipo_pago']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pago');
    }
};
