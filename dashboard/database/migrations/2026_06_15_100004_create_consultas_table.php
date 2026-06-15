<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// El snapshot: una foto por cada vez que el RPA consulta una cuenta. Es el
// corazon del sistema; de aqui se derivan los pagos inferidos y todas las
// metricas evolutivas. raw_texto guarda el texto integro de la tarjeta.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consultas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asignacion_cuenta_id')->constrained('asignacion_cuentas');
            $table->dateTime('fecha_consulta');
            $table->string('desenlace', 20); // al_corriente | con_saldo | prepago | no_bait | error
            $table->decimal('saldo_pendiente', 10, 2)->nullable();
            $table->decimal('monto_plan', 10, 2)->nullable();
            $table->decimal('total', 10, 2)->nullable();
            $table->string('periodo')->nullable();
            $table->date('fecha_limite_pago')->nullable();
            $table->text('raw_texto')->nullable();
            $table->timestamps();

            $table->index(['asignacion_cuenta_id', 'fecha_consulta']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consultas');
    }
};
