<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Catalogo persistente del numero de telefono. Vive por encima de las
// asignaciones para detectar cuentas repetidas entre carteras y permitir
// el cruce historico (que paso la vez anterior con esta cuenta).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('numeros', function (Blueprint $table) {
            $table->id();
            $table->char('numero', 10)->unique();
            $table->timestamp('primera_vez_visto')->nullable();
            $table->unsignedInteger('veces_asignado')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('numeros');
    }
};
