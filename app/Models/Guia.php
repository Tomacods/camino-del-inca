<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Guia extends Model
{
    protected $table = 'guia';

    protected $primaryKey = 'id_usuario';

    // La clave es la del usuario: no la genera esta tabla.
    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'id_usuario',
        'nombre',
        'apellido',
    ];

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'id_usuario', 'id_usuario');
    }
}
