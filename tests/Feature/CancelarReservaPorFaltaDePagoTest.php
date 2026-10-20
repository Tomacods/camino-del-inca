<?php

namespace Tests\Feature;

use App\Enums\EstadoPaquete;
use App\Enums\EstadoPermiso;
use App\Enums\EstadoReserva;
use App\Enums\EstadoSaldo;
use App\Enums\Rol;
use App\Enums\TipoNotificacion;
use App\Enums\TipoPago;
use App\Mail\NotificacionCliente;
use App\Models\Devolucion;
use App\Models\Excursion;
use App\Models\Guia;
use App\Models\Pago;
use App\Models\Paquete;
use App\Models\Recorrido;
use App\Models\Reserva;
use App\Models\Usuario;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

// Cancelar Reserva por Falta de Pago de Saldo (CU-22). La salida es el lunes 28/12/2026 y el saldo vence el 28/11/2026
// a las 23:59:59: desde las 00:00:00 del 29/11 la reserva ya está vencida. Cada reserva tiene un integrante y la seña
// de USD 750 pagada.
class CancelarReservaPorFaltaDePagoTest extends TestCase
{
    use RefreshDatabase;

    private const FECHA_LIMITE_SALDO = '2026-11-28 23:59:59';

    private const VENCIDA = '2026-11-29 00:00:00';

    private Excursion $excursion;

    protected function setUp(): void
    {
        parent::setUp();

        $this->excursion = $this->crearExcursion('2026-12-28');
    }

    /* ------------------------------ Curso normal ------------------------------ */

    public function test_cancela_sin_devolucion_libera_el_cupo_y_notifica_al_titular(): void
    {
        Mail::fake();
        $reserva = $this->crearReserva('000125-9');
        $this->assertSame(11, $this->excursion->obtenerCupoDisponible());
        $this->travelTo(self::VENCIDA);

        $this->assertSame(1, Reserva::verificarVencimientosDePago(now()));

        $this->assertSame(EstadoReserva::Cancelada, $reserva->fresh()->estado);
        $this->assertSame(0, Devolucion::count());
        $this->assertSame(12, $this->excursion->fresh()->obtenerCupoDisponible());

        Mail::assertQueued(NotificacionCliente::class, 1);
        Mail::assertQueued(NotificacionCliente::class, fn (NotificacionCliente $correo) => $correo->hasTo('federico.rios@mail.com')
            && $correo->tipo === TipoNotificacion::CancelacionFaltaPago
            && $correo->datos['reserva']['numero_reserva'] === '000125-9');
    }

    // La postcondición vista desde CU-16: una reserva cancelada ya no registra el pago del saldo.
    public function test_despues_de_cancelar_ya_no_se_registra_el_pago_del_saldo(): void
    {
        Mail::fake();
        $reserva = $this->crearReserva('000125-9');
        $this->travelTo(self::VENCIDA);

        Reserva::verificarVencimientosDePago(now());

        $this->assertNull(Pago::registrarPagoSaldo($reserva, 'Tarjeta de débito'));
        $this->assertSame(EstadoSaldo::Adeudado, $reserva->fresh()->estado_saldo);
        $this->assertFalse($reserva->pagos()->where('tipo_pago', TipoPago::Saldo)->exists());
    }

    /* ---------------------------------- A1 ------------------------------------ */

    public function test_sin_reservas_vencidas_no_cambia_nada_ni_envia_correos(): void
    {
        Mail::fake();
        $reserva = $this->crearReserva('000125-9');
        $this->travelTo('2026-11-28 12:00:00');

        $this->assertSame(0, Reserva::verificarVencimientosDePago(now()));

        $this->assertSame(EstadoReserva::Confirmada, $reserva->fresh()->estado);
        $this->assertSame(11, $this->excursion->fresh()->obtenerCupoDisponible());
        Mail::assertNothingQueued();
    }

    /* ------------------------------ Borde de la fecha ------------------------- */

    public function test_a_las_235959_del_dia_limite_no_cancela_y_un_segundo_despues_si(): void
    {
        Mail::fake();
        $reserva = $this->crearReserva('000125-9');

        $this->travelTo(self::FECHA_LIMITE_SALDO);
        $this->assertSame(0, Reserva::verificarVencimientosDePago(now()));
        $this->assertSame(EstadoReserva::Confirmada, $reserva->fresh()->estado);

        $this->travelTo(self::VENCIDA);
        $this->assertSame(1, Reserva::verificarVencimientosDePago(now()));
        $this->assertSame(EstadoReserva::Cancelada, $reserva->fresh()->estado);
    }

    // Una reserva que quedó «Confirmada» el último día tiene 72 horas para pagar el saldo (CU-04 lo fijó a las 15:30): no
    // se cancela a la medianoche del día límite ni en el último segundo del plazo, y sí en el siguiente.
    public function test_con_el_plazo_de_72_horas_a_la_hora_limite_no_cancela_y_un_segundo_despues_si(): void
    {
        Mail::fake();
        $reserva = $this->crearReserva('000125-9', fechaLimiteSaldo: '2026-12-01 15:30:00');

        foreach ([self::VENCIDA, '2026-12-01 15:30:00'] as $momento) {
            $this->travelTo($momento);
            $this->assertSame(0, Reserva::verificarVencimientosDePago(now()), 'A las '.$momento);
        }
        $this->assertSame(EstadoReserva::Confirmada, $reserva->fresh()->estado);

        $this->travelTo('2026-12-01 15:30:01');
        $this->assertSame(1, Reserva::verificarVencimientosDePago(now()));
        $this->assertSame(EstadoReserva::Cancelada, $reserva->fresh()->estado);
    }

    // La consulta del paso 2 da lo mismo que verificarFechaLimiteSaldo(), la regla de CU-16, con la misma hora.
    public function test_la_busqueda_coincide_con_la_regla_de_la_fecha_limite(): void
    {
        $reserva = $this->crearReserva('000125-9');

        foreach (['2026-11-28 00:00:00', self::FECHA_LIMITE_SALDO, self::VENCIDA, '2026-12-15 10:00:00'] as $momento) {
            $hora = Carbon::parse($momento);

            $this->assertSame(
                ! $reserva->verificarFechaLimiteSaldo($hora),
                Reserva::buscarSaldosVencidos($hora)->contains('id_reserva', $reserva->id_reserva),
                'A las '.$momento,
            );
        }
    }

    /* ------------------------- Hora de la verificación ------------------------ */

    // La hora que recibe verificarVencimientosDePago() decide en todo el recorrido: al buscar y al volver a comprobar
    // la reserva con la fila bloqueada. La del reloj no cuenta.
    public function test_la_verificacion_usa_la_hora_que_recibe_y_no_la_del_reloj(): void
    {
        Mail::fake();
        $reserva = $this->crearReserva('000125-9');

        // Con el reloj después del vencimiento y una hora anterior, no la cancela.
        $this->travelTo(self::VENCIDA);
        $this->assertSame(0, Reserva::verificarVencimientosDePago(Carbon::parse(self::FECHA_LIMITE_SALDO)));
        $this->assertSame(EstadoReserva::Confirmada, $reserva->fresh()->estado);

        // Con el reloj antes del vencimiento y una hora posterior, la cancela.
        $this->travelTo(self::FECHA_LIMITE_SALDO);
        $this->assertSame(1, Reserva::verificarVencimientosDePago(Carbon::parse(self::VENCIDA)));
        $this->assertSame(EstadoReserva::Cancelada, $reserva->fresh()->estado);
        Mail::assertQueued(NotificacionCliente::class, 1);
    }

    /* ------------------------------ No cancela -------------------------------- */

    #[DataProvider('reservasQueNoSeCancelan')]
    public function test_no_cancela_lo_que_no_es_de_este_caso_de_uso(EstadoReserva $estado, EstadoSaldo $estadoSaldo, ?string $fechaLimiteSaldo): void
    {
        Mail::fake();
        $reserva = $this->crearReserva('000125-9', $estado, $estadoSaldo, $fechaLimiteSaldo);
        $this->travelTo('2026-12-20 10:00:00');

        $this->assertSame(0, Reserva::verificarVencimientosDePago(now()));

        $this->assertSame($estado, $reserva->fresh()->estado);
        $this->assertSame($estadoSaldo, $reserva->fresh()->estado_saldo);
        Mail::assertNothingQueued();
    }

    public static function reservasQueNoSeCancelan(): array
    {
        return [
            // fecha_limite_saldo no se borra al pagar el saldo.
            'saldo abonado con la fecha vencida' => [EstadoReserva::Confirmada, EstadoSaldo::Abonado, self::FECHA_LIMITE_SALDO],
            'sin fecha límite de saldo' => [EstadoReserva::Confirmada, EstadoSaldo::Adeudado, null],
            // Las pendientes y sin permiso con plazos vencidos son de CU-26.
            'pendiente' => [EstadoReserva::Pendiente, EstadoSaldo::Adeudado, self::FECHA_LIMITE_SALDO],
            'sin permiso' => [EstadoReserva::SinPermiso, EstadoSaldo::Adeudado, self::FECHA_LIMITE_SALDO],
            'cancelada' => [EstadoReserva::Cancelada, EstadoSaldo::Adeudado, self::FECHA_LIMITE_SALDO],
            'finalizada' => [EstadoReserva::Finalizada, EstadoSaldo::Adeudado, self::FECHA_LIMITE_SALDO],
        ];
    }

    /* ------------------------------ Varias reservas --------------------------- */

    public function test_cancela_todas_las_vencidas_y_deja_intactas_las_demas(): void
    {
        Mail::fake();
        $vencidas = [
            $this->crearReserva('000125-9'),
            $this->crearReserva('000126-0', fechaLimiteSaldo: '2026-11-20 23:59:59'),
            $this->crearReserva('000127-2'),
        ];
        $vigente = $this->crearReserva('000128-4', fechaLimiteSaldo: '2026-11-29 23:59:59');
        $abonada = $this->crearReserva('000129-6', estadoSaldo: EstadoSaldo::Abonado);
        $pendiente = $this->crearReserva('000130-2', EstadoReserva::Pendiente);
        $this->travelTo(self::VENCIDA);

        $this->assertSame(3, Reserva::verificarVencimientosDePago(now()));

        foreach ($vencidas as $reserva) {
            $this->assertSame(EstadoReserva::Cancelada, $reserva->fresh()->estado);
            Mail::assertQueued(NotificacionCliente::class, fn (NotificacionCliente $correo) => $correo->datos['reserva']['numero_reserva'] === $reserva->numero_reserva);
        }
        $this->assertSame(EstadoReserva::Confirmada, $vigente->fresh()->estado);
        $this->assertSame(EstadoReserva::Confirmada, $abonada->fresh()->estado);
        $this->assertSame(EstadoReserva::Pendiente, $pendiente->fresh()->estado);
        Mail::assertQueued(NotificacionCliente::class, 3);
        $this->assertSame(0, Devolucion::count());
        // Seis integrantes reservados, tres liberados.
        $this->assertSame(9, $this->excursion->fresh()->obtenerCupoDisponible());
    }

    // Regla 6: si una cancelación falla, se anota y se sigue con las demás.
    public function test_una_reserva_que_falla_no_frena_a_las_demas(): void
    {
        Mail::fake();
        $primera = $this->crearReserva('000125-9');
        $queFalla = $this->crearReserva('000126-0');
        $ultima = $this->crearReserva('000127-2');
        Reserva::saving(function (Reserva $reserva) {
            if ($reserva->numero_reserva === '000126-0') {
                throw new RuntimeException('La base no responde.');
            }
        });
        Log::spy();
        $this->travelTo(self::VENCIDA);

        $this->assertSame(2, Reserva::verificarVencimientosDePago(now()));

        $this->assertSame(EstadoReserva::Cancelada, $primera->fresh()->estado);
        $this->assertSame(EstadoReserva::Confirmada, $queFalla->fresh()->estado);
        $this->assertSame(EstadoReserva::Cancelada, $ultima->fresh()->estado);
        Mail::assertQueued(NotificacionCliente::class, 2);
        Log::shouldHaveReceived('error')->once()->withArgs(fn ($mensaje) => str_contains($mensaje, '000126-0')
            && str_contains($mensaje, 'La base no responde.'));
    }

    /* ------------------------ Idempotencia y concurrencia --------------------- */

    public function test_si_la_verificacion_corre_dos_veces_cancela_y_notifica_una_sola_vez(): void
    {
        Mail::fake();
        $reserva = $this->crearReserva('000125-9');
        $this->travelTo(self::VENCIDA);

        $this->assertSame(1, Reserva::verificarVencimientosDePago(now()));
        $this->assertSame(0, Reserva::verificarVencimientosDePago(now()));

        $this->assertSame(EstadoReserva::Cancelada, $reserva->fresh()->estado);
        Mail::assertQueued(NotificacionCliente::class, 1);
    }

    // La reserva se leyó vencida, pero antes de cancelarla el cliente pagó el saldo (CU-16) u otra verificación que
    // corría a la vez ya la canceló: con la fila bloqueada se lee como está y se saltea, sin error.
    #[DataProvider('cambiosAntesDeCancelar')]
    public function test_si_la_reserva_cambio_despues_de_buscarla_no_la_cancela(array $cambio, EstadoReserva $estadoFinal, EstadoSaldo $saldoFinal): void
    {
        $this->crearReserva('000125-9');
        $this->travelTo(self::VENCIDA);
        $leida = Reserva::buscarSaldosVencidos(now())->sole();

        Reserva::whereKey($leida->id_reserva)->update($cambio);

        $this->assertFalse($leida->ejecutarCancelacionSinDevolucion(now()));
        $this->assertSame($estadoFinal, $leida->fresh()->estado);
        $this->assertSame($saldoFinal, $leida->fresh()->estado_saldo);
    }

    public static function cambiosAntesDeCancelar(): array
    {
        return [
            'pagó el saldo' => [['estado_saldo' => EstadoSaldo::Abonado], EstadoReserva::Confirmada, EstadoSaldo::Abonado],
            'ya la canceló otra verificación' => [['estado' => EstadoReserva::Cancelada], EstadoReserva::Cancelada, EstadoSaldo::Adeudado],
        ];
    }

    /* ---------------------------- Notificación (A8) --------------------------- */

    public function test_si_el_correo_no_se_puede_encolar_la_reserva_queda_cancelada_y_se_anota(): void
    {
        $reserva = $this->crearReserva('000125-9');
        config(['queue.default' => 'no-configurada']);
        Log::spy();
        $this->travelTo(self::VENCIDA);

        $this->assertSame(1, Reserva::verificarVencimientosDePago(now()));

        $this->assertSame(EstadoReserva::Cancelada, $reserva->fresh()->estado);
        Log::shouldHaveReceived('error')->once()->withArgs(fn ($mensaje) => str_contains($mensaje, TipoNotificacion::CancelacionFaltaPago->value)
            && str_contains($mensaje, '000125-9'));
    }

    /* ----------------------------- Temporizador ------------------------------- */

    public function test_el_comando_informa_cuantas_reservas_cancelo(): void
    {
        Mail::fake();
        $reserva = $this->crearReserva('000125-9');
        $this->travelTo(self::VENCIDA);

        $this->artisan('reservas:cancelar-por-falta-de-pago')
            ->expectsOutput('Se cancelaron 1 reserva por falta de pago del saldo.')
            ->assertSuccessful();

        $this->assertSame(EstadoReserva::Cancelada, $reserva->fresh()->estado);
    }

    public function test_el_comando_sin_reservas_vencidas_lo_informa(): void
    {
        Mail::fake();
        $this->crearReserva('000125-9');
        $this->travelTo('2026-11-28 12:00:00');

        $this->artisan('reservas:cancelar-por-falta-de-pago')
            ->expectsOutput('No hay reservas con el saldo vencido.')
            ->assertSuccessful();

        Mail::assertNothingQueued();
    }

    // Cada hora, a los 5 minutos: el plazo de 72 horas de una reserva que quedó «Confirmada» el último día puede vencer a
    // cualquier hora.
    public function test_el_comando_esta_en_el_schedule_cada_hora_sin_superponerse(): void
    {
        $eventos = collect(app(Schedule::class)->events())
            ->filter(fn (Event $evento) => str_contains($evento->command ?? '', 'reservas:cancelar-por-falta-de-pago'));

        $this->assertCount(1, $eventos);
        $this->assertSame('5 * * * *', $eventos->first()->expression);
        $this->assertTrue($eventos->first()->withoutOverlapping);
    }

    /* -------------------------------- Datos ----------------------------------- */

    private function crearExcursion(string $fechaSalida): Excursion
    {
        $recorrido = Recorrido::create(['nombre' => 'Camino Inca de 4 días', 'duracion_dias' => 4, 'cantidad_campings' => 3]);

        $paquete = Paquete::create([
            'id_recorrido' => $recorrido->id_recorrido,
            'nombre' => 'Camino Inca Clásico',
            'precio_base' => 1500,
            'costo_noche_extra_cusco' => 60,
            'costo_equipo_camping' => 45,
            'cantidad_porteadores' => 6,
            'estado' => EstadoPaquete::Activo,
            'fecha_creacion' => '2026-01-05',
        ]);

        $usuario = Usuario::create(['correo' => 'guia@caminodelinca.test', 'password' => 'guia1234', 'rol' => Rol::Guia]);
        $guia = Guia::create(['id_usuario' => $usuario->id_usuario, 'nombre' => 'Rosa', 'apellido' => 'Quispe']);

        return Excursion::create([
            'id_paquete' => $paquete->id_paquete,
            'id_guia' => $guia->id_usuario,
            'fecha_salida' => $fechaSalida,
            'cupo' => 12,
            'plazas_retenidas' => 0,
        ]);
    }

    // Una reserva de Federico Ríos con un integrante y la seña de USD 750 pagada. Por defecto, «Confirmada», con el saldo
    // «Adeudado» y la fecha límite del saldo del 28/11/2026.
    private function crearReserva(
        string $numeroReserva,
        EstadoReserva $estado = EstadoReserva::Confirmada,
        EstadoSaldo $estadoSaldo = EstadoSaldo::Adeudado,
        ?string $fechaLimiteSaldo = self::FECHA_LIMITE_SALDO,
    ): Reserva {
        $reserva = Reserva::create([
            'id_excursion' => $this->excursion->id_excursion,
            'numero_reserva' => $numeroReserva,
            'correo_electronico' => 'federico.rios@mail.com',
            'fecha_reserva' => '2026-09-20 18:20:00',
            'estado' => $estado,
            'estado_saldo' => $estadoSaldo,
            'noches_extra_antes' => 0,
            'noches_extra_despues' => 0,
            'fecha_limite_saldo' => $fechaLimiteSaldo,
            'fecha_limite_confirmacion' => self::FECHA_LIMITE_SALDO,
        ]);

        $reserva->excursionistas()->create([
            'nombre' => 'Federico',
            'apellido' => 'Ríos',
            'documento_pasaporte' => 'AAH'.str_replace('-', '', $numeroReserva),
            'equipo_camping' => false,
            'estado_permiso' => EstadoPermiso::Obtenido,
        ]);

        Pago::registrarPago($reserva, 750, '2026-09-20 18:20:00', 'Tarjeta de crédito', TipoPago::Sena);

        return $reserva;
    }
}
