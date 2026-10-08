<?php

namespace App\Models;

use App\Enums\EstadoPaquete;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
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

    // CU-05: el portal lista sólo los paquetes activos, del más nuevo al más antiguo. La fecha de creación no tiene
    // hora, así que entre dos paquetes creados el mismo día va primero el de clave más alta, que se creó después.
    public static function listarPaquetes(): Collection
    {
        return self::buscarActivos()
            ->with('recorrido')
            ->orderByDesc('fecha_creacion')
            ->orderByDesc('id_paquete')
            ->get();
    }

    public static function buscarActivos(): Builder
    {
        return self::where('estado', EstadoPaquete::Activo);
    }

    // CU-05: lo que muestra cada paquete en el listado del portal.
    public function getDatosResumen(): array
    {
        return [
            'id_paquete' => $this->id_paquete,
            'nombre' => $this->nombre,
            'precio_base' => $this->precio_base,
            ...$this->recorrido->getDatosRecorrido(),
            'excursiones' => Excursion::getExcursionesDisponibles($this->id_paquete),
        ];
    }
}
