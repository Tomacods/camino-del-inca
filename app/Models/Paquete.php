<?php

namespace App\Models;

use App\Enums\EstadoPaquete;
use App\Enums\EstadoReserva;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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

    /* --------------------------- CU-01 Crear paquete --------------------------- */

    // El paquete queda activo. Filament guarda sus servicios y excursiones en la misma transacción.
    public static function crearPaquete(array $datos): self
    {
        return self::create([
            ...$datos,
            'estado' => EstadoPaquete::Activo,
            'fecha_creacion' => today(),
        ]);
    }

    // Dentro de la transacción de CU-01, antes de guardar el paquete o sus excursiones. Dos altas con el mismo guía
    // se turnan: la segunda espera y comprueba las salidas que confirmó la primera. Los bloqueos se toman en orden
    // y antes de insertar las claves foráneas, para evitar convertir bloqueos compartidos en exclusivos. Al modificar o
    // reactivar un paquete (CU-02) se pasa su identificación, para no compararlo con sus propias excursiones.
    public static function confirmarGuiasDisponibles(array $salidas, int $idRecorrido, ?int $idPaquete = null): void
    {
        Guia::whereIn('id_usuario', array_unique(array_column($salidas, 'id_guia')))
            ->orderBy('id_usuario')
            ->lockForUpdate()
            ->get();

        $recorrido = Recorrido::findOrFail($idRecorrido);
        $fecha = Excursion::buscarConflictoDeGuia($salidas, $recorrido->duracion_dias, $idPaquete);

        if ($fecha !== null) {
            throw new DomainException('El guía de la salida del '.$fecha->format('d/m/Y')
                .' ya conduce otra excursión en esas fechas.');
        }
    }

    /* ------------------------- CU-02 y CU-03 Modificar y eliminar ------------------------- */

    // R-02 y R-03: un paquete con reservas «Pendiente», «Confirmada» o «Sin Permiso» en alguna de sus excursiones no se
    // modifica ni se da de baja. Así el cupo de una excursión nunca cambia con plazas ya ocupadas.
    public function tieneReservasActivas(): bool
    {
        return Reserva::whereIn('estado', [EstadoReserva::Pendiente, EstadoReserva::Confirmada, EstadoReserva::SinPermiso])
            ->whereHas('excursion', fn ($consulta) => $consulta->where('id_paquete', $this->id_paquete))
            ->exists();
    }

    public function sePuedeModificar(): bool
    {
        return ! $this->tieneReservasActivas();
    }

    // R-02 y R-03: lo usan modificarPaquete() y darDeBaja(), y también la página de edición antes de guardar nada.
    // Bloquea primero las excursiones del paquete, como la retención de cupo (CU-14): dentro de una transacción, una
    // reserva nueva espera a que termine el cambio.
    public function exigirSinReservasActivas(): void
    {
        $this->excursiones()->lockForUpdate()->get();

        if ($this->tieneReservasActivas()) {
            throw new DomainException('Tiene reservas en estado «Pendiente», «Confirmada» o «Sin Permiso».');
        }
    }

    // R-02: el nombre, los porteadores y los precios. El recorrido no cambia. Los servicios y las excursiones los guarda
    // el formulario del panel, con sus relaciones.
    public function modificarPaquete(array $datos): void
    {
        $this->exigirSinReservasActivas();

        $this->update(Arr::only($datos, [
            'nombre',
            'cantidad_porteadores',
            'precio_base',
            'costo_noche_extra_cusco',
            'costo_equipo_camping',
        ]));
    }

    // R-03: baja lógica. El paquete deja de mostrarse en el portal y no recibe reservas, pero no se borra.
    public function darDeBaja(): void
    {
        DB::transaction(function () {
            $this->exigirSinReservasActivas();

            $this->update(['estado' => EstadoPaquete::Inactivo]);
        });
    }

    // R-02: vuelve a «Activo» si, mientras estuvo dado de baja, ningún guía de sus salidas futuras quedó asignado a otra
    // excursión superpuesta. Con los guías bloqueados, como al crear y al modificar.
    public function reactivar(): void
    {
        DB::transaction(function () {
            $salidas = $this->excursiones()
                ->whereDate('fecha_salida', '>=', today())
                ->get()
                ->map(fn (Excursion $excursion) => [
                    'fecha_salida' => $excursion->fecha_salida->format('Y-m-d'),
                    'id_guia' => $excursion->id_guia,
                ])
                ->all();

            self::confirmarGuiasDisponibles($salidas, $this->id_recorrido, $this->id_paquete);

            $this->update(['estado' => EstadoPaquete::Activo]);
        });
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

    /* -------------------------- CU-12 Reportar etapa -------------------------- */

    public function getEtapas(): Collection
    {
        return $this->recorrido->getEtapas();
    }

    /* ------------------------- CU-13 Notificar cliente ------------------------ */

    // Lo que el comprobante de la primera compra dice del paquete: el recorrido, con su duración, y los servicios.
    public function getDatosComprobante(): array
    {
        return [
            'recorrido' => $this->recorrido->getDatosRecorrido(),
            'servicios' => $this->servicios
                ->map(fn (Servicio $servicio) => $servicio->getServiciosIncluidos())
                ->all(),
        ];
    }

    /* ------------------------- CU-05 Listar paquetes ------------------------- */

    // El portal lista sólo los paquetes activos, del más nuevo al más antiguo. La fecha de creación no tiene hora, así
    // que entre dos paquetes creados el mismo día va primero el de clave más alta, que se creó después.
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

    // Pasos 3 y 4: entre los paquetes del listado, los que coinciden con el criterio. El componente ya validó el formato
    // y pasa una fecha de salida o un texto para buscar en el nombre.
    public static function buscarPaquetes(Carbon|string $criterio): Collection
    {
        return self::listarPaquetes()
            ->filter(fn (Paquete $paquete) => $paquete->coincideConCriterio($criterio))
            ->values();
    }

    // El nombre se compara sin mayúsculas ni tildes: «clasico» encuentra «Camino Inca Clásico». Una fecha coincide si el
    // paquete tiene una salida disponible ese día, es decir, una de las que muestra el listado.
    public function coincideConCriterio(Carbon|string $criterio): bool
    {
        if ($criterio instanceof Carbon) {
            return Excursion::tieneFechaSalida($this->id_paquete, $criterio);
        }

        return Str::contains(Str::lower(Str::ascii($this->nombre)), Str::lower(Str::ascii($criterio)));
    }

    // Lo que muestra cada paquete en el listado del portal.
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

    /* ------------------------ CU-06 Consultar paquete ------------------------ */

    public static function buscarPorId(int $idPaquete): ?self
    {
        return self::find($idPaquete);
    }

    public function estaActivo(): bool
    {
        return $this->estado === EstadoPaquete::Activo;
    }

    // Todo lo que muestra el detalle del paquete (paso 3), ya calculado para que la vista no haga consultas.
    public function getDetalles(): array
    {
        return [
            'id_paquete' => $this->id_paquete,
            'nombre' => $this->nombre,
            ...$this->recorrido->getDatosRecorrido(),
            'cantidad_etapas' => $this->recorrido->contarEtapas(),
            'servicios' => $this->servicios
                ->map(fn (Servicio $servicio) => $servicio->getServiciosIncluidos())
                ->all(),
            'cantidad_porteadores' => $this->cantidad_porteadores,
            'precio_base' => $this->precio_base,
            'costo_noche_extra_cusco' => $this->costo_noche_extra_cusco,
            'costo_equipo_camping' => $this->costo_equipo_camping,
            'promedio_valoraciones' => $this->calcularPromedioValoraciones(),
            'excursiones' => Excursion::getExcursionesDisponibles($this->id_paquete)
                ->map(fn (Excursion $excursion) => $excursion->getDetalle())
                ->all(),
        ];
    }

    // La consulta administrativa incluye todas las salidas; la disponibilidad del portal se mantiene en getDetalles().
    public function getExcursionesParaConsulta(): array
    {
        return $this->excursiones()->with('guia')->orderBy('fecha_salida')->get()
            ->map(fn (Excursion $excursion) => $excursion->getDetalle())
            ->all();
    }

    // El promedio no se guarda (es derivado, como dice el Documento de Normalización): sale del puntaje de experiencia
    // general de las valoraciones de las reservas de sus excursiones. Sin valoraciones devuelve null.
    public function calcularPromedioValoraciones(): ?float
    {
        $promedio = Valoracion::whereHas(
            'reserva.excursion',
            fn ($consulta) => $consulta->where('id_paquete', $this->id_paquete)
        )->avg('puntaje_experiencia_general');

        return $promedio === null ? null : round((float) $promedio, 1);
    }
}
