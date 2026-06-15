<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Bitacora de cada ejecucion del RPA, para monitoreo desde el dashboard.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('corridas_rpa', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asignacion_id')->nullable()->constrained('asignaciones');
            $table->dateTime('inicio');
            $table->dateTime('fin')->nullable();
            $table->unsignedInteger('total_consultadas')->default(0);
            $table->unsignedInteger('exitosas')->default(0);
            $table->unsignedInteger('errores')->default(0);
            $table->string('estado', 20)->default('en_curso'); // en_curso | completada | fallida
            $table->unsignedSmallInteger('concurrencia')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('corridas_rpa');
    }
};
