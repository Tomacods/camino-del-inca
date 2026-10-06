<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Excursionista extends Model
{
    public const ESTADO_PERMISO_PENDIENTE = 'Pendiente';

    public const ESTADO_PERMISO_OBTENIDO = 'Obtenido';

    public const ESTADO_PERMISO_NO_OBTENIDO = 'No Obtenido';

    protected $table = 'excursionista';

    protected $primaryKey = 'id_excursionista';

    public $timestamps = false;

    protected $fillable = [
        'id_reserva',
        'nombre',
        'apellido',
        'documento_pasaporte',
        'equipo_camping',
        'estado_permiso',
    ];

    protected function casts(): array
    {
        return [
            'equipo_camping' => 'boolean',
        ];
    }

    public function reserva(): BelongsTo
    {
        return $this->belongsTo(Reserva::class, 'id_reserva', 'id_reserva');
    }
}
