<?php

namespace App\Models;

use App\Enums\EstadoPaquete;
use App\Enums\EstadoReserva;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class Excursion extends Model
{
    protected $table = 'excursion';

    protected $primaryKey = 'id_excursion';

    public $timestamps = false;

    protected $fillable = [
        'id_paquete',
        'id_guia',
        'id_etapa_actual',
        'fecha_salida',
        'cupo',
        'plazas_retenidas',
        'fecha_hora_inicio_recorrido',
    ];

    protected function casts(): array
    {
        return [
            'fecha_salida' => 'date',
            'fecha_hora_inicio_recorrido' => 'datetime',
        ];
    }

    public function paquete(): BelongsTo
    {
        return $this->belongsTo(Paquete::class, 'id_paquete', 'id_paquete');
    }

    public function guia(): BelongsTo
    {
        return $this->belongsTo(Guia::class, 'id_guia', 'id_usuario');
    }

    public function etapaActual(): BelongsTo
    {
        return $this->belongsTo(Etapa::class, 'id_etapa_actual', 'id_etapa');
    }

    public function reservas(): HasMany
    {
        return $this->hasMany(Reserva::class, 'id_excursion', 'id_excursion');
    }

    // whereDate compara sólo el día: así funciona igual en PostgreSQL y en SQLite (las pruebas), que guarda la fecha con
    // hora. La fecha tiene que llegar ya validada: en PostgreSQL una fecha mal escrita rompe la consulta.
    public static function buscarPorFechaSalida(int $idPaquete, string $fechaSalida): ?self
    {
        return self::where('id_paquete', $idPaquete)
            ->whereDate('fecha_salida', $fechaSalida)
            ->first();
    }

    public function getFechaSalida(): Carbon
    {
        return $this->fecha_salida;
    }

    // Las reservas canceladas o finalizadas ya no ocupan lugar en la salida.
    public function sumarPlazasReservadas(): int
    {
        return Excursionista::whereHas('reserva', function ($consulta) {
            $consulta->where('id_excursion', $this->id_excursion)
                ->whereIn('estado', [
                    EstadoReserva::Pendiente,
                    EstadoReserva::Confirmada,
                    EstadoReserva::SinPermiso,
                ]);
        })->count();
    }

    public function obtenerCupoDisponible(): int
    {
        return $this->cupo - $this->sumarPlazasReservadas() - $this->plazas_retenidas;
    }

    public function tieneCupoPara(int $cantidadIntegrantes): bool
    {
        return $this->obtenerCupoDisponible() >= $cantidadIntegrantes;
    }

    public function admiteReserva($fecha): bool
    {
        return $this->paquete->estado === EstadoPaquete::Activo
            && $this->cumpleAnticipacionMinima($fecha);
    }

    // Se comparan días, no horas: una salida justo a los 3 meses de la fecha se admite aunque se reserve de tarde.
    public function cumpleAnticipacionMinima($fecha): bool
    {
        $fechaMinimaSalida = Carbon::parse($fecha)
            ->addMonthsNoOverflow(config('reserva.meses_anticipacion_minima'))
            ->startOfDay();

        return $this->fecha_salida->greaterThanOrEqualTo($fechaMinimaSalida);
    }

    // La fila se bloquea hasta el final de la transacción para que dos clientes a la vez no retengan el mismo lugar.
    public function retenerCupo(int $cantidadPlazas): bool
    {
        if ($cantidadPlazas < 1) {
            throw new InvalidArgumentException('La cantidad de plazas a retener tiene que ser 1 o más.');
        }

        return DB::transaction(function () use ($cantidadPlazas) {
            $excursion = Excursion::lockForUpdate()->findOrFail($this->id_excursion);

            if (! $excursion->tieneCupoPara($cantidadPlazas)) {
                return false;
            }

            $excursion->plazas_retenidas += $cantidadPlazas;
            $excursion->save();

            $this->plazas_retenidas = $excursion->plazas_retenidas;

            return true;
        });
    }

    public function liberarCupoRetenido(int $cantidadPlazas): void
    {
        if ($cantidadPlazas < 1) {
            throw new InvalidArgumentException('La cantidad de plazas a liberar tiene que ser 1 o más.');
        }

        DB::transaction(function () use ($cantidadPlazas) {
            $excursion = Excursion::lockForUpdate()->findOrFail($this->id_excursion);

            $excursion->plazas_retenidas = max(0, $excursion->plazas_retenidas - $cantidadPlazas);
            $excursion->save();

            $this->plazas_retenidas = $excursion->plazas_retenidas;
        });
    }

    /* ------------------------------ CU-15 Pagar ------------------------------- */

    // Asocia la reserva a esta excursión y la guarda.
    public function agregarReserva(Reserva $reserva): void
    {
        $this->reservas()->save($reserva);
    }

    /* ------------------------ CU-10 Listar integrantes ------------------------ */

    // Las excursiones a cargo de un guía: en el panel, cada guía ve sólo las suyas.
    public function scopeDeGuia(Builder $consulta, int $idGuia): Builder
    {
        return $consulta->where('id_guia', $idGuia);
    }

    // El guía ve sólo los integrantes de las reservas confirmadas: las que están pendientes o sin permiso todavía pueden
    // no viajar. Ordenadas por número, para mostrar los integrantes agrupados por reserva.
    public function reservasConfirmadas(): HasMany
    {
        return $this->reservas()->where('estado', EstadoReserva::Confirmada)->orderBy('numero_reserva');
    }

    // Busca la excursión por el paquete y la fecha de salida, que la identifican. Trae de una vez el paquete, el guía y las
    // reservas confirmadas con sus integrantes, así mostrarla no consulta la base por cada reserva.
    public static function seleccionarExcursion(int $idPaquete, string $fechaSalida): ?self
    {
        return self::buscarPorFechaSalida($idPaquete, $fechaSalida)?->load([
            'paquete',
            'guia',
            'reservasConfirmadas.excursionistas' => fn ($consulta) => $consulta->orderBy('apellido')->orderBy('nombre'),
        ]);
    }

    // El encabezado de la página de integrantes. No se llama getDatosExcursion() porque ese nombre lo usan CU-20 y CU-13
    // para traer la excursión con el paquete y el guía.
    public function getEncabezado(): array
    {
        return [
            'paquete' => $this->paquete->nombre,
            'fecha_salida' => $this->getFechaSalida(),
            'guia' => $this->guia->getNombreCompleto(),
            'cupo' => $this->cupo,
            'integrantes' => $this->contarIntegrantesConfirmados(),
        ];
    }

    public function getReservasConfirmadas(): Collection
    {
        return $this->reservasConfirmadas;
    }

    // La cantidad que muestra «Mis excursiones»: los mismos integrantes que lista la página de la excursión.
    public function contarIntegrantesConfirmados(): int
    {
        return $this->getReservasConfirmadas()->sum(fn (Reserva $reserva) => $reserva->getExcursionistas()->count());
    }

    /* ------------------------- CU-11 Iniciar recorrido ------------------------ */

    // También la usa Reportar Etapa (CU-12): no se puede reportar una etapa de un recorrido que todavía no empezó.
    public function recorridoIniciado(): bool
    {
        return $this->fecha_hora_inicio_recorrido !== null;
    }

    // Un recorrido de 4 días que sale el lunes termina el jueves: el día de salida cuenta como el primero.
    public function getUltimoDiaRecorrido(): Carbon
    {
        return $this->fecha_salida->copy()->addDays($this->paquete->recorrido->duracion_dias - 1);
    }

    // «El día correspondiente» es cualquiera de los días del recorrido, no sólo el de la salida: si el guía se olvida de
    // iniciarlo el lunes, lo puede hacer después y la excursión no queda sin poder reportar etapas (CU-12). Se comparan
    // días, no horas, como en cumpleAnticipacionMinima(): vale cualquier hora del primero y del último día.
    public function esDiaDelRecorrido($fecha): bool
    {
        $dia = Carbon::parse($fecha)->startOfDay();

        return $dia->betweenIncluded($this->fecha_salida, $this->getUltimoDiaRecorrido());
    }

    // Para mostrar u ocultar la acción en el panel. La misma regla que controla iniciarRecorrido() antes de registrar.
    public function admiteInicioRecorrido($fecha): bool
    {
        return ! $this->recorridoIniciado() && $this->esDiaDelRecorrido($fecha);
    }

    // Paso 4, A1 y A2. La fila se bloquea hasta el final de la transacción: si el guía toca dos veces o tiene la salida
    // abierta en dos pestañas, el segundo pedido espera al primero, encuentra el recorrido iniciado y no pisa la hora.
    // Si no se puede iniciar, lanza DomainException con el aviso para el guía, como iniciarValidacionPermisos() de CU-04.
    public function iniciarRecorrido(): void
    {
        DB::transaction(function () {
            $excursion = Excursion::lockForUpdate()->findOrFail($this->id_excursion);
            $ahora = now();

            if ($excursion->recorridoIniciado()) {
                throw new DomainException('El recorrido ya se inició el '.$excursion->fecha_hora_inicio_recorrido->format('d/m/Y').' a las '
                    .$excursion->fecha_hora_inicio_recorrido->format('H:i').'.');
            }

            if (! $excursion->esDiaDelRecorrido($ahora)) {
                throw new DomainException('El recorrido sólo se puede iniciar entre el día de la salida ('.$excursion->fecha_salida->format('d/m/Y')
                    .') y el último día del recorrido ('.$excursion->getUltimoDiaRecorrido()->format('d/m/Y').').');
            }

            $excursion->fecha_hora_inicio_recorrido = $ahora;
            $excursion->save();

            $this->fecha_hora_inicio_recorrido = $excursion->fecha_hora_inicio_recorrido;
        });
    }

    /* -------------------------- CU-12 Reportar etapa -------------------------- */

    // Las etapas planificadas del recorrido del paquete, en orden.
    public function getEtapas(): Collection
    {
        return $this->paquete->getEtapas();
    }

    // La última etapa reportada. Nula hasta que el guía reporta la primera.
    public function getEtapaActual(): ?Etapa
    {
        return $this->etapaActual;
    }

    // Las etapas se reportan de a una y en el orden fijo del recorrido (regla de negocio): la siguiente es la primera con
    // un orden mayor que el de la actual, o la primera del recorrido si todavía no se reportó ninguna. Es nula cuando la
    // etapa actual es la final.
    public function obtenerSiguienteEtapa(): ?Etapa
    {
        $ordenActual = $this->getEtapaActual()?->orden ?? 0;

        return $this->getEtapas()->first(fn (Etapa $etapa) => $etapa->orden > $ordenActual);
    }

    // La etapa actual es la última del recorrido: el viaje terminó.
    public function esEtapaFinal(): bool
    {
        return $this->getEtapaActual() !== null && $this->obtenerSiguienteEtapa() === null;
    }

    // Para adelantar en la confirmación que reportar la siguiente termina el viaje (A2).
    public function esSiguienteEtapaFinal(): bool
    {
        $siguiente = $this->obtenerSiguienteEtapa();

        return $siguiente !== null && $siguiente->is($this->getEtapas()->last());
    }

    // Paso 2: las etapas planificadas, la última reportada y la siguiente, para mostrarlas antes de reportar.
    public function recuperarEtapaActual(): array
    {
        return [
            'etapas' => $this->getEtapas(),
            'etapa_actual' => $this->getEtapaActual(),
            'siguiente' => $this->obtenerSiguienteEtapa(),
        ];
    }

    // Para mostrar u ocultar la acción en el panel: la precondición del caso de uso. No hay un plazo en días, a diferencia
    // de iniciarRecorrido(): si el guía reporta tarde la etapa final, las reservas igual llegan a «Finalizada» y el
    // cliente puede valorar (CU-23).
    public function admiteReporteEtapa(): bool
    {
        return $this->recorridoIniciado() && $this->obtenerSiguienteEtapa() !== null;
    }

    // Paso 1: el guía elige la excursión en curso, así que «Mis excursiones» la muestra primera. Es la misma regla que
    // admiteReporteEtapa(), escrita en SQL para ordenar: el recorrido se inició y falta la etapa final, porque todavía no
    // se reportó ninguna o porque hay una etapa del mismo recorrido con un orden mayor que la actual. Con EXISTS y sin
    // funciones propias de un motor, anda igual en PostgreSQL y en SQLite (las pruebas).
    public function scopeEnCursoPrimero(Builder $consulta): Builder
    {
        return $consulta->orderByRaw('CASE WHEN excursion.fecha_hora_inicio_recorrido IS NOT NULL AND ('
            .'excursion.id_etapa_actual IS NULL OR EXISTS ('
            .'SELECT 1 FROM etapa AS actual JOIN etapa AS posterior ON posterior.id_recorrido = actual.id_recorrido '
            .'WHERE actual.id_etapa = excursion.id_etapa_actual AND posterior.orden > actual.orden'
            .')) THEN 0 ELSE 1 END');
    }

    // Pasos 3 y 4, A1 y A2. Recibe la etapa que el guía vio en la confirmación y la registra sólo si sigue siendo la
    // siguiente. La fila se bloquea hasta el final de la transacción: si el guía toca dos veces, tiene la excursión abierta
    // en dos pestañas o reintenta después de un corte de conexión (A3) cuando el primer pedido sí había llegado, el
    // segundo espera al primero, encuentra esa etapa ya reportada y no avanza otra.
    // Con la etapa final, las reservas cambian en la misma transacción: o queda todo o no queda nada. Devuelve las
    // reservas que pasaron a «Finalizada» (ninguna, si no era la final), para que quien la llamó invoque Notificar
    // Cliente (CU-13) después de la transacción. Si no se puede reportar, lanza DomainException con el aviso para el guía.
    // El DS-12 tiene actualizarEtapaActual() sin parámetros: la etapa se agrega para no avanzar dos con dos pedidos
    // iguales.
    public function actualizarEtapaActual(int $idEtapa): Collection
    {
        return DB::transaction(function () use ($idEtapa) {
            $excursion = Excursion::lockForUpdate()->findOrFail($this->id_excursion);

            if (! $excursion->recorridoIniciado()) {
                throw new DomainException('El recorrido todavía no se inició: primero tenés que iniciarlo.');
            }

            $siguiente = $excursion->obtenerSiguienteEtapa();

            if ($siguiente === null) {
                throw new DomainException('El recorrido ya terminó: la etapa final, «'.$excursion->getEtapaActual()?->nombre.'», ya se reportó.');
            }

            if ($siguiente->id_etapa !== $idEtapa) {
                throw new DomainException($idEtapa === (int) $excursion->id_etapa_actual
                    ? 'La etapa «'.$excursion->getEtapaActual()->nombre.'» ya se reportó. La siguiente es «'.$siguiente->nombre.'».'
                    : 'Las etapas se reportan en orden: la siguiente es «'.$siguiente->nombre.'».');
            }

            $excursion->setEtapaActual($siguiente);

            $reservasFinalizadas = $excursion->esEtapaFinal() ? $excursion->finalizarReservasConfirmadas() : new Collection;

            $this->id_etapa_actual = $excursion->id_etapa_actual;
            $this->setRelation('etapaActual', $siguiente);

            return $reservasFinalizadas;
        });
    }

    private function setEtapaActual(Etapa $etapa): void
    {
        $this->etapaActual()->associate($etapa);
        $this->save();
    }

    // A2 4.3: sólo las «Confirmada»; una «Pendiente» o «Sin Permiso» que haya quedado no viajó y no cambia. Se leen con
    // bloqueo: si en ese momento se está guardando una cancelación (CU-21), la lectura la espera y esa reserva, ya
    // «Cancelada», no se finaliza. Al revés no alcanza: una cancelación que leyó la reserva «Confirmada» antes y la guarda
    // mientras tanto sólo espera a que termine esta transacción, y después la pisa. Eso lo tiene que controlar CU-21.
    private function finalizarReservasConfirmadas(): Collection
    {
        $reservas = $this->reservasConfirmadas()->lockForUpdate()->get();

        $reservas->each(fn (Reserva $reserva) => $reserva->finalizarReserva());

        return $reservas;
    }

    /* ------------------------------ CU-20 Modificar ------------------------------- */

    public function getDatosExcursion(): self
    {
        return $this->load(['paquete', 'guia']);
    }

    // No se revisa el estado del paquete: R-03 no deja pasar a «Inactivo» un paquete con reservas «Pendiente»,
    // «Confirmada» o «Sin Permiso», así que el de una reserva «Confirmada» o «Sin Permiso» está siempre «Activo».
    public static function buscarOtrasDelPaquete(int $idPaquete, Carbon $fechaOrigen, int $cantidad): Collection
    {
        $otras = self::with(['paquete', 'guia'])
            ->where('id_paquete', $idPaquete)
            ->whereDate('fecha_salida', '!=', $fechaOrigen)
            ->get();

        return self::filtrarHabilitadas($otras, $cantidad);
    }

    public static function filtrarHabilitadas(Collection $excursiones, int $cantidad): Collection
    {
        return $excursiones
            ->filter(fn ($excursion) => $excursion->cumpleAnticipacionMinima(now()))
            ->each(fn ($excursion) => $excursion->cupo_disponible = $excursion->obtenerCupoDisponible())
            ->filter(fn ($excursion) => $excursion->cupo_disponible >= $cantidad)
            ->values();
    }

    /* ------------------------- CU-05 Listar paquetes ------------------------- */

    // También para CU-06: una salida está disponible si se puede reservar, es decir, si está a 3 meses o más (la misma
    // regla con la que Realizar Reserva la acepta) y le queda al menos un lugar. La anticipación se mira primero, así
    // no se calcula el cupo de las salidas que no se van a mostrar.
    public static function getExcursionesDisponibles(int $idPaquete): Collection
    {
        return self::buscarFuturas($idPaquete)
            ->filter(fn (Excursion $excursion) => $excursion->cumpleAnticipacionMinima(today()))
            ->filter(fn (Excursion $excursion) => $excursion->obtenerCupoDisponible() > 0)
            ->values();
    }

    public static function buscarFuturas(int $idPaquete): Collection
    {
        return self::where('id_paquete', $idPaquete)
            ->whereDate('fecha_salida', '>', today())
            ->orderBy('fecha_salida')
            ->get();
    }

    // Búsqueda por fecha: si el paquete tiene una salida disponible ese día, con la misma regla que el listado. Busca
    // sólo esa salida, sin recalcular el cupo de las demás.
    public static function tieneFechaSalida(int $idPaquete, Carbon $fecha): bool
    {
        $excursion = self::buscarPorFechaSalida($idPaquete, $fecha->format('Y-m-d'));

        return $excursion !== null
            && $excursion->cumpleAnticipacionMinima(today())
            && $excursion->obtenerCupoDisponible() > 0;
    }

    /* ------------------------ CU-06 Consultar paquete ------------------------ */

    // Lo que muestra el detalle del paquete de cada salida disponible.
    public function getDetalle(): array
    {
        return [
            'fecha_salida' => $this->getFechaSalida(),
            'cupo_disponible' => $this->obtenerCupoDisponible(),
            'guia' => $this->guia->getNombreCompleto(),
        ];
    }
}
