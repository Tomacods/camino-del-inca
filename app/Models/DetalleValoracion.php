<?php

namespace App\Models;

use App\Enums\CategoriaValoracion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DetalleValoracion extends Model
{
    protected $table = 'detalle_valoracion';

    // La clave es compuesta (id_valoracion, categoria) y Eloquent no la maneja: los detalles se crean y se leen
    // siempre a través de la valoración ($valoracion->detalles()), nunca por su clave.
    protected $primaryKey = null;

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'id_valoracion',
        'categoria',
        'puntaje',
    ];

    protected function casts(): array
    {
        return [
            'categoria' => CategoriaValoracion::class,
        ];
    }

    public function valoracion(): BelongsTo
    {
        return $this->belongsTo(Valoracion::class, 'id_valoracion', 'id_valoracion');
    }
}
