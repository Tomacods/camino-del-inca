<?php

namespace App\Models;

use App\Enums\EstadoPaquete;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Paquete extends Model
{
    protected $table = 'paquete';

    protected $primaryKey = 'id_paquete';

    public $timestamps = false;

    protected $fillable = [
        'id_recorrido',
        'nombre',
        'precio_base',
        'costo_noche_extra_cusco',
        'costo_equipo_camping',
        'cantidad_porteadores',
        'estado',
        'fecha_creacion',
    ];

    protected function casts(): array
    {
        return [
            'precio_base' => 'decimal:2',
            'costo_noche_extra_cusco' => 'decimal:2',
            'costo_equipo_camping' => 'decimal:2',
            'estado' => EstadoPaquete::class,
            'fecha_creacion' => 'date',
        ];
    }

    public function recorrido(): BelongsTo
    {
        return $this->belongsTo(Recorrido::class, 'id_recorrido', 'id_recorrido');
    }

    public function servicios(): BelongsToMany
    {
        return $this->belongsToMany(Servicio::class, 'paquete_servicio', 'id_paquete', 'id_servicio');
    }

    public function excursiones(): HasMany
    {
        return $this->hasMany(Excursion::class, 'id_paquete', 'id_paquete');
    }

    /* ------------------------------ CU-15 Pagar ------------------------------- */

    // Las noches extra se contratan para todo el grupo y se cobran por persona; el equipo de camping, por equipo. Los
    // precios llegan como texto ('750.00') por el cast decimal:2: se pasan a número antes de la cuenta.
    public function calcularMonto(int $cantidadIntegrantes, int $nochesExtra, int $equiposCamping): float
    {
        return (float) $this->precio_base * $cantidadIntegrantes
            + $nochesExtra * $cantidadIntegrantes * (float) $this->costo_noche_extra_cusco
            + $equiposCamping * (float) $this->costo_equipo_camping;
    }
}
