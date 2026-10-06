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
        Schema::create('etapa', function (Blueprint $table) {
            $table->id('id_etapa');
            $table->foreignId('id_recorrido')->constrained('recorrido', 'id_recorrido')->cascadeOnDelete();
            $table->string('nombre', 80);
            $table->smallInteger('orden');

            $table->unique(['id_recorrido', 'orden']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('etapa');
    }
};
