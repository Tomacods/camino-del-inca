<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class Excursion extends Model
{
    protected $table = 'excursion';

    protected $primaryKey = 'id_excursion';

    public $timestamps = false;

    protected $fillable = [
        'id_paquete',
        'id_guia',
        'id_etapa_actual',
        'fecha_salida',
        'cupo',
        'plazas_retenidas',
        'fecha_hora_inicio_recorrido',
    ];

    protected function casts(): array
    {
        return [
            'fecha_salida' => 'date',
            'fecha_hora_inicio_recorrido' => 'datetime',
        ];
    }

    public function paquete(): BelongsTo
    {
        return $this->belongsTo(Paquete::class, 'id_paquete', 'id_paquete');
    }

    public function guia(): BelongsTo
    {
        return $this->belongsTo(Guia::class, 'id_guia', 'id_usuario');
    }

    public function etapaActual(): BelongsTo
    {
        return $this->belongsTo(Etapa::class, 'id_etapa_actual', 'id_etapa');
    }

    public function reservas(): HasMany
    {
        return $this->hasMany(Reserva::class, 'id_excursion', 'id_excursion');
    }

    public function getFechaSalida(): Carbon
    {
        return $this->fecha_salida;
    }
}
