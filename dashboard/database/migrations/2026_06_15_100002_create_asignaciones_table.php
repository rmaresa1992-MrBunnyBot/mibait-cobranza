<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Una cartera cargada. Solo una con activa=1 a la vez (la que recorre el
// RPA). Las demas quedan archivadas pero completas para el historico.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asignaciones', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->date('fecha_carga');
            $table->boolean('activa')->default(false);
            $table->string('estado', 20)->default('activa'); // activa | archivada
            $table->string('tipo_origen', 20)->default('nueva'); // nueva | complemento
            $table->string('archivo_origen')->nullable();
            $table->unsignedInteger('total_cuentas')->default(0);
            $table->timestamps();

            $table->index('activa');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asignaciones');
    }
};
