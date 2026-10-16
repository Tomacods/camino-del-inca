<?php

namespace Tests\Feature;

use App\Enums\EstadoPaquete;
use App\Enums\EstadoPermiso;
use App\Enums\EstadoReserva;
use App\Enums\EstadoSaldo;
use App\Enums\MotivoDevolucion;
use App\Enums\Rol;
use App\Enums\TipoPago;
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

// Cancelar Reserva (CU-21). Hoy es el 13/10/2026 y la salida es el 01/02/2027: faltan más de 30 días, así que se
// devuelve el 50 % de lo abonado. La reserva 000125-9 está «Confirmada» y tiene pagada la seña de USD 750.
class CancelarReservaTest extends TestCase
{
    use RefreshDatabase;

    private Excursion $excursion;

    private Reserva $reserva;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-13 10:00:00'));

        $this->excursion = $this->crearExcursion('2027-02-01');
        $this->reserva = $this->crearReserva($this->excursion, '000125-9', EstadoReserva::Confirmada);
    }

    public function test_sin_acceso_en_la_sesion_vuelve_a_mi_reserva(): void
    {
        $this->get('/mi-reserva/000125-9/cancelar')->assertRedirect('/mi-reserva');
    }

    public function test_la_pantalla_lleva_del_resumen_a_la_cancelacion(): void
    {
        $this->darAcceso();

        $this->abrirPantalla()
            ->assertSet('paso', 2)
            ->assertSee('Revisá cuánto se te devuelve.')
            ->assertSee('Confirmar cancelación')
            ->assertSee('USD 375,00')
            ->assertSee('Te corresponde un reembolso del 50 %.')
            ->call('confirmar')
            ->assertSet('paso', 3)
            ->assertSee('¡Reserva cancelada con éxito!')
            ->assertDontSee('Revisá cuánto se te devuelve.');

        $reserva = $this->reserva->fresh();
        $this->assertSame(EstadoReserva::Cancelada, $reserva->estado);
        $this->assertSame(MotivoDevolucion::Reembolso, $reserva->devolucion->motivo);
        $this->assertSame('375.00', $reserva->devolucion->monto);
    }

    public function test_a_30_dias_o_menos_de_la_salida_no_genera_devolucion(): void
    {
        $this->travelTo(Carbon::parse('2027-01-10 10:00:00'));
        $this->darAcceso();

        $this->abrirPantalla()
            ->assertSee('USD 0,00')
            ->assertSee('Faltan 30 días o menos. No genera devolución.')
            ->call('confirmar')
            ->assertSee('¡Reserva cancelada con éxito!');

        $reserva = $this->reserva->fresh();
        $this->assertSame(EstadoReserva::Cancelada, $reserva->estado);
        $this->assertNull($reserva->devolucion);
    }

    public function test_con_una_reserva_que_no_admite_cancelacion_muestra_el_aviso(): void
    {
        $this->reserva->update(['estado' => EstadoReserva::SinPermiso]);
        $this->darAcceso();

        $this->abrirPantalla()
            ->assertSet('paso', 1)
            ->assertSee('No se puede cancelar')
            ->assertSee('Volver a mi reserva')
            ->assertDontSee('Confirmar cancelación')
            ->assertDontSee('Revisá cuánto se te devuelve.');
    }

    public function test_si_la_reserva_cambia_de_estado_antes_de_confirmar_no_se_cancela(): void
    {
        $this->darAcceso();
        $pantalla = $this->abrirPantalla();

        $this->reserva->update(['estado' => EstadoReserva::SinPermiso]);

        $pantalla->call('confirmar')
            ->assertSet('paso', 1)
            ->assertSee('No se puede cancelar');

        $this->assertSame(EstadoReserva::SinPermiso, $this->reserva->fresh()->estado);
        $this->assertNull($this->reserva->fresh()->devolucion);
    }

    public function test_el_navegador_no_puede_cambiar_la_reserva(): void
    {
        $this->crearReserva($this->excursion, '000122-3', EstadoReserva::Confirmada);
        $this->darAcceso();

        $this->expectException(CannotUpdateLockedPropertyException::class);

        $this->abrirPantalla()->set('numeroReserva', '000122-3');
    }

    private function abrirPantalla()
    {
        return Livewire::test('cancelar-reserva', ['numeroReserva' => '000125-9']);
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
    private function crearReserva(Excursion $excursion, string $numeroReserva, EstadoReserva $estado): Reserva
    {
        $reserva = Reserva::create([
            'id_excursion' => $excursion->id_excursion,
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
            'estado_permiso' => EstadoPermiso::Obtenido,
        ]);

        Pago::registrarPago($reserva, 750, '2026-10-01 18:20:00', 'Tarjeta de crédito', TipoPago::Sena);

        return $reserva;
    }
}
