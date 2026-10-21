<?php

namespace Tests\Feature;

use App\Enums\EstadoPaquete;
use App\Enums\EstadoReserva;
use App\Enums\EstadoSaldo;
use App\Enums\Rol;
use App\Enums\TipoServicio;
use App\Filament\Resources\Reservas\Pages\ListReservas;
use App\Models\Excursion;
use App\Models\Guia;
use App\Models\Paquete;
use App\Models\Recorrido;
use App\Models\Reserva;
use App\Models\Servicio;
use App\Models\Usuario;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ListarReservasTest extends TestCase
{
    use RefreshDatabase;

    private Usuario $administrador;

    private Usuario $usuarioGuia;

    private Recorrido $recorrido;

    private Servicio $hotelCusco;

    private Servicio $hotelAguasCalientes;

    private Servicio $campingAyapata;

    private Reserva $reservaClasico;

    private Reserva $reservaCorto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->administrador = Usuario::create(['correo' => 'admin@caminodelinca.test', 'password' => 'admin1234', 'rol' => Rol::Administrador]);

        $this->usuarioGuia = Usuario::create(['correo' => 'guia@caminodelinca.test', 'password' => 'guia1234', 'rol' => Rol::Guia]);
        Guia::create(['id_usuario' => $this->usuarioGuia->id_usuario, 'nombre' => 'Rosa', 'apellido' => 'Quispe']);

        $this->recorrido = Recorrido::create(['nombre' => 'Camino Clásico', 'duracion_dias' => 4, 'cantidad_campings' => 3]);

        $this->hotelCusco = Servicio::create(['tipo' => TipoServicio::Hotel, 'nombre' => 'Hotel en Cusco']);
        $this->hotelAguasCalientes = Servicio::create(['tipo' => TipoServicio::Hotel, 'nombre' => 'Hotel en Aguas Calientes']);
        $this->campingAyapata = Servicio::create(['tipo' => TipoServicio::CampingDeEtapa, 'nombre' => 'Camping Ayapata']);

        // Una reserva en cada paquete. El Clásico tiene 6 porteadores, un hotel y un camping; el Corto no tiene
        // porteadores ni camping, y tiene otro hotel.
        $this->reservaClasico = $this->crearReserva('000124-7', $this->crearExcursion('Camino Inca Clásico', 6, [$this->hotelCusco, $this->campingAyapata]));
        $this->reservaCorto = $this->crearReserva('000125-9', $this->crearExcursion('Camino Inca Corto', 0, [$this->hotelAguasCalientes]));
    }

    /* ---------------------------- Con porteadores ----------------------------- */

    public function test_el_scope_trae_solo_las_reservas_de_paquetes_con_porteadores(): void
    {
        $this->assertSame(
            [$this->reservaClasico->id_reserva],
            Reserva::conPorteadores()->pluck('id_reserva')->all(),
        );
    }

    public function test_el_filtro_con_porteadores_muestra_solo_esas_reservas(): void
    {
        $this->actingAs($this->administrador);

        Livewire::test(ListReservas::class)
            ->assertCanSeeTableRecords([$this->reservaClasico, $this->reservaCorto])
            ->filterTable('con_porteadores')
            ->assertCanSeeTableRecords([$this->reservaClasico])
            ->assertCanNotSeeTableRecords([$this->reservaCorto]);
    }

    /* -------------------------------- Servicios ------------------------------- */

    public function test_el_scope_trae_solo_las_reservas_de_paquetes_con_ese_servicio(): void
    {
        $this->assertSame(
            [$this->reservaCorto->id_reserva],
            Reserva::conServicio($this->hotelAguasCalientes->id_servicio)->pluck('id_reserva')->all(),
        );
    }

    public function test_el_filtro_de_hotel_muestra_solo_las_reservas_con_ese_hotel(): void
    {
        $this->actingAs($this->administrador);

        Livewire::test(ListReservas::class)
            ->filterTable('servicio_hotel', $this->hotelCusco->id_servicio)
            ->assertCanSeeTableRecords([$this->reservaClasico])
            ->assertCanNotSeeTableRecords([$this->reservaCorto]);
    }

    public function test_con_hotel_y_camping_tienen_que_cumplirse_los_dos(): void
    {
        $this->actingAs($this->administrador);

        Livewire::test(ListReservas::class)
            ->filterTable('servicio_hotel', $this->hotelCusco->id_servicio)
            ->filterTable('servicio_camping_de_etapa', $this->campingAyapata->id_servicio)
            ->assertCanSeeTableRecords([$this->reservaClasico])
            ->assertCanNotSeeTableRecords([$this->reservaCorto]);
    }

    // El Clásico tiene el camping pero no ese hotel, y el Corto tiene el hotel pero no tiene camping.
    public function test_con_un_hotel_y_un_camping_de_paquetes_distintos_no_queda_ninguna(): void
    {
        $this->actingAs($this->administrador);

        Livewire::test(ListReservas::class)
            ->filterTable('servicio_hotel', $this->hotelAguasCalientes->id_servicio)
            ->filterTable('servicio_camping_de_etapa', $this->campingAyapata->id_servicio)
            ->assertCountTableRecords(0);
    }

    public function test_el_filtro_de_hotel_solo_ofrece_hoteles(): void
    {
        $this->actingAs($this->administrador);

        Livewire::test(ListReservas::class)
            ->assertTableFilterExists('servicio_hotel', fn (SelectFilter $filtro) => $filtro->getOptions() === [
                $this->hotelAguasCalientes->id_servicio => 'Hotel en Aguas Calientes',
                $this->hotelCusco->id_servicio => 'Hotel en Cusco',
            ]);
    }

    /* --------------------------------- Datos ---------------------------------- */

    private function crearExcursion(string $nombrePaquete, int $cantidadPorteadores, array $servicios): Excursion
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

        $paquete->servicios()->attach(collect($servicios)->pluck('id_servicio'));

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
