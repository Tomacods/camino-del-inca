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
        Schema::create('reserva', function (Blueprint $table) {
            $table->id('id_reserva');
            $table->foreignId('id_excursion')->constrained('excursion', 'id_excursion')->restrictOnDelete();
            $table->string('numero_reserva', 12)->unique();
            $table->string('correo_electronico', 100);
            $table->dateTime('fecha_reserva');
            $table->enum('estado', ['Pendiente', 'Confirmada', 'Sin Permiso', 'Cancelada', 'Finalizada'])->default('Pendiente');
            $table->enum('estado_saldo', ['Adeudado', 'Abonado']);
            $table->smallInteger('noches_extra_antes');
            $table->smallInteger('noches_extra_despues');
            $table->dateTime('fecha_limite_saldo')->nullable();
            $table->dateTime('fecha_limite_confirmacion');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reserva');
    }
};
