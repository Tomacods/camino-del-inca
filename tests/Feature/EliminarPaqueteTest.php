<?php

namespace Tests\Feature;

use App\Enums\EstadoPaquete;
use App\Enums\EstadoReserva;
use App\Enums\EstadoSaldo;
use App\Enums\Rol;
use App\Enums\TipoPago;
use App\Filament\Resources\Paquetes\Pages\ListPaquetes;
use App\Models\Excursion;
use App\Models\Guia;
use App\Models\Paquete;
use App\Models\Recorrido;
use App\Models\Reserva;
use App\Models\Usuario;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

// CU-03 Eliminar Paquete (R-03, baja lógica) y la reactivación de un paquete dado de baja (R-02).
class EliminarPaqueteTest extends TestCase
{
    use RefreshDatabase;

    private Usuario $administrador;

    private Usuario $usuarioGuia;

    private Guia $guia;

    private Recorrido $recorrido;

    private Paquete $paquete;

    private Excursion $excursion;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-21');

        $this->administrador = Usuario::create(['correo' => 'admin@caminodelinca.test', 'password' => 'admin1234', 'rol' => Rol::Administrador]);
        $this->usuarioGuia = Usuario::create(['correo' => 'guia@caminodelinca.test', 'password' => 'guia1234', 'rol' => Rol::Guia]);
        $this->guia = Guia::create(['id_usuario' => $this->usuarioGuia->id_usuario, 'nombre' => 'Rosa', 'apellido' => 'Quispe']);

        $this->recorrido = Recorrido::create(['nombre' => 'Camino Inca de 4 días', 'duracion_dias' => 4, 'cantidad_campings' => 3]);
        $this->paquete = $this->crearPaquete('Camino Inca Clásico');
        $this->excursion = $this->crearExcursion($this->paquete, '2027-03-01');
    }

    public function test_da_de_baja_un_paquete_sin_reservas_activas(): void
    {
        $this->listado()
            ->callAction(TestAction::make('darDeBaja')->table($this->paquete))
            ->assertNotified('Paquete dado de baja');

        $this->assertSame(EstadoPaquete::Inactivo, $this->paquete->refresh()->estado);
    }

    public function test_no_da_de_baja_un_paquete_con_reservas_activas(): void
    {
        $this->crearReserva(EstadoReserva::SinPermiso);

        $this->listado()
            ->callAction(TestAction::make('darDeBaja')->table($this->paquete))
            ->assertNotified('No se dio de baja el paquete');

        $this->assertSame(EstadoPaquete::Activo, $this->paquete->refresh()->estado);
    }

    public function test_las_reservas_canceladas_no_impiden_la_baja(): void
    {
        $this->crearReserva(EstadoReserva::Cancelada);

        $this->listado()->callAction(TestAction::make('darDeBaja')->table($this->paquete));

        $this->assertSame(EstadoPaquete::Inactivo, $this->paquete->refresh()->estado);
    }

    public function test_un_paquete_dado_de_baja_no_se_muestra_en_el_portal(): void
    {
        $this->paquete->darDeBaja();

        $this->get('/paquetes')->assertOk()->assertDontSee('Camino Inca Clásico');
    }

    public function test_la_baja_espera_al_pago_y_la_reserva_se_registra_con_el_paquete_activo(): void
    {
        $this->assertTrue($this->excursion->retenerCupo(1));

        $this->listado()
            ->callAction(TestAction::make('darDeBaja')->table($this->paquete))
            ->assertNotified('No se dio de baja el paquete')
            ->assertActionDisabled(TestAction::make('edit')->table($this->paquete));

        $this->assertSame(EstadoPaquete::Activo, $this->paquete->refresh()->estado);

        $reserva = Reserva::generarReserva([
            'id_retencion' => 'pago-durante-la-baja',
            'id_excursion' => $this->excursion->id_excursion,
            'correo_electronico' => 'cliente@correo.com',
            'noches_extra_antes' => 0,
            'noches_extra_despues' => 0,
            'vence' => now()->addMinutes(config('reserva.minutos_retencion'))->toIso8601String(),
            'integrantes' => [['nombre' => 'Ana', 'apellido' => 'Pérez', 'documento_pasaporte' => 'ABC12345', 'equipo_camping' => false]],
        ], TipoPago::Total, 'Pago de prueba');

        $this->assertNotNull($reserva);
        $this->assertSame(EstadoReserva::Pendiente, $reserva->estado);
        $this->assertSame(EstadoPaquete::Activo, $this->paquete->refresh()->estado);
        $this->assertSame(0, $this->excursion->refresh()->plazas_retenidas);
    }

    public function test_permite_la_baja_despues_de_liberar_la_retencion(): void
    {
        $this->assertTrue($this->excursion->retenerCupo(1));
        $this->excursion->liberarCupoRetenido(1);

        $this->listado()
            ->callAction(TestAction::make('darDeBaja')->table($this->paquete))
            ->assertNotified('Paquete dado de baja');

        $this->assertSame(EstadoPaquete::Inactivo, $this->paquete->refresh()->estado);
    }

    public function test_un_formulario_abierto_antes_de_la_baja_no_puede_retener_cupo(): void
    {
        $this->excursion->load('paquete');
        $this->paquete->darDeBaja();

        $this->assertFalse($this->excursion->retenerCupo(1));
        $this->assertSame(0, $this->excursion->refresh()->plazas_retenidas);
    }

    public function test_cada_accion_se_ofrece_segun_el_estado(): void
    {
        $inactivo = $this->crearPaquete('Paquete dado de baja');
        $inactivo->darDeBaja();

        $this->listado()
            ->assertActionVisible(TestAction::make('darDeBaja')->table($this->paquete))
            ->assertActionHidden(TestAction::make('reactivar')->table($this->paquete))
            ->assertActionHidden(TestAction::make('darDeBaja')->table($inactivo))
            ->assertActionVisible(TestAction::make('reactivar')->table($inactivo));
    }

    /* ------------------------------- Reactivar ------------------------------- */

    public function test_reactiva_un_paquete_dado_de_baja(): void
    {
        $this->paquete->darDeBaja();

        $this->listado()
            ->callAction(TestAction::make('reactivar')->table($this->paquete))
            ->assertNotified('Paquete reactivado');

        $this->assertSame(EstadoPaquete::Activo, $this->paquete->refresh()->estado);
    }

    // R-02: mientras estuvo dado de baja, su guía quedó asignado a otra excursión el mismo lunes.
    public function test_no_reactiva_si_un_guia_quedo_ocupado_en_esas_fechas(): void
    {
        $this->paquete->darDeBaja();
        $this->crearExcursion($this->crearPaquete('Camino Inca Extendido'), '2027-03-01');

        $this->listado()
            ->callAction(TestAction::make('reactivar')->table($this->paquete))
            ->assertNotified('No se reactivó el paquete');

        $this->assertSame(EstadoPaquete::Inactivo, $this->paquete->refresh()->estado);
    }

    public function test_no_reactiva_si_el_guia_esta_ocupado_en_un_recorrido_que_ya_comenzo(): void
    {
        $this->paquete->darDeBaja();
        $this->crearExcursion($this->crearPaquete('Otro paquete'), '2027-03-01');
        $this->travelTo(Carbon::parse('2027-03-03 10:00:00'));

        $this->listado()
            ->callAction(TestAction::make('reactivar')->table($this->paquete))
            ->assertNotified('No se reactivó el paquete');

        $this->assertSame(EstadoPaquete::Inactivo, $this->paquete->refresh()->estado);
    }

    public function test_el_ultimo_dia_del_recorrido_todavia_se_revisa_al_reactivar(): void
    {
        $this->paquete->darDeBaja();
        $this->crearExcursion($this->crearPaquete('Otro paquete'), '2027-03-01');
        $this->travelTo(Carbon::parse('2027-03-04 23:59:59'));

        $this->listado()
            ->callAction(TestAction::make('reactivar')->table($this->paquete))
            ->assertNotified('No se reactivó el paquete');

        $this->assertSame(EstadoPaquete::Inactivo, $this->paquete->refresh()->estado);
    }

    public function test_reactiva_una_salida_ya_comenzada_si_el_guia_sigue_libre(): void
    {
        $this->paquete->darDeBaja();
        $this->travelTo(Carbon::parse('2027-03-03 10:00:00'));

        $this->listado()
            ->callAction(TestAction::make('reactivar')->table($this->paquete))
            ->assertNotified('Paquete reactivado');

        $this->assertSame(EstadoPaquete::Activo, $this->paquete->refresh()->estado);
    }

    public function test_las_salidas_finalizadas_no_bloquean_la_reactivacion(): void
    {
        $this->paquete->darDeBaja();
        $this->crearExcursion($this->crearPaquete('Otro paquete'), '2027-03-01');
        $this->travelTo(Carbon::parse('2027-03-05 00:00:00'));

        $this->listado()
            ->callAction(TestAction::make('reactivar')->table($this->paquete))
            ->assertNotified('Paquete reactivado');

        $this->assertSame(EstadoPaquete::Activo, $this->paquete->refresh()->estado);
    }

    // Las excursiones de un paquete dado de baja no ocupan al guía para otros paquetes.
    public function test_un_paquete_dado_de_baja_no_ocupa_a_sus_guias(): void
    {
        $this->paquete->darDeBaja();

        $this->assertNull(Excursion::buscarConflictoDeGuia(
            [['fecha_salida' => '2027-03-01', 'id_guia' => $this->guia->id_usuario]],
            4,
        ));
    }

    public function test_un_guia_no_ve_la_seccion_de_paquetes(): void
    {
        $this->actingAs($this->usuarioGuia)
            ->get('/admin/paquetes')
            ->assertForbidden();
    }

    private function listado()
    {
        $this->actingAs($this->administrador);

        return Livewire::test(ListPaquetes::class);
    }

    private function crearPaquete(string $nombre): Paquete
    {
        return Paquete::crearPaquete([
            'id_recorrido' => $this->recorrido->id_recorrido,
            'nombre' => $nombre,
            'precio_base' => 750,
            'costo_noche_extra_cusco' => 60,
            'costo_equipo_camping' => 45,
            'cantidad_porteadores' => 6,
        ]);
    }

    private function crearExcursion(Paquete $paquete, string $fechaSalida): Excursion
    {
        return Excursion::create([
            'id_paquete' => $paquete->id_paquete,
            'id_guia' => $this->guia->id_usuario,
            'fecha_salida' => $fechaSalida,
            'cupo' => 12,
        ]);
    }

    private function crearReserva(EstadoReserva $estado): void
    {
        Reserva::create([
            'id_excursion' => $this->excursion->id_excursion,
            'numero_reserva' => '000401-5',
            'correo_electronico' => 'cliente@correo.com',
            'fecha_reserva' => '2026-10-01 10:00:00',
            'estado' => $estado,
            'estado_saldo' => EstadoSaldo::Abonado,
            'noches_extra_antes' => 0,
            'noches_extra_despues' => 0,
            'fecha_limite_saldo' => null,
            'fecha_limite_confirmacion' => '2027-02-01 23:59:59',
        ]);
    }
}
