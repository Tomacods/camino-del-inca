<?php

namespace Tests\Feature;

use App\Enums\EstadoPaquete;
use App\Enums\EstadoPermiso;
use App\Enums\EstadoReserva;
use App\Enums\EstadoSaldo;
use App\Enums\MotivoDevolucion;
use App\Enums\Rol;
use App\Enums\TipoPago;
use App\Models\Devolucion;
use App\Models\Excursion;
use App\Models\Guia;
use App\Models\Pago;
use App\Models\Paquete;
use App\Models\Recorrido;
use App\Models\Reserva;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

// Solicitar Reintegro (CU-17). La reserva 000125-9 está «Sin Permiso» y tiene pagada la seña de USD 750: se devuelve
// todo lo abonado, sin penalidad.
class SolicitarReintegroTest extends TestCase
{
    use RefreshDatabase;

    private Excursion $excursion;

    private Reserva $reserva;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-13 10:00:00'));

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
            ->assertSee('USD 750')
            ->call('confirmar')
            ->assertSet('paso', 3)
            ->assertSee('Reserva cancelada con reintegro del 100 %')
            ->assertSee('Registramos el reintegro de USD 750.')
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
    }

    public function test_si_la_confirmacion_llega_dos_veces_registra_una_sola_devolucion(): void
    {
        $this->darAcceso();

        $this->abrirPantalla()
            ->call('confirmar')
            ->call('confirmar')
            ->assertDontSee('SQLSTATE');

        $this->assertSame(1, Devolucion::count());
    }

    public function test_el_navegador_no_puede_cambiar_la_reserva(): void
    {
        $this->crearReserva('000122-3', EstadoReserva::Confirmada);
        $this->darAcceso();

        $this->expectException(CannotUpdateLockedPropertyException::class);

        $this->abrirPantalla()->set('numeroReserva', '000122-3');
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
