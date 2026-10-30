<?php

namespace Tests\Feature;

use App\Enums\EstadoPaquete;
use App\Enums\EstadoPermiso;
use App\Enums\EstadoReserva;
use App\Enums\EstadoSaldo;
use App\Enums\MotivoDevolucion;
use App\Enums\Rol;
use App\Enums\TipoPago;
use App\Models\Etapa;
use App\Models\Excursion;
use App\Models\Guia;
use App\Models\Pago;
use App\Models\Paquete;
use App\Models\Recorrido;
use App\Models\Reserva;
use App\Models\Usuario;
use App\Models\Valoracion;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class EstadosReservaTest extends TestCase
{
    use RefreshDatabase;

    private Excursion $origen;

    private Excursion $destinoReprogramacion;

    private Excursion $destinoModificacion;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-06 10:00:00');
        Mail::fake();

        $recorrido = Recorrido::create(['nombre' => 'Camino Inca de 4 días', 'duracion_dias' => 4, 'cantidad_campings' => 3]);

        foreach (['Piscacucho', 'Wayllabamba', 'Pacaymayo', 'Machu Picchu'] as $posicion => $nombre) {
            Etapa::create(['id_recorrido' => $recorrido->id_recorrido, 'nombre' => $nombre, 'orden' => $posicion + 1]);
        }

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
        Guia::create(['id_usuario' => $usuario->id_usuario, 'nombre' => 'Rosa', 'apellido' => 'Quispe']);

        $this->origen = $this->crearExcursion($paquete, $usuario, '2027-01-18');
        $this->destinoReprogramacion = $this->crearExcursion($paquete, $usuario, '2027-02-15');
        $this->destinoModificacion = $this->crearExcursion($paquete, $usuario, '2027-02-22');
    }

    // OO-01: todos los pasos operan sobre la reserva generada al principio.
    public function test_la_reserva_se_reprograma_se_modifica_y_finaliza_para_valorar(): void
    {
        $reserva = $this->generarReserva();
        $idReserva = $reserva->id_reserva;

        $this->validarPermisos($reserva, EstadoPermiso::NoObtenido);
        $this->assertSame(EstadoReserva::SinPermiso, $reserva->estado);

        $this->assertTrue($reserva->iniciarReprogramacion()->contains('id_excursion', $this->destinoReprogramacion->id_excursion));
        $reserva->reprogramar($this->destinoReprogramacion);

        $this->assertSame(EstadoReserva::Pendiente, $reserva->estado);
        $this->assertSame($this->destinoReprogramacion->id_excursion, $reserva->id_excursion);
        $this->assertSame([EstadoPermiso::Pendiente, EstadoPermiso::Pendiente], $reserva->excursionistas->pluck('estado_permiso')->all());
        $this->assertSame('2027-01-15 23:59:59', $reserva->fecha_limite_confirmacion->format('Y-m-d H:i:s'));
        $this->assertSame(12, $this->origen->fresh()->obtenerCupoDisponible());
        $this->assertSame(10, $this->destinoReprogramacion->fresh()->obtenerCupoDisponible());

        $this->validarPermisos($reserva);
        $this->assertSame(EstadoReserva::Confirmada, $reserva->estado);

        $this->assertTrue($reserva->iniciarModificacion()->contains('id_excursion', $this->destinoModificacion->id_excursion));
        $reserva->modificarExcursion($this->destinoModificacion);

        $this->assertSame(EstadoReserva::Pendiente, $reserva->estado);
        $this->assertSame($this->destinoModificacion->id_excursion, $reserva->id_excursion);
        $this->assertSame([EstadoPermiso::Pendiente, EstadoPermiso::Pendiente], $reserva->excursionistas->pluck('estado_permiso')->all());
        $this->assertSame('2027-01-22 23:59:59', $reserva->fecha_limite_saldo->format('Y-m-d H:i:s'));
        $this->assertSame(12, $this->destinoReprogramacion->fresh()->obtenerCupoDisponible());
        $this->assertSame(10, $this->destinoModificacion->fresh()->obtenerCupoDisponible());
        $this->assertSame('750.00', $reserva->pagos()->sole()->monto);

        $this->validarPermisos($reserva);
        $this->assertSame(EstadoReserva::Confirmada, $reserva->estado);
        $this->assertSame(750.0, $reserva->iniciarPagoSaldo()['saldo_pendiente']);

        $pago = Pago::registrarPagoSaldo($reserva, 'Tarjeta de crédito');
        $reserva->refresh();

        $this->assertNotNull($pago);
        $this->assertSame('750.00', $pago->monto);
        $this->assertSame(EstadoReserva::Confirmada, $reserva->estado);
        $this->assertSame(EstadoSaldo::Abonado, $reserva->estado_saldo);
        $this->assertSame(0.0, $reserva->calcularSaldoPendiente());
        $this->assertSame(2, $reserva->pagos()->count());

        $this->travelTo('2027-02-22 08:00:00');
        $excursion = $reserva->excursion;
        $excursion->iniciarRecorrido();
        $this->assertTrue($excursion->recorridoIniciado());

        foreach ($excursion->getEtapas() as $posicion => $etapa) {
            $this->travelTo($excursion->fecha_salida->copy()->addDays($posicion)->setTime(18, 0));
            $finalizadas = $excursion->actualizarEtapaActual($etapa->id_etapa);
            $reserva->refresh();

            $this->assertSame($etapa->id_etapa, $excursion->id_etapa_actual);
            $this->assertSame($posicion === 3 ? EstadoReserva::Finalizada : EstadoReserva::Confirmada, $reserva->estado);
            $this->assertSame($posicion === 3 ? [$idReserva] : [], $finalizadas->pluck('id_reserva')->all());
        }

        $valoracion = Valoracion::valorarServicios($reserva, $this->puntajesValidos($reserva), 5, 'Muy buena experiencia.');
        $reserva->refresh();

        $this->assertSame(EstadoReserva::Finalizada, $reserva->estado);
        $this->assertSame($idReserva, $valoracion->id_reserva);
        $this->assertSame(6, $valoracion->detalles()->count());
        $this->assertDatabaseCount('reserva', 1);
        $this->assertDatabaseCount('valoracion', 1);
        $this->assertDatabaseEmpty('devolucion');
    }

    // OO-02: 60 días antes de la salida y antes del plazo de confirmación.
    public function test_la_reserva_pendiente_se_cancela_voluntariamente_con_reembolso(): void
    {
        $reserva = $this->generarReserva();
        $this->travelTo('2026-11-19 10:00:00');

        $this->assertTrue($reserva->verificarFechaLimiteConfirmacion());
        $this->assertSame(375.0, $reserva->calcularReembolso(now()));

        $reserva->confirmarCancelacion(375, now());

        $this->assertSame(EstadoReserva::Cancelada, $reserva->estado);
        $this->assertSame('375.00', $reserva->devolucion->monto);
        $this->assertSame(MotivoDevolucion::Reembolso, $reserva->devolucion->motivo);
        $this->assertSame(12, $this->origen->fresh()->obtenerCupoDisponible());
        $this->assertSame(1, $reserva->pagos()->count());
    }

    // OO-03: la cancelación voluntaria no se mezcla con el vencimiento del saldo.
    public function test_la_reserva_confirmada_se_cancela_voluntariamente_con_reembolso(): void
    {
        $reserva = $this->generarReserva();
        $this->validarPermisos($reserva);
        $this->assertSame(EstadoReserva::Confirmada, $reserva->estado);
        $this->travelTo('2026-11-19 10:00:00');

        $this->assertTrue($reserva->verificarFechaLimiteSaldo());
        $this->assertSame(375.0, $reserva->calcularReembolso(now()));

        $reserva->confirmarCancelacion(375, now());

        $this->assertSame(EstadoReserva::Cancelada, $reserva->estado);
        $this->assertSame('375.00', $reserva->devolucion->monto);
        $this->assertSame(MotivoDevolucion::Reembolso, $reserva->devolucion->motivo);
        $this->assertSame(12, $this->origen->fresh()->obtenerCupoDisponible());
    }

    // OO-04.
    public function test_la_reserva_sin_permiso_se_cancela_al_solicitar_el_reintegro(): void
    {
        $reserva = $this->generarReserva();
        $this->validarPermisos($reserva, EstadoPermiso::NoObtenido);
        $this->assertSame(EstadoReserva::SinPermiso, $reserva->estado);

        $this->assertSame(750.0, $reserva->confirmarReintegro(now()));

        $this->assertSame(EstadoReserva::Cancelada, $reserva->estado);
        $this->assertSame('750.00', $reserva->devolucion->monto);
        $this->assertSame(MotivoDevolucion::Reintegro, $reserva->devolucion->motivo);
        $this->assertSame(12, $this->origen->fresh()->obtenerCupoDisponible());
    }

    // OO-05.
    public function test_la_reserva_pendiente_se_cancela_al_vencer_la_confirmacion(): void
    {
        $reserva = $this->generarReserva();
        $this->travelTo($reserva->fecha_limite_confirmacion->copy()->addSecond());

        $this->assertSame(1, Reserva::verificarVencimientosDePermisos(now()));
        $reserva->refresh();

        $this->assertSame(EstadoReserva::Cancelada, $reserva->estado);
        $this->assertSame('750.00', $reserva->devolucion->monto);
        $this->assertSame(MotivoDevolucion::Reintegro, $reserva->devolucion->motivo);
        $this->assertSame(12, $this->origen->fresh()->obtenerCupoDisponible());
    }

    // OO-06.
    public function test_la_reserva_sin_permiso_se_cancela_al_vencer_sin_elegir_una_opcion(): void
    {
        $reserva = $this->generarReserva();
        $this->validarPermisos($reserva, EstadoPermiso::NoObtenido);
        $this->assertSame(EstadoReserva::SinPermiso, $reserva->estado);
        $this->travelTo($reserva->fecha_limite_confirmacion->copy()->addSecond());

        $this->assertSame(1, Reserva::verificarVencimientosDePermisos(now()));
        $reserva->refresh();

        $this->assertSame(EstadoReserva::Cancelada, $reserva->estado);
        $this->assertSame('750.00', $reserva->devolucion->monto);
        $this->assertSame(MotivoDevolucion::Reintegro, $reserva->devolucion->motivo);
        $this->assertSame(12, $this->origen->fresh()->obtenerCupoDisponible());
    }

    // OO-07.
    public function test_la_reserva_confirmada_se_cancela_al_vencer_el_saldo_sin_devolucion(): void
    {
        $reserva = $this->generarReserva();
        $this->validarPermisos($reserva);
        $this->assertSame(EstadoReserva::Confirmada, $reserva->estado);
        $this->travelTo($reserva->fecha_limite_saldo->copy()->addSecond());

        $this->assertSame(1, Reserva::verificarVencimientosDePago(now()));
        $reserva->refresh();

        $this->assertSame(EstadoReserva::Cancelada, $reserva->estado);
        $this->assertSame(EstadoSaldo::Adeudado, $reserva->estado_saldo);
        $this->assertDatabaseEmpty('devolucion');
        $this->assertSame('750.00', $reserva->pagos()->sole()->monto);
        $this->assertSame(12, $this->origen->fresh()->obtenerCupoDisponible());
    }

    // OO-08.
    public function test_una_reserva_pendiente_rechaza_la_valoracion_sin_cambiar_de_estado(): void
    {
        $reserva = $this->generarReserva();

        $this->assertThrows(
            fn () => Valoracion::valorarServicios($reserva, $this->puntajesValidos($reserva), 5, null),
            DomainException::class,
            'Sólo se valoran las reservas finalizadas, y una sola vez.',
        );

        $this->assertSame(EstadoReserva::Pendiente, $reserva->fresh()->estado);
        $this->assertDatabaseEmpty('valoracion');
        $this->assertDatabaseEmpty('detalle_valoracion');
    }

    // OO-09.
    public function test_una_reserva_sin_permiso_rechaza_el_pago_de_saldo_sin_cambiar_de_estado(): void
    {
        $reserva = $this->generarReserva();
        $this->validarPermisos($reserva, EstadoPermiso::NoObtenido);

        $this->assertSame(EstadoSaldo::Adeudado, $reserva->estado_saldo);
        $this->assertSame(750.0, $reserva->calcularSaldoPendiente());
        $this->assertTrue($reserva->verificarFechaLimiteSaldo());

        $this->assertThrows(
            fn () => $reserva->iniciarPagoSaldo(),
            DomainException::class,
            'Esta reserva no tiene saldo pendiente.',
        );

        $this->assertSame(EstadoReserva::SinPermiso, $reserva->fresh()->estado);
        $this->assertSame(1, $reserva->pagos()->count());
        $this->assertSame(EstadoSaldo::Adeudado, $reserva->estado_saldo);
    }

    private function crearExcursion(Paquete $paquete, Usuario $guia, string $fechaSalida): Excursion
    {
        return Excursion::create([
            'id_paquete' => $paquete->id_paquete,
            'id_guia' => $guia->id_usuario,
            'fecha_salida' => $fechaSalida,
            'cupo' => 12,
            'plazas_retenidas' => 0,
        ]);
    }

    private function generarReserva(): Reserva
    {
        $this->assertTrue($this->origen->retenerCupo(2));

        // Se invoca CU-15 una vez aprobado el primer pago: todavía no se asigna ningún estado a mano.
        $reserva = Reserva::generarReserva([
            'id_retencion' => (string) Str::uuid(),
            'id_excursion' => $this->origen->id_excursion,
            'correo_electronico' => 'estados.reserva@example.com',
            'noches_extra_antes' => 0,
            'noches_extra_despues' => 0,
            'integrantes' => [
                ['nombre' => 'Ana', 'apellido' => 'Paz', 'documento_pasaporte' => 'EST001', 'equipo_camping' => false],
                ['nombre' => 'Luis', 'apellido' => 'Paz', 'documento_pasaporte' => 'EST002', 'equipo_camping' => false],
            ],
            'vence' => now()->addMinutes(5)->toIso8601String(),
        ], TipoPago::Sena, 'Tarjeta de crédito');

        $this->assertNotNull($reserva);
        $this->assertSame(EstadoReserva::Pendiente, $reserva->estado);
        $this->assertSame(EstadoSaldo::Adeudado, $reserva->estado_saldo);
        $this->assertSame('750.00', $reserva->pagos()->sole()->monto);
        $this->assertSame(10, $this->origen->fresh()->obtenerCupoDisponible());

        return $reserva;
    }

    private function validarPermisos(Reserva $reserva, EstadoPermiso $segundoPermiso = EstadoPermiso::Obtenido): void
    {
        $reserva->iniciarValidacionPermisos();
        $reserva->registrarPermiso('EST001', EstadoPermiso::Obtenido);
        $reserva->registrarPermiso('EST002', $segundoPermiso);
        $reserva->ejecutarValidacionPermisos();
        $reserva->refresh();

        $this->assertSame([EstadoPermiso::Obtenido, $segundoPermiso], $reserva->excursionistas->pluck('estado_permiso')->all());
    }

    private function puntajesValidos(Reserva $reserva): array
    {
        $puntajes = [];

        foreach (Valoracion::getCategoriasAValorar($reserva) as $categoria) {
            $puntajes[$categoria->name] = 5;
        }

        return $puntajes;
    }
}
