<?php

namespace App\Models;

use App\Enums\EstadoReserva;
use App\Enums\EstadoSaldo;
use App\Enums\MotivoDevolucion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class Reserva extends Model
{
    public const ESTADO_PENDIENTE = 'Pendiente';

    public const ESTADO_CONFIRMADA = 'Confirmada';

    public const ESTADO_SIN_PERMISO = 'Sin Permiso';

    public const ESTADO_CANCELADA = 'Cancelada';

    public const ESTADO_FINALIZADA = 'Finalizada';

    public const ESTADO_SALDO_ADEUDADO = 'Adeudado';

    public const ESTADO_SALDO_ABONADO = 'Abonado';

    public const OPCION_PAGAR_SALDO = 'Pagar saldo';

    public const OPCION_MODIFICAR = 'Modificar reserva';

    public const OPCION_CANCELAR = 'Cancelar reserva';

    public const OPCION_REINTEGRO = 'Solicitar reintegro';

    public const OPCION_REPROGRAMAR = 'Solicitar reprogramación';

    public const OPCION_VALORAR = 'Valorar servicios';

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

    /* ----------------------------- CU-18 Consultar ---------------------------- */

    public static function buscarPorCorreoYNumero(string $correo, string $numeroReserva): ?self
    {
        return self::where('correo_electronico', $correo)
            ->where('numero_reserva', $numeroReserva)
            ->first();
    }

    public function obtenerMontoTotal(): float // puse este aca aunq vaya en paquete para usarlo momentaneamente, cuando este el metodo correspondinete uso ese
    {
        $paquete = $this->excursion->paquete;
        $integrantes = $this->excursionistas->count();
        $conEquipo = $this->excursionistas->where('equipo_camping', true)->count();
        $nochesExtra = $this->noches_extra_antes + $this->noches_extra_despues;

        return $integrantes * $paquete->precio_base
            + $conEquipo * $paquete->costo_equipo_camping
            + $nochesExtra * $integrantes * $paquete->costo_noche_extra_cusco;
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
        return $this->obtenerMontoTotal() - $this->sumarPagos();
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
        $diasLimite = config('reservas.dias_anticipacion_reembolso');
        $porcentaje = config('reservas.porcentaje_reembolso');

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
                    'motivo' => $motivo->value,
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
}
