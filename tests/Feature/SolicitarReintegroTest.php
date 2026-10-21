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
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

// Solicitar Reintegro (CU-17). La reserva 000125-9 está «Sin Permiso» y tiene pagada la seña de USD 750: se devuelve
// todo lo abonado, sin penalidad. Su fecha límite de confirmación es el 01/01/2027 a las 23:59:59: hasta ese segundo se
// puede pedir el reintegro.
class SolicitarReintegroTest extends TestCase
{
    use RefreshDatabase;

    private const AVISO_PLAZO_VENCIDO = 'El plazo para elegir entre el reintegro y la reprogramación venció el 01/01/2027: la reserva ya no admite reintegro.';

    private Excursion $excursion;

    private Reserva $reserva;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-13 10:00:00'));

        Mail::fake();

        $this->excursion = $this->crearExcursion('2027-02-01');
        $this->reserva = $this->crearReserva('000125-9', EstadoReserva::SinPermiso);
    }

    public function test_sin_acceso_en_la_sesion_vuelve_a_mi_reserva(): void
    {
        $this->get('/mi-reserva/000125-9/reintegro')->assertRedirect('/mi-reserva');
    }

    public function test_la_pantalla_lleva_del_resumen_al_reintegro(): void
    {
        $this->darAcceso();

        $this->abrirPantalla()
            ->assertSet('paso', 2)
            ->assertSee('Te devolvemos todo lo abonado.')
            ->assertSee('Confirmar reintegro')
            ->assertSee('USD 750,00')
            ->call('confirmar')
            ->assertSet('paso', 3)
            ->assertSee('Reserva cancelada con reintegro del 100 %')
            ->assertSee('Registramos el reintegro de USD 750,00.')
            ->assertDontSee('Te devolvemos todo lo abonado.');

        $reserva = $this->reserva->fresh();
        $this->assertSame(EstadoReserva::Cancelada, $reserva->estado);
        $this->assertSame(MotivoDevolucion::Reintegro, $reserva->devolucion->motivo);
        $this->assertSame('750.00', $reserva->devolucion->monto);
    }

    public function test_con_una_reserva_que_no_esta_sin_permiso_muestra_el_aviso(): void
    {
        $this->reserva->update(['estado' => EstadoReserva::Confirmada]);
        $this->darAcceso();

        $this->abrirPantalla()
            ->assertSet('paso', 1)
            ->assertSee('No se puede solicitar el reintegro')
            ->assertSee('Volver a mi reserva')
            ->assertDontSee('Confirmar reintegro')
            ->assertDontSee('Te devolvemos todo lo abonado.');
    }

    public function test_si_la_reserva_cambia_de_estado_antes_de_confirmar_no_se_cancela(): void
    {
        $this->darAcceso();
        $pantalla = $this->abrirPantalla();

        $this->reserva->update(['estado' => EstadoReserva::Confirmada]);

        $pantalla->call('confirmar')
            ->assertSet('paso', 1)
            ->assertSee('No se puede solicitar el reintegro');

        $this->assertSame(EstadoReserva::Confirmada, $this->reserva->fresh()->estado);
        $this->assertNull($this->reserva->fresh()->devolucion);
        Mail::assertNothingQueued();
    }

    public function test_si_la_confirmacion_llega_dos_veces_registra_una_sola_devolucion(): void
    {
        $this->darAcceso();

        $this->abrirPantalla()
            ->call('confirmar')
            ->call('confirmar')
            ->assertDontSee('SQLSTATE');

        $this->assertSame(1, Devolucion::count());
        Mail::assertQueued(NotificacionCliente::class, 1); // para comprobar que la segunda confirmación no manda un correo duplicado
    }

    public function test_el_navegador_no_puede_cambiar_la_reserva(): void
    {
        $this->crearReserva('000122-3', EstadoReserva::Confirmada);
        $this->darAcceso();

        $this->expectException(CannotUpdateLockedPropertyException::class);

        $this->abrirPantalla()->set('numeroReserva', '000122-3');
    }

    public function test_al_confirmar_se_encola_el_correo_de_falta_de_permisos_pedido_por_el_cliente(): void
    {
        $this->darAcceso();

        $this->abrirPantalla()->call('confirmar')->assertSet('paso', 3);

        Mail::assertQueued(NotificacionCliente::class, 1);
        Mail::assertQueued(NotificacionCliente::class, fn ($mail) => $mail->hasTo('federico.rios@mail.com')
            && $mail->tipo === TipoNotificacion::CancelacionFaltaPermisos
            && $mail->datos['cancelada_por_vencimiento'] === false
            && $mail->datos['devolucion'] !== null);
    }

    public function test_el_asunto_del_correo_informa_el_numero_de_reserva(): void
    {
        $this->darAcceso();

        $this->abrirPantalla()->call('confirmar');

        Mail::assertQueued(NotificacionCliente::class, fn ($mail) => $mail->envelope()->subject === 'Tu reserva 000125-9 fue cancelada por falta de permisos');
    }

    public function test_si_no_se_puede_encolar_el_correo_el_reintegro_igual_queda_registrado(): void
    {
        Log::spy();
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('Cola caída'));
        $this->darAcceso();

        $this->abrirPantalla()
            ->call('confirmar')
            ->assertSet('paso', 3);

        $reserva = $this->reserva->fresh();
        $this->assertSame(EstadoReserva::Cancelada, $reserva->estado);
        $this->assertSame(MotivoDevolucion::Reintegro, $reserva->devolucion->motivo);
        Log::shouldHaveReceived('error')->once();
    }

    /* ---------------------- Plazo: fecha límite de confirmación ---------------------- */

    public function test_dentro_del_plazo_se_ofrece_el_reintegro_y_se_puede_confirmar(): void
    {
        $reserva = $this->reserva->fresh();

        $this->assertTrue($reserva->admiteReintegro());
        $this->assertContains(Reserva::OPCION_REINTEGRO, $reserva->getOpcionesHabilitadas());

        $this->assertSame(750.0, $reserva->confirmarReintegro(now()));

        $this->assertSame(EstadoReserva::Cancelada, $reserva->estado);
        $this->assertSame(MotivoDevolucion::Reintegro, $reserva->devolucion->motivo);
        $this->assertSame('750.00', $reserva->devolucion->monto);
    }

    // El borde es el de verificarFechaLimiteConfirmacion(), el mismo que usan CU-04 para validar, CU-19 para reprogramar y
    // CU-26 para cancelar: a las 23:59:59 del día límite todavía se puede elegir cualquiera de las dos opciones.
    public function test_a_las_23_59_59_del_dia_limite_todavia_se_puede_pedir_el_reintegro(): void
    {
        $this->travelTo(Carbon::parse('2027-01-01 23:59:59'));
        $reserva = $this->reserva->fresh();

        $this->assertTrue($reserva->verificarFechaLimiteConfirmacion());
        $this->assertTrue($reserva->admiteReintegro());
        $this->assertTrue($reserva->admiteReprogramacion());
        $this->assertSame([Reserva::OPCION_REINTEGRO, Reserva::OPCION_REPROGRAMAR], $reserva->getOpcionesHabilitadas());

        $this->assertSame(750.0, $reserva->confirmarReintegro(now()));

        $this->assertSame(EstadoReserva::Cancelada, $this->reserva->fresh()->estado);
        $this->assertSame('750.00', $this->reserva->fresh()->devolucion->monto);
    }

    // Un segundo después, a las 00:00:00 del día siguiente, el plazo ya venció para las dos opciones: es cuando CU-26 la
    // cancela.
    public function test_a_las_00_00_00_del_dia_siguiente_ya_no_se_puede_pedir_el_reintegro(): void
    {
        $this->travelTo(Carbon::parse('2027-01-02 00:00:00'));
        $reserva = $this->reserva->fresh();

        $this->assertFalse($reserva->verificarFechaLimiteConfirmacion());
        $this->assertFalse($reserva->admiteReintegro());
        $this->assertFalse($reserva->admiteReprogramacion());
        $this->assertSame([], $reserva->getOpcionesHabilitadas());

        try {
            $reserva->calcularReintegro();
            $this->fail('Se calculó el reintegro con el plazo vencido.');
        } catch (DomainException $excepcion) {
            $this->assertSame(self::AVISO_PLAZO_VENCIDO, $excepcion->getMessage());
        }

        try {
            $reserva->confirmarReintegro(now());
            $this->fail('Se confirmó el reintegro con el plazo vencido.');
        } catch (DomainException $excepcion) {
            $this->assertSame(self::AVISO_PLAZO_VENCIDO, $excepcion->getMessage());
        }

        $this->assertSinCancelar();
    }

    // Una reserva que además dejó de estar «Sin Permiso» avisa por el estado (A1), como antes.
    public function test_con_el_plazo_vencido_y_otro_estado_avisa_por_el_estado(): void
    {
        $this->travelTo(Carbon::parse('2027-01-10 10:00:00'));
        $this->reserva->update(['estado' => EstadoReserva::Cancelada]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('La reserva se encuentra en estado «Cancelada» y no admite reintegro.');

        $this->reserva->fresh()->calcularReintegro();
    }

    // El cliente vio el monto a las 23:50 del día límite y confirmó pasada la medianoche.
    public function test_si_el_plazo_vence_entre_ver_el_monto_y_confirmar_no_se_cancela(): void
    {
        $this->travelTo(Carbon::parse('2027-01-01 23:50:00'));
        $reserva = $this->reserva->fresh();
        $this->assertSame(750.0, $reserva->calcularReintegro());

        $this->travelTo(Carbon::parse('2027-01-02 00:00:00'));

        try {
            $reserva->confirmarReintegro(now());
            $this->fail('Se confirmó el reintegro con el plazo vencido.');
        } catch (DomainException $excepcion) {
            $this->assertSame(self::AVISO_PLAZO_VENCIDO, $excepcion->getMessage());
        }

        $this->assertSinCancelar();
    }

    // Plazo vencido: el mismo aviso de error que A1, con el motivo.
    public function test_con_el_plazo_vencido_la_pantalla_muestra_el_aviso(): void
    {
        $this->travelTo(Carbon::parse('2027-01-02 00:00:00'));
        $this->darAcceso();

        $this->abrirPantalla()
            ->assertSet('paso', 1)
            ->assertSee('No se puede solicitar el reintegro')
            ->assertSee(self::AVISO_PLAZO_VENCIDO)
            ->assertSee('Volver a mi reserva')
            ->assertDontSee('Confirmar reintegro');
    }

    public function test_si_el_plazo_vence_antes_de_confirmar_la_pantalla_lo_avisa_y_no_cancela(): void
    {
        $this->travelTo(Carbon::parse('2027-01-01 23:50:00'));
        $this->darAcceso();

        $pantalla = $this->abrirPantalla()
            ->assertSet('paso', 2)
            ->assertSee('Confirmar reintegro');

        $this->travelTo(Carbon::parse('2027-01-02 00:00:00'));

        $pantalla->call('confirmar')
            ->assertSet('paso', 1)
            ->assertSee('No se puede solicitar el reintegro')
            ->assertSee(self::AVISO_PLAZO_VENCIDO)
            ->assertDontSee('Reserva cancelada con reintegro del 100 %');

        $this->assertSinCancelar();
        Mail::assertNothingQueued();
    }

    public function test_mi_reserva_ofrece_el_reintegro_hasta_las_23_59_59_del_dia_limite(): void
    {
        $this->darAcceso();

        $this->travelTo(Carbon::parse('2027-01-01 23:59:59'));
        Livewire::test('consultar-reserva')
            ->assertSeeHtml('href="/mi-reserva/000125-9/reintegro"');

        $this->travelTo(Carbon::parse('2027-01-02 00:00:00'));
        Livewire::test('consultar-reserva')
            ->assertSee('Reserva 000125-9')
            ->assertDontSeeHtml('/mi-reserva/000125-9/reintegro')
            ->assertSee('El plazo para elegir entre el reintegro y la reprogramación venció el 01/01/2027.');
    }

    // Ni estado ni devolución: la reserva sigue esperando a CU-26.
    private function assertSinCancelar(): void
    {
        $reserva = $this->reserva->fresh();
        $this->assertSame(EstadoReserva::SinPermiso, $reserva->estado);
        $this->assertNull($reserva->devolucion);
    }

    private function abrirPantalla()
    {
        return Livewire::test('solicitar-reintegro', ['numeroReserva' => '000125-9']);
    }

    // Como queda la sesión cuando el cliente consulta su reserva en Mi reserva.
    private function darAcceso(): void
    {
        session(['acceso_reserva' => ['correo' => 'federico.rios@mail.com', 'numeroReserva' => '000125-9']]);
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

    // Una reserva de Federico Ríos, con un integrante y la seña de USD 750 pagada.
    private function crearReserva(string $numeroReserva, EstadoReserva $estado): Reserva
    {
        $reserva = Reserva::create([
            'id_excursion' => $this->excursion->id_excursion,
            'numero_reserva' => $numeroReserva,
            'correo_electronico' => 'federico.rios@mail.com',
            'fecha_reserva' => '2026-10-01 18:20:00',
            'estado' => $estado,
            'estado_saldo' => EstadoSaldo::Adeudado,
            'noches_extra_antes' => 0,
            'noches_extra_despues' => 0,
            'fecha_limite_saldo' => '2027-01-01 23:59:59',
            'fecha_limite_confirmacion' => '2027-01-01 23:59:59',
        ]);

        $reserva->excursionistas()->create([
            'nombre' => 'Federico',
            'apellido' => 'Ríos',
            'documento_pasaporte' => 'AAH'.str_replace('-', '', $numeroReserva),
            'equipo_camping' => false,
            'estado_permiso' => $estado === EstadoReserva::SinPermiso ? EstadoPermiso::NoObtenido : EstadoPermiso::Obtenido,
        ]);

        Pago::registrarPago($reserva, 750, '2026-10-01 18:20:00', 'Tarjeta de crédito', TipoPago::Sena);

        return $reserva;
    }
}
