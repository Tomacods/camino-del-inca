<?php

namespace App\Models;

use App\Enums\MotivoDevolucion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Devolucion extends Model
{
    protected $table = 'devolucion';

    protected $primaryKey = 'id_devolucion';

    public $timestamps = false;

    protected $fillable = [
        'id_reserva',
        'fecha',
        'monto',
        'motivo',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'monto' => 'decimal:2',
            'motivo' => MotivoDevolucion::class,
        ];
    }

    public function reserva(): BelongsTo
    {
        return $this->belongsTo(Reserva::class, 'id_reserva', 'id_reserva');
    }
}
