<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Datos adicionales que trae el layout real de cartera (Asignacion.xlsx).
// El DN sigue en 'numero'; aqui se guardan los campos de origen tal como
// llegan, para no perder informacion de la cartera.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asignacion_cuentas', function (Blueprint $table) {
            $table->date('fecha_emision')->nullable();
            $table->string('mes_emision')->nullable();
            $table->decimal('monto_emision', 10, 2)->nullable();
            $table->string('num_edo_cuenta', 50)->nullable();
            $table->string('estatus_contrato', 60)->nullable();
            $table->date('fecha_creacion_contrato')->nullable();
            $table->string('nva_ban_of')->nullable();
            $table->string('estatus_uf', 60)->nullable();
            $table->string('numero_tel_contrato', 20)->nullable();
            $table->string('asignacion_origen', 60)->nullable();   // columna ASIGNACIÓN
            $table->string('pagos_mes')->nullable();               // PAGOS EN MAYO
            $table->string('num_telefono_ov', 20)->nullable();
            $table->date('flp')->nullable();
            $table->string('reetiqueta', 60)->nullable();
            $table->integer('ban_vencimiento')->nullable();
            $table->string('ban_bracket_vencimiento', 30)->nullable();
            $table->string('asignada', 80)->nullable();
            $table->string('canal', 60)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('asignacion_cuentas', function (Blueprint $table) {
            $table->dropColumn([
                'fecha_emision', 'mes_emision', 'monto_emision', 'num_edo_cuenta',
                'estatus_contrato', 'fecha_creacion_contrato', 'nva_ban_of',
                'estatus_uf', 'numero_tel_contrato', 'asignacion_origen', 'pagos_mes',
                'num_telefono_ov', 'flp', 'reetiqueta', 'ban_vencimiento',
                'ban_bracket_vencimiento', 'asignada', 'canal',
            ]);
        });
    }
};
