<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Configuracion extends Model
{
    protected $table = 'configuracion';

    protected $fillable = ['clave', 'valor', 'descripcion'];

    public $timestamps = true;

    public static function obtener(string $clave, ?string $default = null): ?string
    {
        return static::where('clave', $clave)->value('valor') ?? $default;
    }
}
