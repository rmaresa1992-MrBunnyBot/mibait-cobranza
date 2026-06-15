<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// El cambio inferido al comparar dos snapshots consecutivos. La fecha de
// pago real es desconocida: se modela como el rango
// [fecha_consulta_anterior, fecha_deteccion], nunca como instante exacto.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eventos_pago', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asignacion_cuenta_id')->constrained('asignacion_cuentas');
            $table->string('tipo', 20); // pago_parcial | pago_total
            $table->decimal('monto_pagado', 10, 2);
            $table->decimal('saldo_antes', 10, 2)->nullable();
            $table->decimal('saldo_despues', 10, 2)->nullable();
            $table->dateTime('fecha_consulta_anterior')->nullable();
            $table->dateTime('fecha_deteccion');
            $table->timestamps();

            $table->index(['asignacion_cuenta_id', 'fecha_deteccion']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eventos_pago');
    }
};
