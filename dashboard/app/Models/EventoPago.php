<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventoPago extends Model
{
    protected $table = 'eventos_pago';

    protected $fillable = [
        'asignacion_cuenta_id', 'tipo', 'monto_pagado', 'saldo_antes',
        'saldo_despues', 'fecha_consulta_anterior', 'fecha_deteccion',
    ];

    protected $casts = [
        'fecha_consulta_anterior' => 'datetime',
        'fecha_deteccion' => 'datetime',
        'monto_pagado' => 'decimal:2',
        'saldo_antes' => 'decimal:2',
        'saldo_despues' => 'decimal:2',
    ];

    public function cuenta(): BelongsTo
    {
        return $this->belongsTo(AsignacionCuenta::class, 'asignacion_cuenta_id');
    }
}
