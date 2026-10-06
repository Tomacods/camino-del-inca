<?php

namespace Tests\Feature;

use App\Enums\EstadoPaquete;
use App\Enums\EstadoPermiso;
use App\Enums\EstadoReserva;
use App\Enums\EstadoSaldo;
use App\Enums\Rol;
use App\Enums\TipoPago;
use App\Models\Excursion;
use App\Models\Excursionista;
use App\Models\Guia;
use App\Models\Pago;
use App\Models\Paquete;
use App\Models\Recorrido;
use App\Models\Reserva;
use App\Models\Usuario;
use App\Models\Valoracion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ConsultarReservaTest extends TestCase
{
    use RefreshDatabase;

    private Excursion $excursion;

    protected function setUp(): void
    {
        parent::setUp();

        $this->crearReservaConfirmada();
    }

    public function test_la_reserva_llega_a_la_vista_con_sus_relaciones_cargadas(): void
    {
        $componente = $this->consultarReserva('000125-9')->instance();

        // La vista ya se dibujó y pudo cargar relaciones sobre la reserva guardada: se la vuelve a pedir desde cero.
        unset($componente->reserva);
        $reserva = $componente->reserva;

        $this->assertTrue($reserva->relationLoaded('excursion'));
        $this->assertTrue($reserva->excursion->relationLoaded('paquete'));
        $this->assertTrue($reserva->relationLoaded('excursionistas'));
        $this->assertTrue($reserva->relationLoaded('pagos'));
    }

    public function test_muestra_los_integrantes_con_el_estado_de_su_permiso(): void
    {
        $this->consultarReserva('000125-9')
            ->assertHasNoErrors()
            ->assertSee('Federico Ríos')
            ->assertSee('Sofía Ríos')
            ->assertSee(EstadoPermiso::Obtenido->value)
            ->assertSee(EstadoPermiso::NoObtenido->value);
    }

    public function test_muestra_el_medio_de_cada_pago(): void
    {
        $this->consultarReserva('000125-9')
            ->assertSee(TipoPago::Sena->value)
            ->assertSee('Tarjeta de crédito')
            ->assertSee('USD 650');
    }

    public function test_las_opciones_sin_pantalla_se_muestran_deshabilitadas(): void
    {
        $this->consultarReserva('000125-9')
            ->assertSee('Pagar saldo')
            ->assertSee('Modificar reserva')
            ->assertSee('Disponible próximamente')
            ->assertSeeHtml('href="/mi-reserva/000125-9/cancelar"');
    }

    public function test_avisa_si_no_encuentra_la_reserva(): void
    {
        $this->consultarReserva('999999-9')
            ->assertHasErrors(['numeroReserva'])
            ->assertSee('No encontramos una reserva con esos datos.');
    }

    public function test_una_reserva_finalizada_sin_valoracion_ofrece_valorar_los_servicios(): void
    {
        $this->crearReservaFinalizada('000118-1', 'paula.benitez@mail.com');

        $this->consultarReserva('000118-1', 'paula.benitez@mail.com')
            ->assertSee('Valorar servicios')
            ->assertDontSee('Ya valoraste los servicios de este viaje.');
    }

    public function test_una_reserva_finalizada_con_valoracion_no_ofrece_valorar(): void
    {
        $reserva = $this->crearReservaFinalizada('000119-3', 'carlos.gomez@mail.com');
        Valoracion::create([
            'id_reserva' => $reserva->id_reserva,
            'fecha' => '2026-09-14 19:30:00',
            'puntaje_experiencia_general' => 4,
        ]);

        $this->consultarReserva('000119-3', 'carlos.gomez@mail.com')
            ->assertSee('Ya valoraste los servicios de este viaje.')
            ->assertDontSee('Valorar servicios');
    }

    private function consultarReserva(string $numeroReserva, string $correo = 'federico.rios@mail.com')
    {
        return Livewire::test('consultar-reserva')
            ->set('correo', $correo)
            ->set('numeroReserva', $numeroReserva)
            ->call('consultarReserva');
    }

    private function crearReservaConfirmada(): void
    {
        $recorrido = Recorrido::create(['nombre' => 'Camino Clásico', 'duracion_dias' => 4, 'cantidad_campings' => 3]);

        $paquete = Paquete::create([
            'id_recorrido' => $recorrido->id_recorrido,
            'nombre' => 'Camino Inca Clásico',
            'precio_base' => 650,
            'costo_noche_extra_cusco' => 40,
            'costo_equipo_camping' => 25,
            'cantidad_porteadores' => 2,
            'estado' => EstadoPaquete::Activo,
            'fecha_creacion' => '2026-01-05',
        ]);

        $usuario = Usuario::create(['correo' => 'guia@caminodelinca.test', 'password' => 'guia1234', 'rol' => Rol::Guia]);
        Guia::create(['id_usuario' => $usuario->id_usuario, 'nombre' => 'Rosa', 'apellido' => 'Quispe']);

        $this->excursion = Excursion::create([
            'id_paquete' => $paquete->id_paquete,
            'id_guia' => $usuario->id_usuario,
            'fecha_salida' => '2027-02-01',
            'cupo' => 12,
            'plazas_retenidas' => 0,
        ]);

        $reserva = Reserva::create([
            'id_excursion' => $this->excursion->id_excursion,
            'numero_reserva' => '000125-9',
            'correo_electronico' => 'federico.rios@mail.com',
            'fecha_reserva' => '2026-10-01 10:00:00',
            'estado' => EstadoReserva::Confirmada,
            'estado_saldo' => EstadoSaldo::Adeudado,
            'noches_extra_antes' => 0,
            'noches_extra_despues' => 0,
            'fecha_limite_saldo' => '2027-01-01 23:59:59',
            'fecha_limite_confirmacion' => '2027-01-01 23:59:59',
        ]);

        foreach ([['Federico', EstadoPermiso::Obtenido], ['Sofía', EstadoPermiso::NoObtenido]] as $numero => [$nombre, $permiso]) {
            Excursionista::create([
                'id_reserva' => $reserva->id_reserva,
                'nombre' => $nombre,
                'apellido' => 'Ríos',
                'documento_pasaporte' => 'PAS'.$numero,
                'equipo_camping' => false,
                'estado_permiso' => $permiso,
            ]);
        }

        Pago::create([
            'id_reserva' => $reserva->id_reserva,
            'fecha' => '2026-10-01',
            'monto' => 650,
            'tipo_pago' => TipoPago::Sena,
            'medio_pago' => 'Tarjeta de crédito',
        ]);
    }

    private function crearReservaFinalizada(string $numeroReserva, string $correo): Reserva
    {
        return Reserva::create([
            'id_excursion' => $this->excursion->id_excursion,
            'numero_reserva' => $numeroReserva,
            'correo_electronico' => $correo,
            'fecha_reserva' => '2026-05-18 15:40:00',
            'estado' => EstadoReserva::Finalizada,
            'estado_saldo' => EstadoSaldo::Abonado,
            'noches_extra_antes' => 0,
            'noches_extra_despues' => 0,
            'fecha_limite_saldo' => null,
            'fecha_limite_confirmacion' => '2026-08-07 23:59:59',
        ]);
    }
}
