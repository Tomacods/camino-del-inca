<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

use App\Enums\MotivoDevolucion;
use App\Enums\EstadoReserva;
use App\Enums\EstadoSaldo;

class Reserva extends Model
{
    // Opciones de gestión: constantes para no depender de textos escritos a mano en las vistas
    public const OPCION_PAGAR_SALDO = 'Pagar saldo';
    public const OPCION_MODIFICAR = 'Modificar reserva';
    public const OPCION_CANCELAR = 'Cancelar reserva';
    public const OPCION_REINTEGRO = 'Solicitar reintegro';
    public const OPCION_REPROGRAMAR = 'Solicitar reprogramación';
    public const OPCION_VALORAR = 'Valorar servicios';

    protected $table = 'reservas';
    protected $primaryKey = 'id_reserva';

    protected $guarded = [];

    protected $casts = [
        'fecha_salida' => 'date',
        'fecha_limite_saldo' => 'date',
        'fecha_limite_confirmacion' => 'date',
        'estado' => EstadoReserva::class,
        'estado_saldo' => EstadoSaldo::class,
    ];

    public static function buscarPorCorreoYNumero(string $correo, string $numeroReserva): ?self
    {
        return self::datosDePrueba()->first(
            fn (self $reserva) => $reserva->correo_electronico === $correo
                && $reserva->numero_reserva === $numeroReserva
        );
    }

    private static function datosDePrueba(): Collection
    {
        $crear = function (string $numero, EstadoReserva $estado, EstadoSaldo $estadoSaldo, string $fechaSalida, array $pagos): self {
            $reserva = new self();
            $reserva->forceFill([
                'numero_reserva' => $numero,
                'correo_electronico' => 'lucia.fernandez@mail.com',
                'estado' => $estado,
                'estado_saldo' => $estadoSaldo,
                'paquete_nombre' => 'Camino Inca Clásico',
                'monto_total' => 2700,
                'fecha_limite_saldo' => '2026-12-18',
                'tiene_valoracion' => false,
            ]);

            $reserva->setRelation('excursion', new Excursion([
                'fecha_salida' => $fechaSalida,
                'cupo' => 12,
            ]));

            $reserva->setRelation('pagos', collect(array_map(
                fn (array $datos) => new Pago($datos),
                $pagos
            )));

            return $reserva;
        };

        $sena = ['fecha' => '2026-09-21', 'monto' => 1350, 'tipo_pago' => 'Seña', 'medio_pago' => 'Mercado Pago'];
        $saldo = ['fecha' => '2026-11-10', 'monto' => 1350, 'tipo_pago' => 'Saldo', 'medio_pago' => 'Mercado Pago'];
        $total = ['fecha' => '2026-09-21', 'monto' => 2700, 'tipo_pago' => 'Total', 'medio_pago' => 'Mercado Pago'];

        return collect([
            $crear('000124-7', EstadoReserva::Confirmada, EstadoSaldo::Adeudado, '2027-01-18', [$sena]),
            $crear('000125-4', EstadoReserva::Pendiente, EstadoSaldo::Adeudado, '2027-01-18', [$sena]),
            $crear('000126-1', EstadoReserva::SinPermiso, EstadoSaldo::Adeudado, '2027-01-18', [$sena]),
            $crear('000127-9', EstadoReserva::Finalizada, EstadoSaldo::Abonado, '2026-09-01', [$sena, $saldo]),
            $crear('000128-6', EstadoReserva::Cancelada, EstadoSaldo::Adeudado, '2027-01-18', [$sena]),
            $crear('000129-3', EstadoReserva::Confirmada, EstadoSaldo::Abonado, '2026-10-25', [$total]),
        ]);
    }

    public function devoluciones(): HasMany
    {
        return $this->hasMany(Devolucion::class, 'id_reserva', 'id_reserva');
    }

    public function excursion(): BelongsTo
    {
        return $this->belongsTo(Excursion::class, 'id_excursion', 'id_excursion');
    }

    // Antes era private: Eloquent no puede resolver una relación privada cuando se use la base real
    public function pagos(): HasMany
    {
        return $this->hasMany(Pago::class, 'id_reserva', 'id_reserva');
    }

    public function obtenerSaldoPendiente(): float
    {
        return $this->monto_total - $this->pagos->sum('monto');
    }

    public function obtenerOpcionesHabilitadas(): array
    {
        return match ($this->estado) {
            EstadoReserva::Confirmada => array_values(array_filter([
                $this->estado_saldo === EstadoSaldo::Adeudado ? self::OPCION_PAGAR_SALDO : null,
                self::OPCION_MODIFICAR,
                self::OPCION_CANCELAR,
            ])),
            EstadoReserva::Pendiente => [self::OPCION_CANCELAR],
            EstadoReserva::SinPermiso => [self::OPCION_REINTEGRO, self::OPCION_REPROGRAMAR],
            EstadoReserva::Finalizada => $this->tiene_valoracion ? [] : [self::OPCION_VALORAR],
            default => [], // Cancelada
        };
    }

    private function sumarPagos(): float
    {
        $montoAbonado = 0;
        foreach ($this->pagos as $pago) {
            $montoAbonado += $pago->consultarMonto();
        }
        return $montoAbonado;
    }

    public function calcularReembolso(Carbon $fecha_actual): float
    {
        // Primero se valida el estado; recién después se hace el cálculo
        if (!$this->validarEstado([EstadoReserva::Pendiente, EstadoReserva::Confirmada])) {
            throw new \Exception('La reserva se encuentra en estado «' . $this->estado->value . '» y no admite cancelación por esta vía.');
        }

        $fechaSalida = Carbon::parse($this->excursion->fecha_salida);

        return $this->calcularMontoReembolso($fecha_actual, $fechaSalida, $this->sumarPagos());
    }

    private function calcularMontoReembolso(Carbon $fecha_actual, Carbon $fecha_salida, float $monto_abonado): float
    {
        // Se comparan días calendario (sin hora) y enteros, para que "más de 30 días" sea exacto
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
        // $this->excursion->liberarCupo();
    }

    public function ejecutarCancelacionConReembolso(float $monto_reembolso, Carbon $fecha_actual): void
    {
        if ($monto_reembolso > 0) {
            $this->generarDevolucion($monto_reembolso, $fecha_actual, MotivoDevolucion::Reembolso);
        }

        $this->setEstado(EstadoReserva::Cancelada);
    }

    private function generarDevolucion(float $monto_reembolso, Carbon $fecha, MotivoDevolucion $motivo): void
    {
        /*
        $this->devoluciones()->create([
            'monto' => $monto_reembolso,
            'fecha' => $fecha,
            'motivo' => $motivo,
        ]);
        */
    }

    private function validarEstado(array $estados): bool
    {
        return in_array($this->estado, $estados, true);
    }

    private function setEstado(EstadoReserva $estado): void
    {
        $this->estado = $estado;
        //$this->save();
    }

    /* -------------------------------------------------------------------------- */
    /*   MÉTODOS PARA EL CASO DE USO "SOLICITAR REINTEGRO"                        */
    /* -------------------------------------------------------------------------- */

    public function calcularReintegro(): float
    {
        if (!$this->validarEstado([EstadoReserva::SinPermiso])) {
            throw new \Exception('La reserva se encuentra en estado «' . $this->estado->value . '» y no admite reintegro.');
        }

        // El reintegro devuelve el 100% de lo abonado, sin importar las fechas
        return $this->sumarPagos();
    }

    public function ejecutarCancelacionConReintegro(float $monto_reintegro, Carbon $fecha_actual): void
    {
        if ($monto_reintegro > 0) {
            $this->generarDevolucion($monto_reintegro, $fecha_actual, MotivoDevolucion::Reintegro);
        }

        $this->setEstado(EstadoReserva::Cancelada);
    }
}