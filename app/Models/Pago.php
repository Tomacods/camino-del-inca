<?php

namespace App\Models;

use App\Enums\TipoPago;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use InvalidArgumentException;

class Pago extends Model
{
    protected $table = 'pago';

    protected $primaryKey = 'id_pago';

    public $timestamps = false;

    protected $fillable = [
        'id_reserva',
        'fecha',
        'monto',
        'tipo_pago',
        'medio_pago',
    ];

    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'monto' => 'decimal:2',
            'tipo_pago' => TipoPago::class,
        ];
    }

    public function reserva(): BelongsTo
    {
        return $this->belongsTo(Reserva::class, 'id_reserva', 'id_reserva');
    }

    public function comprobante(): HasOne
    {
        return $this->hasOne(Comprobante::class, 'id_pago', 'id_pago');
    }

    /* ------------------------------ CU-15 Pagar ------------------------------- */

    // Es estático porque el monto se calcula antes de que exista el pago. El saldo no se calcula acá: es de CU-16 y sale
    // de Reserva::calcularSaldoPendiente().
    public static function calcularMontoAPagar(float $montoTotal, TipoPago $tipoPago): float
    {
        return match ($tipoPago) {
            TipoPago::Total => $montoTotal,
            TipoPago::Sena => round($montoTotal * config('reserva.porcentaje_sena'), 2),
            TipoPago::Saldo => throw new InvalidArgumentException('El saldo se calcula con Reserva::calcularSaldoPendiente().'),
        };
    }
}
