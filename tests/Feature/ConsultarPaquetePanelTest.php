<?php

namespace Tests\Feature;

use App\Enums\EstadoPaquete;
use App\Enums\EstadoPermiso;
use App\Enums\EstadoReserva;
use App\Enums\EstadoSaldo;
use App\Enums\Rol;
use App\Enums\TipoServicio;
use App\Filament\Resources\Paquetes\Pages\ListPaquetes;
use App\Models\Etapa;
use App\Models\Excursion;
use App\Models\Excursionista;
use App\Models\Guia;
use App\Models\Paquete;
use App\Models\Recorrido;
use App\Models\Reserva;
use App\Models\Servicio;
use App\Models\Usuario;
use App\Models\Valoracion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class ConsultarPaquetePanelTest extends TestCase
{
    use RefreshDatabase;

    private Paquete $paquete;

    private Usuario $administrador;

    private Usuario $guia;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-25 12:00:00'));
        $this->administrador = Usuario::create([
            'correo' => 'admin@caminodelinca.test', 'password' => 'admin1234', 'rol' => Rol::Administrador,
        ]);
        $this->guia = Usuario::create([
            'correo' => 'guia@caminodelinca.test', 'password' => 'guia1234', 'rol' => Rol::Guia,
        ]);
        Guia::create(['id_usuario' => $this->guia->id_usuario, 'nombre' => 'Rosa', 'apellido' => 'Quispe']);
        $recorrido = Recorrido::create(['nombre' => 'Camino de cuatro días', 'duracion_dias' => 4, 'cantidad_campings' => 3]);
        Etapa::create(['id_recorrido' => $recorrido->id_recorrido, 'nombre' => 'Km 82', 'orden' => 1]);
        $this->paquete = Paquete::create([
            'id_recorrido' => $recorrido->id_recorrido,
            'nombre' => 'Paquete de consulta',
            'precio_base' => 750.49,
            'costo_noche_extra_cusco' => 60.75,
            'costo_equipo_camping' => 45.25,
            'cantidad_porteadores' => 6,
            'estado' => EstadoPaquete::Activo,
            'fecha_creacion' => '2026-10-01',
        ]);
    }

    public function test_el_administrador_consulta_activos_e_inactivos_y_filtra_por_estado(): void
    {
        $inactivo = $this->paquete->replicate();
        $inactivo->fill(['nombre' => 'Paquete inactivo', 'estado' => EstadoPaquete::Inactivo])->save();
        $this->actingAs($this->administrador);

        Livewire::test(ListPaquetes::class)
            ->assertCanSeeTableRecords([$this->paquete, $inactivo])
            ->filterTable('estado', EstadoPaquete::Inactivo->value)
            ->assertCanSeeTableRecords([$inactivo])
            ->assertCanNotSeeTableRecords([$this->paquete])
            ->filterTable('estado', EstadoPaquete::Activo->value)
            ->assertCanSeeTableRecords([$this->paquete])
            ->assertCanNotSeeTableRecords([$inactivo]);

        $this->get('/admin/paquetes/'.$this->paquete->id_paquete)->assertOk()->assertSee('Paquete de consulta');
        $this->get('/admin/paquetes/'.$inactivo->id_paquete)->assertOk()->assertSee('Paquete inactivo')->assertSee('Inactivo');
        $this->get('/paquetes/'.$inactivo->id_paquete)->assertRedirect('/paquetes');
    }

    public function test_el_detalle_administrativo_incluye_datos_precios_servicios_y_promedio(): void
    {
        $hotel = Servicio::create(['tipo' => TipoServicio::Hotel, 'nombre' => 'Hotel en Cusco']);
        $this->paquete->servicios()->attach($hotel);
        $reserva = $this->crearReserva($this->crearExcursion('2026-09-07'), EstadoReserva::Finalizada, '000201-1');
        Valoracion::create(['id_reserva' => $reserva->id_reserva, 'fecha' => '2026-09-14', 'puntaje_experiencia_general' => 4]);

        $this->actingAs($this->administrador)->get('/admin/paquetes/'.$this->paquete->id_paquete)
            ->assertOk()
            ->assertSee('Camino de cuatro días')
            ->assertSee('Etapas')
            ->assertSee('Porteadores')
            ->assertSee('Hotel en Cusco')
            ->assertSee('USD 750,49')
            ->assertSee('USD 60,75')
            ->assertSee('USD 45,25')
            ->assertSee('4,0 / 5');
    }

    public function test_el_panel_muestra_todas_las_salidas_y_el_cupo_derivado(): void
    {
        $this->crearExcursion('2027-02-01', 12);
        $this->crearExcursion('2026-09-07');
        $proxima = $this->crearExcursion('2026-11-02', 3);
        foreach ([EstadoReserva::Pendiente, EstadoReserva::Confirmada, EstadoReserva::SinPermiso, EstadoReserva::Cancelada, EstadoReserva::Finalizada] as $i => $estado) {
            $reserva = $this->crearReserva($proxima, $estado, '00020'.$i.'-1');
            Excursionista::create([
                'id_reserva' => $reserva->id_reserva, 'nombre' => 'Ana', 'apellido' => 'Pérez',
                'documento_pasaporte' => 'PAS'.$i, 'equipo_camping' => false, 'estado_permiso' => EstadoPermiso::NoObtenido,
            ]);
        }

        // 12 - 3 retenciones - 3 integrantes de reservas que ocupan cupo = 6.
        $salidas = $this->paquete->getExcursionesParaConsulta();
        $this->assertSame([12, 6, 0], array_column($salidas, 'cupo_disponible'));
        $this->actingAs($this->administrador)->get('/admin/paquetes/'.$this->paquete->id_paquete)
            ->assertOk()->assertSeeInOrder(['07/09/2026', '02/11/2026', '01/02/2027'])->assertSee('Rosa Quispe');
        $this->get('/paquetes/'.$this->paquete->id_paquete)
            ->assertDontSee('07/09/2026')->assertDontSee('02/11/2026')->assertDontSee('01/02/2027');
    }

    public function test_el_panel_informa_si_no_hay_excursiones_ni_valoraciones(): void
    {
        $this->actingAs($this->administrador)->get('/admin/paquetes/'.$this->paquete->id_paquete)
            ->assertOk()->assertSee('No tiene excursiones.')->assertSee('Todavía no tiene valoraciones.');
    }

    public function test_el_guia_no_puede_abrir_el_listado_ni_el_detalle_por_url(): void
    {
        $this->actingAs($this->guia);
        $this->get('/admin/paquetes')->assertForbidden();
        $this->get('/admin/paquetes/'.$this->paquete->id_paquete)->assertForbidden();
        $this->paquete->update(['estado' => EstadoPaquete::Inactivo]);
        $this->get('/admin/paquetes/'.$this->paquete->id_paquete)->assertForbidden();
    }

    public function test_el_visitante_debe_iniciar_sesion_para_consultar_en_el_panel(): void
    {
        $this->get('/admin/paquetes')->assertRedirect('/admin/login');
        $this->get('/admin/paquetes/'.$this->paquete->id_paquete)->assertRedirect('/admin/login');
        $this->get('/paquetes/'.$this->paquete->id_paquete)->assertOk();
    }

    private function crearExcursion(string $fecha, int $retenidas = 0): Excursion
    {
        return Excursion::create([
            'id_paquete' => $this->paquete->id_paquete, 'id_guia' => $this->guia->id_usuario,
            'fecha_salida' => $fecha, 'cupo' => 12, 'plazas_retenidas' => $retenidas,
        ]);
    }

    private function crearReserva(Excursion $excursion, EstadoReserva $estado, string $numero): Reserva
    {
        return Reserva::create([
            'id_excursion' => $excursion->id_excursion, 'numero_reserva' => $numero,
            'correo_electronico' => 'cliente@correo.com', 'fecha_reserva' => '2026-03-02 10:00:00',
            'estado' => $estado, 'estado_saldo' => EstadoSaldo::Abonado,
            'noches_extra_antes' => 0, 'noches_extra_despues' => 0,
            'fecha_limite_confirmacion' => '2026-07-03 23:59:59',
        ]);
    }
}
