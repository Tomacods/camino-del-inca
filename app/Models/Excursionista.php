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

    /* -------------------- CU-10 Listar integrantes y CU-13 -------------------- */

    // Los datos de un integrante: los mismos que en CU-04, más el equipo de camping y el permiso. Lo usan la página de
    // integrantes del guía (CU-10) y el correo de Notificar Cliente (CU-13), que toma sólo lo que dice el DS-13. En el
    // diagrama de clases es privado; acá es público porque lo llaman la página del panel y la reserva.
    public function getDatos(): array
    {
        return $this->consultarDatosExcursionistas() + [
            'equipo_camping' => $this->equipo_camping,
            'estado_permiso' => $this->estado_permiso,
        ];
    }
}
