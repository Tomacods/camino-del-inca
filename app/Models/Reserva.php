<?php

namespace App\Models;

use App\Enums\EstadoPermiso;
use App\Enums\EstadoReserva;
use App\Enums\EstadoSaldo;
use App\Enums\MotivoDevolucion;
use App\Enums\TipoPago;
use App\Enums\TipoServicio;
use App\Jobs\LiberarCupoRetenido;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class Reserva extends Model
{
    public const OPCION_PAGAR_SALDO = 'Pagar saldo';

    public const OPCION_MODIFICAR = 'Modificar reserva';

    public const OPCION_CANCELAR = 'Cancelar reserva';

    public const OPCION_REINTEGRO = 'Solicitar reintegro';

    public const OPCION_REPROGRAMAR = 'Solicitar reprogramación';

    public const OPCION_VALORAR = 'Valorar servicios';

    // Número de reserva (CU-15): seis cifras, guion y dígito verificador, módulo 11 con pesos 2 a 7 desde la derecha.
    private const CIFRAS_NUMERO_RESERVA = 6;

    private const PESOS_DIGITO_VERIFICADOR = [2, 3, 4, 5, 6, 7];

    // Candado de generarReserva() (CU-15). Se suelta solo a los 10 segundos, por si el proceso se cae con el candado
    // tomado; un pago espera hasta 5 a que se suelte.
    private const CANDADO_GENERAR_RESERVA = 'generar-reserva';

    private const SEGUNDOS_CANDADO = 10;

    private const SEGUNDOS_ESPERA_CANDADO = 5;

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
            'estado' => EstadoReserva::class,
            'estado_saldo' => EstadoSaldo::class,
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

    /* ----------------------------- CU-14 Realizar ----------------------------- */

    // Es estático porque cuando el cliente elige las noches todavía no existe la reserva: se guarda con el primer pago.
    public static function validarNochesExtra(int $nochesExtraAntes, int $nochesExtraDespues): bool
    {
        return $nochesExtraAntes >= 0
            && $nochesExtraDespues >= 0
            && $nochesExtraAntes + $nochesExtraDespues <= config('reserva.maximo_noches_extra');
    }

    /* ------------------------------ CU-15 Pagar ------------------------------- */

    // Pasos 8 a 11 con el primer pago aprobado: registra la reserva con sus integrantes, el pago con su comprobante y
    // pasa las plazas de retenidas a reservadas. Recibe la reserva en curso que CU-14 guardó en la sesión; no la lee de
    // ahí: se la pasa quien la llama. Es estático porque la reserva todavía no existe. Una retención genera una sola
    // reserva: devuelve null si la retención venció (A3) o si ya se liberó.
    public static function generarReserva(array $reservaEnCurso, TipoPago $tipoPago, string $medioPago): ?self
    {
        if ($tipoPago === TipoPago::Saldo) {
            throw new InvalidArgumentException('El saldo se paga sobre una reserva ya registrada (CU-16).');
        }

        // A3: si la retención venció, no se crea nada. Decide la hora del servidor, igual que en la pantalla de pago; de
        // liberar esas plazas ya se ocupan la tarea demorada y la pantalla. Este primer control es para salir rápido,
        // sin esperar el candado.
        $vence = Carbon::parse($reservaEnCurso['vence']);

        if (now()->greaterThanOrEqualTo($vence)) {
            return null;
        }

        // El número siguiente se calcula mirando el último emitido: si dos clientes pagan a la vez, los dos verían el
        // mismo. Con el candado, mientras una reserva se registra la siguiente espera su turno. Si no lo consigue en el
        // plazo, block() lanza una excepción y no se registra nada.
        $candado = Cache::lock(self::CANDADO_GENERAR_RESERVA, self::SEGUNDOS_CANDADO);
        $candado->block(self::SEGUNDOS_ESPERA_CANDADO);

        try {
            // La hora se toma con el candado ya tomado: es el momento en que se registran la reserva, el pago y el
            // comprobante, los tres con la misma.
            $ahora = now();

            // Se controla de nuevo, porque mientras se esperaba el candado la retención pudo vencer o liberarse: la
            // canceló el cliente, la liberó la tarea demorada o la usó un pago anterior. Si el mismo pago llega dos
            // veces, la segunda llamada espera a que la primera termine, encuentra la retención liberada y no
            // registra otra reserva.
            if ($ahora->greaterThanOrEqualTo($vence) || LiberarCupoRetenido::yaLiberada($reservaEnCurso['id_retencion'])) {
                return null;
            }

            // Todo en una sola transacción: o queda todo o no queda nada.
            return DB::transaction(function () use ($reservaEnCurso, $tipoPago, $medioPago, $ahora) {
                $excursion = Excursion::findOrFail($reservaEnCurso['id_excursion']);

                // En el DS-15 el pago va antes que la reserva, pero en la base el pago lleva la clave de la reserva
                // (pago.id_reserva no admite nulos): se guarda primero la reserva.
                $reserva = new self([
                    'correo_electronico' => $reservaEnCurso['correo_electronico'],
                    'noches_extra_antes' => $reservaEnCurso['noches_extra_antes'],
                    'noches_extra_despues' => $reservaEnCurso['noches_extra_despues'],
                    'fecha_reserva' => $ahora,
                    'numero_reserva' => self::generarNumeroReserva(),
                    'estado' => EstadoReserva::Pendiente,
                    'estado_saldo' => $tipoPago === TipoPago::Total ? EstadoSaldo::Abonado : EstadoSaldo::Adeudado,
                ]);
                $reserva->fijarFechasLimite($excursion->getFechaSalida());
                $excursion->agregarReserva($reserva);

                foreach ($reservaEnCurso['integrantes'] as $integrante) {
                    $reserva->excursionistas()->create([
                        'nombre' => $integrante['nombre'],
                        'apellido' => $integrante['apellido'],
                        'documento_pasaporte' => $integrante['documento_pasaporte'],
                        'equipo_camping' => $integrante['equipo_camping'],
                        'estado_permiso' => EstadoPermiso::Pendiente,
                    ]);
                }

                $monto = Pago::calcularMontoAPagar($reserva->calcularMontoTotal(), $tipoPago);
                Pago::registrarPago($reserva, $monto, $ahora, $medioPago, $tipoPago);

                // Se libera con la misma tarea que la demorada y dentro de la transacción: cuando la demorada llegue, no
                // descuenta de nuevo. Las plazas pasan de retenidas a reservadas: el cupo disponible queda igual. La
                // marca de «ya liberada» se guarda con la transacción, antes de soltar el candado: un segundo pago con
                // esta retención ya la encuentra.
                LiberarCupoRetenido::dispatchSync($excursion->id_excursion, count($reservaEnCurso['integrantes']), $reservaEnCurso['id_retencion']);

                return $reserva;
            });
        } finally {
            // Se suelta aunque la transacción falle: si no, el pago siguiente tendría que esperar a que venza solo.
            $candado->release();
        }
    }

    // Cada cifra, desde la derecha, se multiplica por su peso (2, 3, ..., 7 y vuelve a empezar); el dígito es el resto
    // de dividir la suma por 11. Devuelve de 0 a 10: quien lo usa decide qué hacer con el 10.
    public static function calcularDigitoVerificador(int $numero): int
    {
        $cifras = array_reverse(str_split((string) $numero));
        $pesos = self::PESOS_DIGITO_VERIFICADOR;
        $suma = 0;

        foreach ($cifras as $posicion => $cifra) {
            $suma += (int) $cifra * $pesos[$posicion % count($pesos)];
        }

        return $suma % 11;
    }

    // El secuencial sigue al del último número emitido; sin reservas, empieza en 1. Como todos tienen seis cifras con
    // ceros a la izquierda, ordenarlos como texto es ordenarlos como números.
    public static function generarNumeroReserva(): string
    {
        $ultimoNumero = self::orderByDesc('numero_reserva')->value('numero_reserva');
        $secuencial = $ultimoNumero === null ? 1 : (int) Str::before($ultimoNumero, '-') + 1;

        // Un resto de 10 no entra en una cifra: esos números no se emiten.
        while (self::calcularDigitoVerificador($secuencial) === 10) {
            $secuencial++;
        }

        return str_pad((string) $secuencial, self::CIFRAS_NUMERO_RESERVA, '0', STR_PAD_LEFT)
            .'-'.self::calcularDigitoVerificador($secuencial);
    }

    // Vencen a las 23:59:59, como en los seeders. subMonthsNoOverflow no se pasa al mes siguiente: del 29/03 va al 28/02
    // y no al 01/03. Carbon::parse hace una copia, así no cambia la fecha de salida de la excursión. Usa estado_saldo:
    // se llama después de asignarlo (en el DS-15 va antes de setEstadoSaldo). Sólo asigna: guarda quien la llama.
    public function fijarFechasLimite($fechaSalida): void
    {
        $fechaLimite = Carbon::parse($fechaSalida)
            ->subMonthsNoOverflow(config('reserva.meses_anticipacion_fechas_limite'))
            ->endOfDay();

        $this->fecha_limite_confirmacion = $fechaLimite;
        $this->fecha_limite_saldo = $this->estado_saldo === EstadoSaldo::Adeudado ? $fechaLimite : null;
    }

    /* ----------------------------- CU-18 Consultar ---------------------------- */

    // Los correos se guardan en minúsculas y sin espacios en las puntas: el que escribe el cliente se convierte igual,
    // así encuentra su reserva aunque lo escriba con otras mayúsculas.
    public static function buscarPorCorreoYNumero(string $correo, string $numeroReserva): ?self
    {
        return self::where('correo_electronico', mb_strtolower(trim($correo)))
            ->where('numero_reserva', $numeroReserva)
            ->first();
    }

    public function calcularMontoTotal(): float
    {
        return $this->excursion->paquete->calcularMonto(
            $this->excursionistas->count(),
            $this->noches_extra_antes + $this->noches_extra_despues,
            $this->excursionistas->where('equipo_camping', true)->count(),
        );
    }

    public function getDetalle(): self
    {
        return $this->load([
            'excursion.paquete',
            'excursionistas',
            'pagos' => fn ($pagos) => $pagos->orderBy('fecha'),
        ]);
    }

    public function calcularSaldoPendiente(): float
    {
        return $this->calcularMontoTotal() - $this->sumarPagos();
    }

    public function getOpcionesHabilitadas(): array
    {
        return $this->determinarOpciones($this->estado, $this->estado_saldo, $this->tieneValoracion());
    }

    public function tieneValoracion(): bool
    {
        return $this->valoracion()->exists();
    }

    private function determinarOpciones(EstadoReserva $estado, EstadoSaldo $estadoSaldo, bool $tieneValoracion): array
    {
        return match ($estado) {
            EstadoReserva::Confirmada => array_values(array_filter([
                $estadoSaldo === EstadoSaldo::Adeudado ? self::OPCION_PAGAR_SALDO : null,
                self::OPCION_MODIFICAR,
                self::OPCION_CANCELAR,
            ])),
            EstadoReserva::Pendiente => [self::OPCION_CANCELAR],
            EstadoReserva::SinPermiso => [self::OPCION_REINTEGRO, self::OPCION_REPROGRAMAR],
            EstadoReserva::Finalizada => $tieneValoracion ? [] : [self::OPCION_VALORAR],
            default => [], // Cancelada
        };
    }

    /* ----------------------------- CU-21 Cancelar ----------------------------- */

    public function calcularReembolso(Carbon $fecha_actual): float
    {
        if (! $this->validarEstado([EstadoReserva::Pendiente, EstadoReserva::Confirmada])) {
            throw new \Exception('La reserva se encuentra en estado «'.$this->estado->value.'» y no admite cancelación por esta vía.');
        }

        $fechaSalida = Carbon::parse($this->excursion->fecha_salida);

        return $this->calcularMontoReembolso($fecha_actual, $fechaSalida, $this->sumarPagos());
    }

    private function calcularMontoReembolso(Carbon $fecha_actual, Carbon $fecha_salida, float $monto_abonado): float
    {
        $diasAnticipacion = (int) $fecha_actual->copy()->startOfDay()
            ->diffInDays($fecha_salida->copy()->startOfDay());
        $diasLimite = config('reserva.dias_anticipacion_reembolso');
        $porcentaje = config('reserva.porcentaje_reembolso');

        if ($diasAnticipacion > $diasLimite && $fecha_salida->greaterThan($fecha_actual)) {
            return $monto_abonado * $porcentaje;
        }

        return 0.0;
    }

    public function confirmarCancelacion(Carbon $fecha_actual): void
    {
        $montoReembolso = $this->calcularReembolso($fecha_actual);
        $this->ejecutarCancelacionConReembolso($montoReembolso, $fecha_actual);
    }

    public function ejecutarCancelacionConReembolso(float $monto_reembolso, Carbon $fecha_actual): void
    {
        $this->cancelarRegistrandoDevolucion($monto_reembolso, $fecha_actual, MotivoDevolucion::Reembolso);
    }

    /* --------------------------- CU-17 Solicitar Reintegro -------------------- */

    public function calcularReintegro(): float
    {
        if (! $this->validarEstado([EstadoReserva::SinPermiso])) {
            throw new \Exception('La reserva se encuentra en estado «'.$this->estado->value.'» y no admite reintegro.');
        }

        return $this->sumarPagos();
    }

    public function ejecutarCancelacionConReintegro(float $monto_reintegro, Carbon $fecha_actual): void
    {
        $this->cancelarRegistrandoDevolucion($monto_reintegro, $fecha_actual, MotivoDevolucion::Reintegro);
    }

    private function cancelarRegistrandoDevolucion(float $monto, Carbon $fecha, MotivoDevolucion $motivo): void
    {
        DB::transaction(function () use ($monto, $fecha, $motivo) {
            if ($monto > 0) {
                $this->devolucion()->create([
                    'fecha' => $fecha,
                    'monto' => $monto,
                    'motivo' => $motivo,
                ]);
            }

            $this->estado = EstadoReserva::Cancelada;
            $this->save();
        });
    }

    private function sumarPagos(): float
    {
        return (float) $this->pagos->sum('monto');
    }

    private function validarEstado(array $estados): bool
    {
        return in_array($this->estado, $estados, true);
    }

    /* ------------------- Filtros del listado (CU-07) ------------------- */

    public function scopeDeEstado(Builder $consulta, EstadoReserva $estado): Builder
    {
        return $consulta->where('estado', $estado->value);
    }

    public function scopeDePaquete(Builder $consulta, int $idPaquete): Builder
    {
        return $consulta->whereHas('excursion', fn (Builder $excursion) => $excursion->where('id_paquete', $idPaquete));
    }

    public function scopeDeGuia(Builder $consulta, int $idGuia): Builder
    {
        return $consulta->whereHas('excursion', fn (Builder $excursion) => $excursion->where('id_guia', $idGuia));
    }

    public function scopeConEquipoCamping(Builder $consulta): Builder
    {
        return $consulta->whereHas('excursionistas', fn (Builder $integrante) => $integrante->where('equipo_camping', true));
    }

    public function scopeConServicio(Builder $consulta, TipoServicio $tipo): Builder
    {
        return $consulta->whereHas('excursion.paquete.servicios', fn (Builder $servicio) => $servicio->where('tipo', $tipo->value));
    }

/* ----------------------------- CU-20 Modificar ---------------------------- */
public static function modificarReserva(string $correo, string $numeroReserva)
{
    $reserva =  $this->buscarPorCorreoYNumero($correo, $numeroReserva);

}
public function iniciarModificacion(){
    if ($this->validarEstado([EstadoReserva::Confirmada])) {
        # code...
    }
}

private function getCantidadExcursionistas(): int
{
    return $this->excursionistas->count();
}

public function elegirExcursionDestino(Carbon $fechaElegida): array
{
    $habilitadas = Excursion::buscarOtrasDelPaquete(
        $this->excursion->id_paquete,
        $this->excursion->fecha_salida,
        $this->getCantidadExcursionistas()
    );

    $destino = $habilitadas->first(
        fn ($excursion) => $excursion->fecha_salida->isSameDay($fechaElegida)
    );

    if ($destino === null) {
        throw new \DomainException('La excursión elegida ya no está disponible.');
    }

    return [
        'origen' => $this->excursion->getDatosExcursion(),
        'destino' => $destino->getDatosExcursion(),
    ];
}

private function cambiarExcursion(Excursion $destino): void
{
    $this->id_excursion = $destino->id_excursion;
}

public function recalcularFechasLimite(Carbon $fechaSalida): void
{
    $limite = $fechaSalida->copy()
        ->subMonthsNoOverflow(config('reserva.meses_anticipacion_fechas_limite'));

    $this->fecha_limite_confirmacion = $limite;

    if ($this->estado_saldo === EstadoSaldo::Adeudado) {
        $this->fecha_limite_saldo = $limite;
    }
}

public function modificarExcursion(Excursion $destino): void
{
    DB::transaction(function () use ($destino) {
        $this->cambiarExcursion($destino);
        $this->estado = EstadoReserva::Pendiente;

        foreach ($this->excursionistas as $excursionista) {
            $excursionista->actualizarPermisosPendiente();
        }

        $this->recalcularFechasLimite($destino->fecha_salida);
        $this->save();
    });
}
}