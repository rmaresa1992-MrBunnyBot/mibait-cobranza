<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Configuracion clave-valor editable desde el dashboard. Incluye el perfil
// de concurrencia por franja horaria que respeta el RPA: modesto de dia,
// alto en la franja nocturna 23:00-06:00.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('configuracion', function (Blueprint $table) {
            $table->id();
            $table->string('clave')->unique();
            $table->string('valor')->nullable();
            $table->string('descripcion')->nullable();
            $table->timestamps();
        });

        DB::table('configuracion')->insert([
            ['clave' => 'concurrencia_dia', 'valor' => '2', 'descripcion' => 'Workers Playwright simultaneos en horario diurno'],
            ['clave' => 'concurrencia_noche', 'valor' => '8', 'descripcion' => 'Workers Playwright simultaneos en franja nocturna'],
            ['clave' => 'franja_noche_inicio', 'valor' => '23:00', 'descripcion' => 'Hora de inicio de la franja nocturna (HH:MM)'],
            ['clave' => 'franja_noche_fin', 'valor' => '06:00', 'descripcion' => 'Hora de fin de la franja nocturna (HH:MM)'],
            ['clave' => 'rpa_headless', 'valor' => 'false', 'descripcion' => 'Ejecutar el navegador sin ventana (true/false)'],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('configuracion');
    }
};
