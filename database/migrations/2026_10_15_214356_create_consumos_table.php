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
        Schema::create('consumos', function (Blueprint $table) {
            $table->id();
            $table->string('id_reserva'); // ID de la reserva en el sistema SOAP
            $table->string('concepto');   // Descripción del cargo extra
            $table->decimal('monto', 10, 2); // Valor del consumo
            $table->enum('estado', ['Pendiente', 'Pagado'])->default('Pendiente');
            $table->timestamp('fecha_registro')->useCurrent();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('consumos');
    }
};