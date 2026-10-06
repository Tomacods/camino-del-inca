<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Reserva extends Model
{
    public const ESTADO_PENDIENTE = 'Pendiente';

    public const ESTADO_CONFIRMADA = 'Confirmada';

    public const ESTADO_SIN_PERMISO = 'Sin Permiso';

    public const ESTADO_CANCELADA = 'Cancelada';

    public const ESTADO_FINALIZADA = 'Finalizada';

    public const ESTADO_SALDO_ADEUDADO = 'Adeudado';

    public const ESTADO_SALDO_ABONADO = 'Abonado';

    protected $table = 'reserva';

    protected $primaryKey = 'id_reserva';

    public $timestamps = false;

    protected $fillable = [
        'id_excursion',
        'numero_reserva',
        'correo_electronico',
        'fecha_reserva',
        'estado',
        'estado_saldo',
        'noches_extra_antes',
        'noches_extra_despues',
        'fecha_limite_saldo',
        'fecha_limite_confirmacion',
    ];

    protected function casts(): array
    {
        return [
            'fecha_reserva' => 'datetime',
            'fecha_limite_saldo' => 'datetime',
            'fecha_limite_confirmacion' => 'datetime',
        ];
    }

    public function excursion(): BelongsTo
    {
        return $this->belongsTo(Excursion::class, 'id_excursion', 'id_excursion');
    }

    public function excursionistas(): HasMany
    {
        return $this->hasMany(Excursionista::class, 'id_reserva', 'id_reserva');
    }

    public function pagos(): HasMany
    {
        return $this->hasMany(Pago::class, 'id_reserva', 'id_reserva');
    }

    public function devolucion(): HasOne
    {
        return $this->hasOne(Devolucion::class, 'id_reserva', 'id_reserva');
    }

    public function valoracion(): HasOne
    {
        return $this->hasOne(Valoracion::class, 'id_reserva', 'id_reserva');
    }
}
