<?php

namespace App\Models;

use App\Enums\EstadoPaquete;
use App\Enums\EstadoReserva;
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
}
