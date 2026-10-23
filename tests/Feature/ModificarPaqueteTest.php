<?php

namespace Tests\Feature;

use App\Enums\EstadoReserva;
use App\Enums\EstadoSaldo;
use App\Enums\Rol;
use App\Enums\TipoServicio;
use App\Filament\Resources\Paquetes\Pages\EditPaquete;
use App\Filament\Resources\Paquetes\PaqueteResource;
use App\Models\Excursion;
use App\Models\Guia;
use App\Models\Paquete;
use App\Models\Recorrido;
use App\Models\Reserva;
use App\Models\Servicio;
use App\Models\Usuario;
use Closure;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

// CU-02 Modificar Paquete (R-02), en el panel del administrador.
class ModificarPaqueteTest extends TestCase
{
    use RefreshDatabase;

    private Usuario $administrador;

    private Guia $rosa;

    private Guia $luis;

    private Recorrido $recorrido;

    private Servicio $hotel;

    private Servicio $camping;

    private Paquete $paquete;

    private Excursion $excursion;

    private Closure $deshacerRepeaterFalso;

    protected function setUp(): void
    {
        parent::setUp();

        // Un miércoles. El 01/03, el 08/03 y el 15/03/2027 son lunes.
        Carbon::setTestNow('2026-10-21');

        $this->deshacerRepeaterFalso = Repeater::fake();

        $this->administrador = Usuario::create(['correo' => 'admin@caminodelinca.test', 'password' => 'admin1234', 'rol' => Rol::Administrador]);
        $this->rosa = $this->crearGuia('rosa@caminodelinca.test', 'Rosa', 'Quispe');
        $this->luis = $this->crearGuia('luis@caminodelinca.test', 'Luis', 'Mamani');

        $this->recorrido = Recorrido::create(['nombre' => 'Camino Inca de 4 días', 'duracion_dias' => 4, 'cantidad_campings' => 3]);
        $this->hotel = Servicio::create(['tipo' => TipoServicio::Hotel, 'nombre' => 'Hotel en Cusco']);
        $this->camping = Servicio::create(['tipo' => TipoServicio::CampingDeEtapa, 'nombre' => 'Camping Wayllabamba']);

        $this->paquete = $this->crearPaquete('Camino Inca Clásico');
        $this->paquete->servicios()->attach($this->hotel->id_servicio);
        $this->excursion = $this->crearExcursion($this->paquete, '2027-03-01', $this->rosa);
    }

    protected function tearDown(): void
    {
        ($this->deshacerRepeaterFalso)();

        parent::tearDown();
    }

    public function test_modifica_los_datos_del_paquete_y_de_sus_excursiones(): void
    {
        $item = 'excursiones.record-'.$this->excursion->id_excursion;

        $this->formulario()
            ->fillForm([
                'nombre' => 'Camino Inca Clásico renovado',
                'precio_base' => 800,
                'servicios' => [$this->hotel->id_servicio, $this->camping->id_servicio],
                "{$item}.cupo" => 8,
                "{$item}.id_guia" => $this->luis->id_usuario,
                'excursiones.nueva.fecha_salida' => '2027-03-08',
                'excursiones.nueva.cupo' => 10,
                'excursiones.nueva.id_guia' => $this->rosa->id_usuario,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->paquete->refresh();
        $this->assertSame('Camino Inca Clásico renovado', $this->paquete->nombre);
        $this->assertEquals(800, $this->paquete->precio_base);
        $this->assertCount(2, $this->paquete->servicios);

        $this->excursion->refresh();
        $this->assertEquals(8, $this->excursion->cupo);
        $this->assertEquals($this->luis->id_usuario, $this->excursion->id_guia);

        $this->assertTrue($this->paquete->excursiones()->whereDate('fecha_salida', '2027-03-08')->exists());
    }

    // Sus propias excursiones no cuentan como superposición: guardar sin cambiar los guías no da error.
    public function test_guarda_sin_cambiar_los_guias(): void
    {
        $this->formulario()
            ->fillForm(['nombre' => 'Camino Inca Clásico 2027'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Camino Inca Clásico 2027', $this->paquete->refresh()->nombre);
    }

    public function test_la_fecha_de_una_excursion_existente_no_cambia(): void
    {
        $this->formulario()
            ->fillForm(['excursiones.record-'.$this->excursion->id_excursion.'.fecha_salida' => '2027-03-15'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('2027-03-01', $this->excursion->refresh()->fecha_salida->format('Y-m-d'));
    }

    public function test_el_recorrido_no_cambia(): void
    {
        $otro = Recorrido::create(['nombre' => 'Camino Inca de 2 días', 'duracion_dias' => 2, 'cantidad_campings' => 0]);

        $this->formulario()
            ->fillForm(['id_recorrido' => $otro->id_recorrido])
            ->call('save');

        $this->assertEquals($this->recorrido->id_recorrido, $this->paquete->refresh()->id_recorrido);
    }

    public function test_una_salida_nueva_tiene_que_ser_un_lunes(): void
    {
        $this->formulario()
            ->fillForm([
                'excursiones.nueva.fecha_salida' => '2027-03-09',
                'excursiones.nueva.cupo' => 10,
                'excursiones.nueva.id_guia' => $this->rosa->id_usuario,
            ])
            ->call('save')
            ->assertHasFormErrors(['excursiones.nueva.fecha_salida']);
    }

    // R-02: la reasignación de guía tiene la misma validación de superposición que R-01.
    public function test_rechaza_reasignar_un_guia_ocupado_en_otro_paquete(): void
    {
        $otro = $this->crearPaquete('Camino Inca Extendido');
        $this->crearExcursion($otro, '2027-03-01', $this->luis);

        $this->formulario()
            ->fillForm(['excursiones.record-'.$this->excursion->id_excursion.'.id_guia' => $this->luis->id_usuario])
            ->call('save')
            ->assertHasFormErrors(['excursiones']);

        $this->assertEquals($this->rosa->id_usuario, $this->excursion->refresh()->id_guia);
    }

    // Otro pedido ocupa al guía nuevo después de la validación y antes de que se guarde: no se guarda nada.
    public function test_rechaza_al_guardar_si_otro_pedido_ocupo_al_guia(): void
    {
        $otro = $this->crearPaquete('Camino Inca Extendido');
        $yaOcupado = false;

        Excursion::updated(function (Excursion $excursion) use ($otro, &$yaOcupado) {
            if ($yaOcupado || $excursion->id_excursion !== $this->excursion->id_excursion) {
                return;
            }

            $yaOcupado = true;

            $this->crearExcursion($otro, '2027-03-01', $this->luis);
        });

        $this->formulario()
            ->fillForm([
                'nombre' => 'Camino Inca Clásico 2027',
                'excursiones.record-'.$this->excursion->id_excursion.'.id_guia' => $this->luis->id_usuario,
            ])
            ->call('save')
            ->assertNotified('Este paquete no se puede modificar');

        $this->assertSame('Camino Inca Clásico', $this->paquete->refresh()->nombre);
        $this->assertEquals($this->rosa->id_usuario, $this->excursion->refresh()->id_guia);
    }

    public function test_con_reservas_activas_vuelve_al_listado_sin_modificar(): void
    {
        $this->crearReserva(EstadoReserva::Pendiente);

        $this->formulario()
            ->assertNotified('Este paquete no se puede modificar')
            ->assertRedirect(PaqueteResource::getUrl('index'));
    }

    public function test_las_reservas_finalizadas_no_impiden_modificar(): void
    {
        $this->crearReserva(EstadoReserva::Finalizada);

        $this->formulario()
            ->fillForm(['nombre' => 'Camino Inca Clásico 2027'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Camino Inca Clásico 2027', $this->paquete->refresh()->nombre);
    }

    // Si entra una reserva mientras se edita, al guardar no se modifica nada.
    public function test_al_guardar_vuelve_a_revisar_las_reservas(): void
    {
        $formulario = $this->formulario();

        $this->crearReserva(EstadoReserva::Confirmada);

        $formulario
            ->fillForm([
                'nombre' => 'Camino Inca Clásico 2027',
                'excursiones.record-'.$this->excursion->id_excursion.'.cupo' => 4,
            ])
            ->call('save')
            ->assertNotified('Este paquete no se puede modificar');

        $this->assertSame('Camino Inca Clásico', $this->paquete->refresh()->nombre);
        $this->assertEquals(12, $this->excursion->refresh()->cupo);
    }

    private function formulario()
    {
        $this->actingAs($this->administrador);

        return Livewire::test(EditPaquete::class, ['record' => $this->paquete->getRouteKey()]);
    }

    private function crearGuia(string $correo, string $nombre, string $apellido): Guia
    {
        $usuario = Usuario::create(['correo' => $correo, 'password' => 'guia1234', 'rol' => Rol::Guia]);

        return Guia::create(['id_usuario' => $usuario->id_usuario, 'nombre' => $nombre, 'apellido' => $apellido]);
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

    private function crearExcursion(Paquete $paquete, string $fechaSalida, Guia $guia): Excursion
    {
        return Excursion::create([
            'id_paquete' => $paquete->id_paquete,
            'id_guia' => $guia->id_usuario,
            'fecha_salida' => $fechaSalida,
            'cupo' => 12,
        ]);
    }

    private function crearReserva(EstadoReserva $estado): void
    {
        Reserva::create([
            'id_excursion' => $this->excursion->id_excursion,
            'numero_reserva' => '000301-'.random_int(0, 9),
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
