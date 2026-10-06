<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Devolucion extends Model
{
    public const MOTIVO_REEMBOLSO = 'Reembolso';   // CU-21
    public const MOTIVO_REINTEGRO = 'Reintegro';   // CU-17 y CU-26

    protected $table = 'devoluciones';
    protected $primaryKey = 'id_devolucion';

    protected $guarded = [];

    protected $casts = [
        'fecha' => 'date',
        'monto' => 'float',
    ];

    public function reserva(): BelongsTo
    {
        return $this->belongsTo(Reserva::class, 'id_reserva', 'id_reserva');
    }
}