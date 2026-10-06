<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class Excursion extends Model
{
    protected $table = 'excursiones';
    protected $primaryKey = 'id_excursion';

    protected $guarded = [];

    protected $casts = [
        'fecha_salida' => 'date',
        'cupo' => 'integer',
        'fecha_hora_inicio_recorrido' => 'datetime',
    ];

    public function reservas(): HasMany
    {
        return $this->hasMany(Reserva::class, 'id_excursion', 'id_excursion');
    }

    public function getFechaSalida(): Carbon
    {
        return $this->fecha_salida;
    }
}