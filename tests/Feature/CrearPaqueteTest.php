<?php

namespace Tests\Feature;

use App\Enums\EstadoPaquete;
use App\Enums\Rol;
use App\Enums\TipoServicio;
use App\Filament\Resources\Paquetes\Pages\CreatePaquete;
use App\Filament\Resources\Paquetes\Pages\ListPaquetes;
use App\Models\Excursion;
use App\Models\Guia;
use App\Models\Paquete;
use App\Models\Recorrido;
use App\Models\Servicio;
use App\Models\Usuario;
use Closure;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

// CU-01 Crear Paquete (R-01), en el panel del administrador.
class CrearPaqueteTest extends TestCase
{
    use RefreshDatabase;

    private Usuario $administrador;

    private Usuario $usuarioGuia;

    private Guia $guia;

    private Recorrido $recorrido;

    private Servicio $hotel;

    private Servicio $camping;

    private Closure $deshacerRepeaterFalso;

    protected function setUp(): void
    {
        parent::setUp();

        // Un miércoles. El 01/03/2027 y el 08/03/2027 son lunes.
        Carbon::setTestNow('2026-10-21');

        // Claves numéricas en el repetidor, para poder escribir las excursiones en la prueba.
        $this->deshacerRepeaterFalso = Repeater::fake();

        $this->administrador = Usuario::create(['correo' => 'admin@caminodelinca.test', 'password' => 'admin1234', 'rol' => Rol::Administrador]);
        $this->usuarioGuia = Usuario::create(['correo' => 'guia@caminodelinca.test', 'password' => 'guia1234', 'rol' => Rol::Guia]);
        $this->guia = Guia::create(['id_usuario' => $this->usuarioGuia->id_usuario, 'nombre' => 'Rosa', 'apellido' => 'Quispe']);

        $this->recorrido = Recorrido::create(['nombre' => 'Camino Inca de 4 días', 'duracion_dias' => 4, 'cantidad_campings' => 3]);
        $this->hotel = Servicio::create(['tipo' => TipoServicio::Hotel, 'nombre' => 'Hotel en Cusco']);
        $this->camping = Servicio::create(['tipo' => TipoServicio::CampingDeEtapa, 'nombre' => 'Camping Wayllabamba']);
    }

    protected function tearDown(): void
    {
        ($this->deshacerRepeaterFalso)();

        parent::tearDown();
    }

    public function test_el_administrador_crea_un_paquete_activo_con_sus_servicios_y_excursiones(): void
    {
        $this->formulario()
            ->fillForm($this->datosValidos())
            ->call('create')
            ->assertHasNoFormErrors();

        $paquete = Paquete::where('nombre', 'Camino Inca Clásico')->firstOrFail();

        $this->assertSame(EstadoPaquete::Activo, $paquete->estado);
        $this->assertTrue($paquete->fecha_creacion->isSameDay('2026-10-21'));
        $this->assertEqualsCanonicalizing(
            [$this->hotel->id_servicio, $this->camping->id_servicio],
            $paquete->servicios->pluck('id_servicio')->all(),
        );

        $excursiones = $paquete->excursiones()->orderBy('fecha_salida')->get();
        $this->assertSame(['2027-03-01', '2027-03-08'], $excursiones->map(fn (Excursion $e) => $e->fecha_salida->format('Y-m-d'))->all());
        $this->assertEquals([12, 10], $excursiones->pluck('cupo')->all());
        $this->assertEquals([$this->guia->id_usuario, $this->guia->id_usuario], $excursiones->pluck('id_guia')->all());
    }

    public function test_pide_los_datos_obligatorios(): void
    {
        $this->formulario()
            ->fillForm(['nombre' => '', 'id_recorrido' => null, 'servicios' => [], 'excursiones' => []])
            ->call('create')
            ->assertHasFormErrors(['nombre', 'id_recorrido', 'servicios', 'excursiones']);

        $this->assertSame(0, Paquete::count());
    }

    public function test_el_nombre_no_se_puede_repetir(): void
    {
        Paquete::crearPaquete([
            'id_recorrido' => $this->recorrido->id_recorrido,
            'nombre' => 'Camino Inca Clásico',
            'precio_base' => 700,
            'costo_noche_extra_cusco' => 50,
            'costo_equipo_camping' => 40,
            'cantidad_porteadores' => 4,
        ]);

        $this->formulario()
            ->fillForm($this->datosValidos())
            ->call('create')
            ->assertHasFormErrors(['nombre' => 'unique']);
    }

    public function test_la_salida_tiene_que_ser_un_lunes(): void
    {
        $this->formulario()
            ->fillForm($this->datosValidos(excursiones: [$this->salida('2027-03-02')]))
            ->call('create')
            ->assertHasFormErrors(['excursiones.0.fecha_salida']);
    }

    public function test_la_salida_tiene_que_ser_futura(): void
    {
        $this->formulario()
            ->fillForm($this->datosValidos(excursiones: [$this->salida('2026-10-19')]))
            ->call('create')
            ->assertHasFormErrors(['excursiones.0.fecha_salida']);
    }

    public function test_dos_excursiones_no_pueden_salir_el_mismo_dia(): void
    {
        $this->formulario()
            ->fillForm($this->datosValidos(excursiones: [$this->salida('2027-03-01'), $this->salida('2027-03-01')]))
            ->call('create')
            ->assertHasFormErrors(['excursiones.1.fecha_salida']);
    }

    public function test_el_cupo_tiene_que_ser_mayor_a_cero(): void
    {
        $this->formulario()
            ->fillForm($this->datosValidos(excursiones: [$this->salida('2027-03-01', cupo: 0)]))
            ->call('create')
            ->assertHasFormErrors(['excursiones.0.cupo']);
    }

    // R-01: el guía ya conduce otra excursión (de otro paquete) en esas fechas.
    public function test_rechaza_un_guia_ocupado_en_las_mismas_fechas(): void
    {
        $this->crearExcursionDeOtroPaquete('2027-03-01');

        $this->formulario()
            ->fillForm($this->datosValidos(excursiones: [$this->salida('2027-03-01')]))
            ->call('create')
            ->assertHasFormErrors(['excursiones']);

        $this->assertFalse(Paquete::where('nombre', 'Camino Inca Clásico')->exists());
    }

    public function test_el_mismo_guia_puede_salir_otra_semana(): void
    {
        $this->crearExcursionDeOtroPaquete('2027-03-01');

        $this->formulario()
            ->fillForm($this->datosValidos(excursiones: [$this->salida('2027-03-08')]))
            ->call('create')
            ->assertHasNoFormErrors();
    }

    public function test_un_guia_no_puede_entrar_al_formulario(): void
    {
        $this->actingAs($this->usuarioGuia)
            ->get('/admin/paquetes/crear')
            ->assertForbidden();
    }

    public function test_el_listado_del_panel_muestra_los_paquetes(): void
    {
        $paquete = Paquete::crearPaquete([
            'id_recorrido' => $this->recorrido->id_recorrido,
            'nombre' => 'Camino Inca Corto',
            'precio_base' => 480,
            'costo_noche_extra_cusco' => 60,
            'costo_equipo_camping' => 45,
            'cantidad_porteadores' => 0,
        ]);

        $this->actingAs($this->administrador);

        Livewire::test(ListPaquetes::class)->assertCanSeeTableRecords([$paquete]);
    }

    private function formulario()
    {
        $this->actingAs($this->administrador);

        return Livewire::test(CreatePaquete::class);
    }

    private function datosValidos(?array $excursiones = null): array
    {
        return [
            'nombre' => 'Camino Inca Clásico',
            'id_recorrido' => $this->recorrido->id_recorrido,
            'cantidad_porteadores' => 6,
            'precio_base' => 750,
            'costo_noche_extra_cusco' => 60,
            'costo_equipo_camping' => 45,
            'servicios' => [$this->hotel->id_servicio, $this->camping->id_servicio],
            'excursiones' => $excursiones ?? [
                $this->salida('2027-03-01', cupo: 12),
                $this->salida('2027-03-08', cupo: 10),
            ],
        ];
    }

    private function salida(string $fecha, int $cupo = 12): array
    {
        return ['fecha_salida' => $fecha, 'cupo' => $cupo, 'id_guia' => $this->guia->id_usuario];
    }

    // Una excursión del mismo guía en otro paquete, de 4 días: ocupa del lunes al jueves.
    private function crearExcursionDeOtroPaquete(string $fechaSalida): void
    {
        $otro = Paquete::crearPaquete([
            'id_recorrido' => $this->recorrido->id_recorrido,
            'nombre' => 'Camino Inca Extendido',
            'precio_base' => 950,
            'costo_noche_extra_cusco' => 60,
            'costo_equipo_camping' => 45,
            'cantidad_porteadores' => 8,
        ]);

        Excursion::create([
            'id_paquete' => $otro->id_paquete,
            'id_guia' => $this->guia->id_usuario,
            'fecha_salida' => $fechaSalida,
            'cupo' => 12,
        ]);
    }
}
