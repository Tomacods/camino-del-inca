<?php

namespace Tests\Feature;

use App\Enums\EstadoPaquete;
use App\Enums\EstadoPermiso;
use App\Enums\EstadoReserva;
use App\Enums\EstadoSaldo;
use App\Enums\MotivoDevolucion;
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
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\ServicioCorreoQueFalla;
use Tests\TestCase;

// Cancelar Reserva por Falta de Confirmación de Permisos (CU-26). La salida es el 01/02/2027, así que la fecha límite de
// confirmación es el 01/01/2027 a las 23:59:59. Cada reserva tiene pagada la seña de USD 750.
class CancelarReservaPorFaltaDeConfirmacionTest extends TestCase
{
    use RefreshDatabase;

    private const FECHA_LIMITE = '2027-01-01 23:59:59';

    // La primera corrida del Temporizador después del vencimiento.
    private const DESPUES_DEL_VENCIMIENTO = '2027-01-02 00:05:00';

    private Excursion $excursion;

    // Mail::fake() va en cada prueba y no acá: la del servicio de correo que falla necesita el envío de verdad.
    protected function setUp(): void
    {
        parent::setUp();

        $this->excursion = $this->crearExcursion('2027-02-01');
    }

    public function test_una_reserva_pendiente_vencida_se_cancela_con_reintegro_del_100_y_se_notifica(): void
    {
        Mail::fake();
        $reserva = $this->crearReserva('000124-7', EstadoReserva::Pendiente);
        $this->travelTo(Carbon::parse(self::DESPUES_DEL_VENCIMIENTO));

        $this->assertSame(1, Reserva::verificarVencimientosDePermisos(now()));

        $reserva = $reserva->fresh();
        $this->assertSame(EstadoReserva::Cancelada, $reserva->estado);
        $this->assertSame(MotivoDevolucion::Reintegro, $reserva->devolucion->motivo);
        $this->assertSame('750.00', $reserva->devolucion->monto);
        $this->assertSame('2027-01-02', $reserva->devolucion->fecha->toDateString());
        $this->assertCorreoDeVencimiento('000124-7', 750);
    }

    // Con la seña y el saldo pagados: el reintegro es la suma de todos los pagos.
    public function test_una_reserva_sin_permiso_vencida_se_cancela_con_reintegro_de_todo_lo_abonado(): void
    {
        Mail::fake();
        $reserva = $this->crearReserva('000123-5', EstadoReserva::SinPermiso);
        Pago::registrarPago($reserva, 900, '2026-12-20 11:00:00', 'Tarjeta de crédito', TipoPago::Saldo);
        $this->travelTo(Carbon::parse(self::DESPUES_DEL_VENCIMIENTO));

        $this->assertSame(1, Reserva::verificarVencimientosDePermisos(now()));

        $reserva = $reserva->fresh();
        $this->assertSame(EstadoReserva::Cancelada, $reserva->estado);
        $this->assertSame(MotivoDevolucion::Reintegro, $reserva->devolucion->motivo);
        $this->assertSame('1650.00', $reserva->devolucion->monto);
        $this->assertCorreoDeVencimiento('000123-5', 1650);
    }

    // La fecha límite es inclusiva, como en CU-04: hasta las 23:59:59 todavía se pueden validar los permisos.
    public function test_a_las_23_59_59_del_dia_limite_no_se_cancela_y_a_las_00_00_00_del_siguiente_si(): void
    {
        Mail::fake();
        $reserva = $this->crearReserva('000124-7', EstadoReserva::Pendiente);

        $this->travelTo(Carbon::parse(self::FECHA_LIMITE));
        $this->assertSame(0, Reserva::verificarVencimientosDePermisos(now()));
        $this->assertSame(EstadoReserva::Pendiente, $reserva->fresh()->estado);
        Mail::assertNothingQueued();

        $this->travelTo(Carbon::parse('2027-01-02 00:00:00'));
        $this->assertSame(1, Reserva::verificarVencimientosDePermisos(now()));
        $this->assertSame(EstadoReserva::Cancelada, $reserva->fresh()->estado);
    }

    public function test_las_reservas_confirmadas_canceladas_o_finalizadas_no_se_tocan(): void
    {
        Mail::fake();
        $confirmada = $this->crearReserva('000122-3', EstadoReserva::Confirmada);
        $cancelada = $this->crearReserva('000125-9', EstadoReserva::Cancelada);
        $finalizada = $this->crearReserva('000126-0', EstadoReserva::Finalizada);
        $this->travelTo(Carbon::parse(self::DESPUES_DEL_VENCIMIENTO));

        $this->assertSame(0, Reserva::verificarVencimientosDePermisos(now()));

        $this->assertSame(EstadoReserva::Confirmada, $confirmada->fresh()->estado);
        $this->assertSame(EstadoReserva::Cancelada, $cancelada->fresh()->estado);
        $this->assertSame(EstadoReserva::Finalizada, $finalizada->fresh()->estado);
        $this->assertSame(0, Devolucion::count());
        Mail::assertNothingQueued();
    }

    // A1.
    public function test_sin_reservas_vencidas_no_cambia_nada_y_no_sale_ningun_correo(): void
    {
        Mail::fake();
        $pendiente = $this->crearReserva('000124-7', EstadoReserva::Pendiente);
        $sinPermiso = $this->crearReserva('000123-5', EstadoReserva::SinPermiso);
        $this->travelTo(Carbon::parse('2026-12-15 10:00:00'));

        $this->assertSame(0, Reserva::verificarVencimientosDePermisos(now()));

        $this->assertSame(EstadoReserva::Pendiente, $pendiente->fresh()->estado);
        $this->assertSame(EstadoReserva::SinPermiso, $sinPermiso->fresh()->estado);
        $this->assertSame(0, Devolucion::count());
        Mail::assertNothingQueued();
    }

    public function test_las_plazas_de_la_reserva_cancelada_vuelven_al_cupo_disponible(): void
    {
        Mail::fake();
        $this->crearReserva('000124-7', EstadoReserva::Pendiente, 3);
        $this->assertSame(9, $this->excursion->obtenerCupoDisponible());
        $this->travelTo(Carbon::parse(self::DESPUES_DEL_VENCIMIENTO));

        Reserva::verificarVencimientosDePermisos(now());

        $this->assertSame(12, $this->excursion->fresh()->obtenerCupoDisponible());
    }

    public function test_correr_la_verificacion_dos_veces_deja_una_sola_devolucion_y_un_solo_correo(): void
    {
        Mail::fake();
        $this->crearReserva('000124-7', EstadoReserva::Pendiente);
        $this->travelTo(Carbon::parse(self::DESPUES_DEL_VENCIMIENTO));

        $this->assertSame(1, Reserva::verificarVencimientosDePermisos(now()));
        $this->assertSame(0, Reserva::verificarVencimientosDePermisos(now()));

        $this->assertSame(1, Devolucion::count());
        Mail::assertQueued(NotificacionCliente::class, 1);
    }

    // Si la reserva cambió después de buscarla (el cliente pidió el reintegro, por ejemplo), la cancelación la vuelve a
    // leer y no hace nada.
    public function test_si_la_reserva_ya_no_corresponde_al_cancelarla_no_hace_nada(): void
    {
        Mail::fake();
        $reserva = $this->crearReserva('000123-5', EstadoReserva::SinPermiso);
        $this->travelTo(Carbon::parse(self::DESPUES_DEL_VENCIMIENTO));
        $encontrada = Reserva::buscarConfirmacionesVencidas(now())->sole();

        $reserva->update(['estado' => EstadoReserva::Cancelada]);

        $this->assertFalse($encontrada->ejecutarCancelacionPorVencimiento(now()));
        $this->assertSame(0, Devolucion::count());
    }

    // A8 de CU-13: el correo falla y la cancelación queda igual; la verificación sigue con la reserva siguiente. En las
    // pruebas la cola es «sync»: el envío se hace en el momento y la falla llega hasta notificarCliente(), que la anota.
    public function test_si_el_correo_falla_la_reserva_queda_cancelada_y_se_procesan_las_demas(): void
    {
        ServicioCorreoQueFalla::instalar();
        Log::spy();
        $primera = $this->crearReserva('000123-5', EstadoReserva::SinPermiso);
        $segunda = $this->crearReserva('000124-7', EstadoReserva::Pendiente);
        $this->travelTo(Carbon::parse(self::DESPUES_DEL_VENCIMIENTO));

        $this->assertSame(2, Reserva::verificarVencimientosDePermisos(now()));

        $this->assertSame(EstadoReserva::Cancelada, $primera->fresh()->estado);
        $this->assertSame(EstadoReserva::Cancelada, $segunda->fresh()->estado);
        $this->assertSame(2, Devolucion::count());
        Log::shouldHaveReceived('error')->twice();
    }

    // Una reserva que no se puede cancelar se anota en el registro con su número y no frena a las demás. Acá falla porque
    // ya tiene una devolución, y la base admite una sola por reserva.
    public function test_si_una_reserva_no_se_puede_cancelar_se_anota_y_se_sigue_con_las_demas(): void
    {
        Mail::fake();
        Log::spy();
        $conDevolucion = $this->crearReserva('000123-5', EstadoReserva::SinPermiso);
        $conDevolucion->devolucion()->create(['fecha' => '2026-12-20', 'monto' => 750, 'motivo' => MotivoDevolucion::Reintegro]);
        $otra = $this->crearReserva('000124-7', EstadoReserva::Pendiente);
        $this->travelTo(Carbon::parse(self::DESPUES_DEL_VENCIMIENTO));

        $this->assertSame(1, Reserva::verificarVencimientosDePermisos(now()));

        $this->assertSame(EstadoReserva::SinPermiso, $conDevolucion->fresh()->estado);
        $this->assertSame(EstadoReserva::Cancelada, $otra->fresh()->estado);
        Log::shouldHaveReceived('error')->once()->withArgs(fn (string $mensaje) => str_contains($mensaje, '000123-5'));
        Mail::assertQueued(NotificacionCliente::class, 1);
        Mail::assertQueued(NotificacionCliente::class, fn ($mail) => $mail->hasTo('titular.000124-7@mail.com'));
    }

    public function test_el_comando_cancela_las_reservas_vencidas(): void
    {
        Mail::fake();
        $reserva = $this->crearReserva('000124-7', EstadoReserva::Pendiente);
        $this->travelTo(Carbon::parse(self::DESPUES_DEL_VENCIMIENTO));

        $this->artisan('reservas:cancelar-por-falta-de-confirmacion')
            ->expectsOutput('Se canceló 1 reserva por falta de confirmación de permisos.')
            ->assertSuccessful();

        $this->assertSame(EstadoReserva::Cancelada, $reserva->fresh()->estado);

        $this->artisan('reservas:cancelar-por-falta-de-confirmacion')
            ->expectsOutput('No hay reservas con la fecha límite de confirmación vencida.')
            ->assertSuccessful();
    }

    public function test_el_comando_esta_en_el_schedule_una_vez_por_dia_sin_superponerse(): void
    {
        Mail::fake();
        $evento = collect(app(Schedule::class)->events())
            ->first(fn ($evento) => str_contains($evento->command, 'reservas:cancelar-por-falta-de-confirmacion'));

        $this->assertNotNull($evento);
        $this->assertSame('5 0 * * *', $evento->expression);
        $this->assertTrue($evento->withoutOverlapping);
    }

    // El correo es el mismo para una reserva «Pendiente» y para una «Sin Permiso»: el texto lo prueba NotificarClienteTest.
    private function assertCorreoDeVencimiento(string $numeroReserva, float $montoReintegro): void
    {
        Mail::assertQueued(NotificacionCliente::class, 1);
        Mail::assertQueued(NotificacionCliente::class, fn ($mail) => $mail->hasTo('titular.'.$numeroReserva.'@mail.com')
            && $mail->tipo === TipoNotificacion::CancelacionFaltaPermisos
            && $mail->datos['reserva']['numero_reserva'] === $numeroReserva
            && $mail->datos['cancelada_por_vencimiento'] === true
            && $mail->datos['devolucion']['motivo'] === MotivoDevolucion::Reintegro
            && $mail->datos['devolucion']['monto'] === $montoReintegro);
    }

    private function crearExcursion(string $fechaSalida): Excursion
    {
        $recorrido = Recorrido::create(['nombre' => 'Camino Inca de 4 días', 'duracion_dias' => 4, 'cantidad_campings' => 3]);

        $paquete = Paquete::create([
            'id_recorrido' => $recorrido->id_recorrido,
            'nombre' => 'Camino Inca Clásico',
            'precio_base' => 750,
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

    // Una reserva de la excursión, con la seña de USD 750 pagada. Los permisos quedan como corresponden al estado.
    private function crearReserva(string $numeroReserva, EstadoReserva $estado, int $cantidadIntegrantes = 1): Reserva
    {
        $reserva = Reserva::create([
            'id_excursion' => $this->excursion->id_excursion,
            'numero_reserva' => $numeroReserva,
            'correo_electronico' => 'titular.'.$numeroReserva.'@mail.com',
            'fecha_reserva' => '2026-10-01 18:20:00',
            'estado' => $estado,
            'estado_saldo' => EstadoSaldo::Adeudado,
            'noches_extra_antes' => 0,
            'noches_extra_despues' => 0,
            'fecha_limite_saldo' => self::FECHA_LIMITE,
            'fecha_limite_confirmacion' => self::FECHA_LIMITE,
        ]);

        $estadoPermiso = match ($estado) {
            EstadoReserva::Pendiente => EstadoPermiso::Pendiente,
            EstadoReserva::SinPermiso => EstadoPermiso::NoObtenido,
            default => EstadoPermiso::Obtenido,
        };

        for ($integrante = 1; $integrante <= $cantidadIntegrantes; $integrante++) {
            $reserva->excursionistas()->create([
                'nombre' => 'Integrante '.$integrante,
                'apellido' => 'Ríos',
                'documento_pasaporte' => 'AAH'.str_replace('-', '', $numeroReserva).$integrante,
                'equipo_camping' => false,
                'estado_permiso' => $estadoPermiso,
            ]);
        }

        Pago::registrarPago($reserva, 750, '2026-10-01 18:20:00', 'Tarjeta de crédito', TipoPago::Sena);

        return $reserva;
    }
}
