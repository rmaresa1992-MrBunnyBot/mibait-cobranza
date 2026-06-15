<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Asignacion extends Model
{
    protected $table = 'asignaciones';

    protected $fillable = [
        'nombre', 'fecha_carga', 'activa', 'estado', 'tipo_origen',
        'archivo_origen', 'total_cuentas',
    ];

    protected $casts = [
        'fecha_carga' => 'date',
        'activa' => 'boolean',
    ];

    public function cuentas(): HasMany
    {
        return $this->hasMany(AsignacionCuenta::class);
    }

    public function corridas(): HasMany
    {
        return $this->hasMany(CorridaRpa::class);
    }
}
