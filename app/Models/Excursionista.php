<?php

namespace App\Models;

use App\Enums\EstadoPermiso;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Excursionista extends Model
{
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
            'estado_permiso' => EstadoPermiso::class,
        ];
    }

    public function reserva(): BelongsTo
    {
        return $this->belongsTo(Reserva::class, 'id_reserva', 'id_reserva');
    }

    /* ------------------------- CU-04 Validar permisos ------------------------- */

    // Paso 2: lo que el administrador ve de cada integrante para registrar su permiso.
    public function consultarDatosExcursionistas(): array
    {
        return [
            'nombre' => $this->nombre,
            'apellido' => $this->apellido,
            'documento_pasaporte' => $this->documento_pasaporte,
        ];
    }

    public function actualizarPermiso(EstadoPermiso $estadoPermiso): void
    {
        $this->estado_permiso = $estadoPermiso;
        $this->save();
    }
}
