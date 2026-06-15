<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Consulta extends Model
{
    protected $table = 'consultas';

    protected $fillable = [
        'asignacion_cuenta_id', 'fecha_consulta', 'desenlace', 'saldo_pendiente',
        'monto_plan', 'total', 'periodo', 'fecha_limite_pago', 'raw_texto',
    ];

    protected $casts = [
        'fecha_consulta' => 'datetime',
        'fecha_limite_pago' => 'date',
        'saldo_pendiente' => 'decimal:2',
        'monto_plan' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    public function cuenta(): BelongsTo
    {
        return $this->belongsTo(AsignacionCuenta::class, 'asignacion_cuenta_id');
    }
}
