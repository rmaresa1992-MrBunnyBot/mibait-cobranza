<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// El renglon: un numero dentro de una asignacion. El estatus_cobranza es
// DERIVADO del saldo (no capturado). saldo_referencia lo fija la primera
// consulta del RPA. Al llegar a pago_total se marca cerrada y el RPA deja
// de consultarla.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asignacion_cuentas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asignacion_id')->constrained('asignaciones');
            $table->foreignId('numero_id')->constrained('numeros');
            $table->char('numero', 10); // denormalizado para queries rapidas
            $table->date('fecha_entrega')->nullable();
            $table->string('estatus_carga', 20)->default('con_adeudo');
            $table->decimal('saldo_referencia', 10, 2)->nullable();
            $table->decimal('saldo_actual', 10, 2)->nullable();
            $table->string('estatus_cobranza', 20)->default('con_adeudo'); // con_adeudo | pago_parcial | pago_total
            $table->string('tipo_linea', 20)->default('desconocido'); // desconocido | bait | prepago | no_bait
            $table->boolean('cerrada')->default(false);
            $table->date('fecha_pago_inferida')->nullable();
            $table->dateTime('ultima_consulta_at')->nullable();
            $table->timestamps();

            $table->index(['asignacion_id', 'cerrada']);
            $table->index('numero');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asignacion_cuentas');
    }
};
