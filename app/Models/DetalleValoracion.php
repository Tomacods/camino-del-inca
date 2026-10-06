<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DetalleValoracion extends Model
{
    public const CATEGORIA_HOTEL = 'Hotel';

    public const CATEGORIA_CAMPING_DE_ETAPA = 'Camping de Etapa';

    public const CATEGORIA_TRANSPORTE_EN_BUS = 'Transporte en Bus';

    public const CATEGORIA_TRANSPORTE_FERROVIARIO = 'Transporte Ferroviario';

    public const CATEGORIA_PORTEADORES = 'Porteadores';

    public const CATEGORIA_GUIA = 'Guía';

    public const CATEGORIA_EQUIPO_DE_CAMPING = 'Equipo de Camping';

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

    public function valoracion(): BelongsTo
    {
        return $this->belongsTo(Valoracion::class, 'id_valoracion', 'id_valoracion');
    }
}
