<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Servicio extends Model
{
    public const TIPO_HOTEL = 'Hotel';

    public const TIPO_TRANSPORTE_EN_BUS = 'Transporte en Bus';

    public const TIPO_TRANSPORTE_FERROVIARIO = 'Transporte Ferroviario';

    public const TIPO_CAMPING_DE_ETAPA = 'Camping de Etapa';

    protected $table = 'servicio';

    protected $primaryKey = 'id_servicio';

    public $timestamps = false;

    protected $fillable = [
        'tipo',
        'nombre',
    ];

    public function paquetes(): BelongsToMany
    {
        return $this->belongsToMany(Paquete::class, 'paquete_servicio', 'id_servicio', 'id_paquete');
    }
}
