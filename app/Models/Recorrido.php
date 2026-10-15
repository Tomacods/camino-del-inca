<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Recorrido extends Model
{
    protected $table = 'recorrido';

    protected $primaryKey = 'id_recorrido';

    public $timestamps = false;

    protected $fillable = [
        'nombre',
        'duracion_dias',
        'cantidad_campings',
    ];

    public function etapas(): HasMany
    {
        return $this->hasMany(Etapa::class, 'id_recorrido', 'id_recorrido');
    }

    public function paquetes(): HasMany
    {
        return $this->hasMany(Paquete::class, 'id_recorrido', 'id_recorrido');
    }

    /* ------------------------- CU-13 Notificar cliente ------------------------ */

    public function getDatosRecorrido(): array
    {
        return [
            'nombre_recorrido' => $this->nombre,
            'duracion_dias' => $this->duracion_dias,
        ];
    }

    /* ------------------------ CU-06 Consultar paquete ------------------------ */

    public function contarEtapas(): int
    {
        return $this->etapas()->count();
    }
}
