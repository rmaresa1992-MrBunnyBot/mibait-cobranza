<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Numero extends Model
{
    protected $table = 'numeros';

    protected $fillable = ['numero', 'primera_vez_visto', 'veces_asignado'];

    protected $casts = [
        'primera_vez_visto' => 'datetime',
    ];

    public function cuentas(): HasMany
    {
        return $this->hasMany(AsignacionCuenta::class, 'numero_id');
    }
}
