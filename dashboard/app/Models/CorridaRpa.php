<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CorridaRpa extends Model
{
    protected $table = 'corridas_rpa';

    protected $fillable = [
        'asignacion_id', 'inicio', 'fin', 'total_consultadas', 'exitosas',
        'errores', 'estado', 'concurrencia',
    ];

    protected $casts = [
        'inicio' => 'datetime',
        'fin' => 'datetime',
    ];

    public function asignacion(): BelongsTo
    {
        return $this->belongsTo(Asignacion::class);
    }
}
