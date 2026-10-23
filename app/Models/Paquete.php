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

    // R-01: al crearse, el paquete queda «Activo», listo para recibir reservas. Los servicios y las excursiones los
    // guarda después el formulario del panel, con sus relaciones.
    public static function crearPaquete(array $datos): self
    {
        return self::create([
            ...$datos,
            'estado' => EstadoPaquete::Activo,
            'fecha_creacion' => today(),
        ]);
    }

    // R-01, dentro de la transacción que guarda el paquete y sus excursiones. El formulario ya revisó los guías, pero
    // otro pedido puede ocupar a uno entre esa validación y el guardado: acá se bloquea a los guías y se vuelve a
    // revisar. Si dos pedidos se cruzan, el segundo espera el bloqueo, ve la excursión del primero y se rechaza.
    public function confirmarGuiasDisponibles(): void
    {
        $fecha = $this->buscarConflictoConGuiasBloqueados();

        if ($fecha !== null) {
            throw new DomainException('El guía de la salida del '.$fecha->format('d/m/Y')
                .' ya conduce otra excursión en esas fechas.');
        }
    }

    // Bloquea a los guías de sus salidas, en orden para que dos pedidos no se traben entre sí, y busca superposiciones
    // con las excursiones ya guardadas de otros paquetes.
    private function buscarConflictoConGuiasBloqueados(bool $soloFuturas = false): ?Carbon
    {
        $excursiones = $this->excursiones()
            ->when($soloFuturas, fn ($consulta) => $consulta->whereDate('fecha_salida', '>=', today()))
            ->get();

        Guia::whereIn('id_usuario', $excursiones->pluck('id_guia')->unique()->all())
            ->orderBy('id_usuario')
            ->lockForUpdate()
            ->get();

        return Excursion::buscarConflictoDeGuia(
            $excursiones->map(fn (Excursion $excursion) => [
                'fecha_salida' => $excursion->fecha_salida->format('Y-m-d'),
                'id_guia' => $excursion->id_guia,
            ])->all(),
            $this->recorrido->duracion_dias,
            $this->id_paquete,
        );
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

    // R-02: el nombre, los porteadores y los precios. El recorrido no cambia. Los servicios y las excursiones los guarda
    // después el formulario del panel, con sus relaciones.
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
            $fecha = $this->buscarConflictoConGuiasBloqueados(soloFuturas: true);

            if ($fecha !== null) {
                throw new DomainException('El guía de la salida del '.$fecha->format('d/m/Y')
                    .' ya conduce otra excursión en esas fechas. Cambiá el guía y volvé a intentarlo.');
            }

            $this->update(['estado' => EstadoPaquete::Activo]);
        });
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
}
