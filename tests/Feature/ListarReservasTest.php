<?php

namespace Tests\Feature;

use App\Enums\EstadoPaquete;
use App\Enums\EstadoReserva;
use App\Enums\EstadoSaldo;
use App\Enums\Rol;
use App\Filament\Resources\Reservas\Pages\ListReservas;
use App\Models\Excursion;
use App\Models\Guia;
use App\Models\Paquete;
use App\Models\Recorrido;
use App\Models\Reserva;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ListarReservasTest extends TestCase
{
    use RefreshDatabase;

    private Usuario $administrador;

    private Usuario $usuarioGuia;

    private Recorrido $recorrido;

    private Reserva $reservaConPorteadores;

    private Reserva $reservaSinPorteadores;

    protected function setUp(): void
    {
        parent::setUp();

        $this->administrador = Usuario::create(['correo' => 'admin@caminodelinca.test', 'password' => 'admin1234', 'rol' => Rol::Administrador]);

        $this->usuarioGuia = Usuario::create(['correo' => 'guia@caminodelinca.test', 'password' => 'guia1234', 'rol' => Rol::Guia]);
        Guia::create(['id_usuario' => $this->usuarioGuia->id_usuario, 'nombre' => 'Rosa', 'apellido' => 'Quispe']);

        $this->recorrido = Recorrido::create(['nombre' => 'Camino Clásico', 'duracion_dias' => 4, 'cantidad_campings' => 3]);

        // Una reserva en un paquete con 6 porteadores y otra en uno sin porteadores.
        $this->reservaConPorteadores = $this->crearReserva('000124-7', $this->crearExcursion('Camino Inca Clásico', 6));
        $this->reservaSinPorteadores = $this->crearReserva('000125-9', $this->crearExcursion('Camino Inca Corto', 0));
    }

    /* ---------------------------- Con porteadores ----------------------------- */

    public function test_el_scope_trae_solo_las_reservas_de_paquetes_con_porteadores(): void
    {
        $this->assertSame(
            [$this->reservaConPorteadores->id_reserva],
            Reserva::conPorteadores()->pluck('id_reserva')->all(),
        );
    }

    public function test_el_filtro_con_porteadores_muestra_solo_esas_reservas(): void
    {
        $this->actingAs($this->administrador);

        Livewire::test(ListReservas::class)
            ->assertCanSeeTableRecords([$this->reservaConPorteadores, $this->reservaSinPorteadores])
            ->filterTable('con_porteadores')
            ->assertCanSeeTableRecords([$this->reservaConPorteadores])
            ->assertCanNotSeeTableRecords([$this->reservaSinPorteadores]);
    }

    /* --------------------------------- Datos ---------------------------------- */

    private function crearExcursion(string $nombrePaquete, int $cantidadPorteadores): Excursion
    {
        $paquete = Paquete::create([
            'id_recorrido' => $this->recorrido->id_recorrido,
            'nombre' => $nombrePaquete,
            'precio_base' => 650,
            'costo_noche_extra_cusco' => 40,
            'costo_equipo_camping' => 25,
            'cantidad_porteadores' => $cantidadPorteadores,
            'estado' => EstadoPaquete::Activo,
            'fecha_creacion' => '2026-01-05',
        ]);

        return Excursion::create([
            'id_paquete' => $paquete->id_paquete,
            'id_guia' => $this->usuarioGuia->id_usuario,
            'fecha_salida' => '2027-01-18',
            'cupo' => 12,
            'plazas_retenidas' => 0,
        ]);
    }

    private function crearReserva(string $numeroReserva, Excursion $excursion): Reserva
    {
        return Reserva::create([
            'id_excursion' => $excursion->id_excursion,
            'numero_reserva' => $numeroReserva,
            'correo_electronico' => 'titular.'.$numeroReserva.'@mail.com',
            'fecha_reserva' => '2026-09-30 10:30:00',
            'estado' => EstadoReserva::Pendiente,
            'estado_saldo' => EstadoSaldo::Adeudado,
            'noches_extra_antes' => 0,
            'noches_extra_despues' => 0,
            'fecha_limite_saldo' => '2026-12-18 23:59:59',
            'fecha_limite_confirmacion' => '2026-12-18 23:59:59',
        ]);
    }
}
