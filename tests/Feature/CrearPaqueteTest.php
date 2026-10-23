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
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use RuntimeException;
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

    // Los máximos de las columnas (smallint y decimal(10,2)): PostgreSQL rechazaría un valor más grande.
    public function test_los_numeros_no_superan_los_maximos_de_la_base(): void
    {
        $datos = $this->datosValidos(excursiones: [$this->salida('2027-03-01', cupo: 40000)]);
        $datos['precio_base'] = 100000000;

        $this->formulario()
            ->fillForm($datos)
            ->call('create')
            ->assertHasFormErrors(['precio_base', 'excursiones.0.cupo']);
    }

    // Revisión del PR: si falla el guardado de una excursión, no queda un paquete «Activo» incompleto.
    public function test_si_falla_una_excursion_no_queda_nada_guardado(): void
    {
        Excursion::creating(function (Excursion $excursion) {
            if ($excursion->fecha_salida->format('Y-m-d') === '2027-03-08') {
                throw new RuntimeException('La base rechazó la segunda excursión.');
            }
        });

        try {
            $this->formulario()->fillForm($this->datosValidos())->call('create');
        } catch (RuntimeException) {
            // El error sigue su curso; lo que importa es lo que quedó en la base.
        }

        $this->assertSame(0, Paquete::count());
        $this->assertSame(0, Excursion::count());
        $this->assertDatabaseCount('paquete_servicio', 0);
    }

    // Una escritura entre la validación inicial y el guardado debe detectarse sin crear el paquete.
    // Es una simulación en la misma conexión; el bloqueo real se comprueba aparte en PostgreSQL.
    public function test_rechaza_al_guardar_si_otro_pedido_ocupo_al_guia(): void
    {
        $this->ocuparGuiaDespuesDeLaValidacion();

        $this->formulario()
            ->fillForm($this->datosValidos(excursiones: [$this->salida('2027-03-01')]))
            ->call('create')
            ->assertNotified('No se creó el paquete')
            ->assertSet('record', null);

        $this->assertFalse(Paquete::where('nombre', 'Camino Inca Clásico')->exists());
        $this->assertDatabaseCount('paquete_servicio', 0);
    }

    public function test_el_formulario_permite_corregir_el_guia_y_reintentar_tras_un_conflicto(): void
    {
        $otroUsuario = Usuario::create(['correo' => 'otroguia@caminodelinca.test', 'password' => 'guia1234', 'rol' => Rol::Guia]);
        $otroGuia = Guia::create(['id_usuario' => $otroUsuario->id_usuario, 'nombre' => 'Luis', 'apellido' => 'Quispe']);
        $this->ocuparGuiaDespuesDeLaValidacion();

        $formulario = $this->formulario()
            ->fillForm($this->datosValidos(excursiones: [$this->salida('2027-03-01')]))
            ->call('create')
            ->assertNotified('No se creó el paquete')
            ->assertSet('record', null);

        $salidaCorregida = $this->salida('2027-03-01');
        $salidaCorregida['id_guia'] = $otroGuia->id_usuario;

        $formulario->fillForm($this->datosValidos(excursiones: [$salidaCorregida]))
            ->call('create')
            ->assertStatus(200)
            ->assertHasNoFormErrors()
            ->assertRedirect('/admin/paquetes');

        $paquete = Paquete::where('nombre', 'Camino Inca Clásico')->firstOrFail();
        $this->assertSame(1, $paquete->excursiones()->count());
        $this->assertEquals($otroGuia->id_usuario, $paquete->excursiones()->firstOrFail()->id_guia);
        $this->assertSame(2, $paquete->servicios()->count());
    }

    public function test_una_salida_cercana_se_crea_y_se_consulta_solo_en_el_panel(): void
    {
        $this->formulario()
            ->fillForm($this->datosValidos(excursiones: [$this->salida('2026-10-26')]))
            ->call('create')->assertHasNoFormErrors();

        $paquete = Paquete::where('nombre', 'Camino Inca Clásico')->firstOrFail();
        $this->get('/admin/paquetes/'.$paquete->id_paquete)->assertOk()->assertSee('26/10/2026');
        $this->get('/paquetes')->assertDontSee('26/10/2026');
        $this->get('/paquetes/'.$paquete->id_paquete)->assertOk()->assertDontSee('26/10/2026');
        Livewire::test('realizar-reserva', ['idPaquete' => $paquete->id_paquete, 'fechaSalida' => '2026-10-26'])
            ->assertSet('impedimento', 'anticipacion');
    }

    public function test_el_guia_y_el_visitante_no_acceden_al_alta_ni_al_listado(): void
    {
        $this->get('/admin/paquetes')->assertRedirect('/admin/login');
        $this->get('/admin/paquetes/crear')->assertRedirect('/admin/login');
        $this->actingAs($this->usuarioGuia)->get('/admin/paquetes')->assertForbidden();
        Livewire::test(CreatePaquete::class)->assertForbidden();
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

    private function ocuparGuiaDespuesDeLaValidacion(): void
    {
        $otro = Paquete::crearPaquete([
            'id_recorrido' => $this->recorrido->id_recorrido,
            'nombre' => 'Camino Inca Extendido',
            'precio_base' => 950,
            'costo_noche_extra_cusco' => 60,
            'costo_equipo_camping' => 45,
            'cantidad_porteadores' => 8,
        ]);
        $yaOcupado = false;

        // Se inserta después de que la primera consulta leyó al guía libre. La segunda comprobación debe verlo ocupado.
        DB::listen(function (QueryExecuted $consulta) use ($otro, &$yaOcupado) {
            if ($yaOcupado || ! str_contains($consulta->sql, 'from "excursion"')) {
                return;
            }

            $yaOcupado = true;
            Excursion::create([
                'id_paquete' => $otro->id_paquete,
                'id_guia' => $this->guia->id_usuario,
                'fecha_salida' => '2027-03-01',
                'cupo' => 12,
            ]);
        });
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
