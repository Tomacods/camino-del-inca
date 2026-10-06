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
        Schema::create('comprobante', function (Blueprint $table) {
            $table->id('id_comprobante');
            $table->foreignId('id_pago')->unique()->constrained('pago', 'id_pago')->cascadeOnDelete();
            $table->string('numero_comprobante', 20)->unique();
            $table->dateTime('fecha_emision');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('comprobante');
    }
};
