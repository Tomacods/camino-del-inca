<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Valoracion extends Model
{
    protected $table = 'valoracion';

    protected $primaryKey = 'id_valoracion';

    public $timestamps = false;

    protected $fillable = [
        'id_reserva',
        'fecha',
        'puntaje_experiencia_general',
        'comentario',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'datetime',
        ];
    }

    public function reserva(): BelongsTo
    {
        return $this->belongsTo(Reserva::class, 'id_reserva', 'id_reserva');
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(DetalleValoracion::class, 'id_valoracion', 'id_valoracion');
    }
}
