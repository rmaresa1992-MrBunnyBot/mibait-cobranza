<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AsignacionCuenta extends Model
{
    protected $table = 'asignacion_cuentas';

    protected $fillable = [
        'asignacion_id', 'numero_id', 'numero', 'fecha_entrega', 'estatus_carga',
        'saldo_referencia', 'saldo_actual', 'estatus_cobranza', 'tipo_linea',
        'cerrada', 'fecha_pago_inferida', 'ultima_consulta_at',
    ];

    protected $casts = [
        'fecha_entrega' => 'date',
        'fecha_pago_inferida' => 'date',
        'ultima_consulta_at' => 'datetime',
        'cerrada' => 'boolean',
        'saldo_referencia' => 'decimal:2',
        'saldo_actual' => 'decimal:2',
    ];

    public function asignacion(): BelongsTo
    {
        return $this->belongsTo(Asignacion::class);
    }

    public function numeroRef(): BelongsTo
    {
        return $this->belongsTo(Numero::class, 'numero_id');
    }

    public function consultas(): HasMany
    {
        return $this->hasMany(Consulta::class);
    }

    public function eventosPago(): HasMany
    {
        return $this->hasMany(EventoPago::class);
    }
}
