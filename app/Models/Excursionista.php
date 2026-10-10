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

    private function setEstadoPermiso(EstadoPermiso $estadoPermiso): void
    {
        $this->estado_permiso = $estadoPermiso;
        $this->save();
    }

    /* ----------------------------- CU-20 Modificar ---------------------------- */
    public function actualizarPermisosPendiente(): void
    {
        $this->setEstadoPermiso(EstadoPermiso::Pendiente);
    }
}
