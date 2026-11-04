<?php

namespace Tests\Feature;

use App\Enums\EstadoPaquete;
use App\Enums\Rol;
use App\Models\Excursion;
use App\Models\Guia;
use App\Models\Paquete;
use App\Models\Recorrido;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

// CU-05 Listar Paquetes: curso normal (pasos 1 a 4), flujos alternativos A1 a A4, y reglas de negocio del listado.
class ListarPaquetesTest extends TestCase
{
    use RefreshDatabase;

    private Recorrido $recorrido;

    private Guia $guia;

    protected function setUp(): void
    {
        parent::setUp();

        // Fecha fija, para que las salidas «futuras» lo sigan siendo cuando se corra la prueba más adelante.
        Carbon::setTestNow('2026-10-06');

        $this->recorrido = Recorrido::create([
            'nombre' => 'Camino Inca de 4 días',
            'duracion_dias' => 4,
            'cantidad_campings' => 3,
        ]);

        $usuario = Usuario::create([
            'correo' => 'guia@caminodelinca.test',
            'password' => 'guia1234',
            'rol' => Rol::Guia,
        ]);

        $this->guia = Guia::create(['id_usuario' => $usuario->id_usuario, 'nombre' => 'Rosa', 'apellido' => 'Quispe']);
    }

    public function test_muestra_los_datos_resumen_de_cada_paquete(): void
    {
        $paquete = $this->crearPaquete('Camino Inca Clásico');
        $this->crearExcursion($paquete, '2027-02-15');

        $respuesta = $this->get('/paquetes');

        $respuesta->assertOk();
        $respuesta->assertSee('Camino Inca Clásico');
        $respuesta->assertSee('Camino Inca de 4 días');
        $respuesta->assertDontSee('Camino Inca de 4 días · 4 días');
        $respuesta->assertSee('USD 750,00');
        $respuesta->assertSee('15/02/2027');
    }

    public function test_muestra_solo_los_paquetes_activos(): void
    {
        $this->crearPaquete('Camino Inca Clásico');
        $this->crearPaquete('Paquete dado de baja', estado: EstadoPaquete::Inactivo);

        $respuesta = $this->get('/paquetes');

        $respuesta->assertSee('Camino Inca Clásico');
        $respuesta->assertDontSee('Paquete dado de baja');
    }

    public function test_ordena_los_paquetes_del_mas_nuevo_al_mas_antiguo(): void
    {
        $this->crearPaquete('Paquete viejo', fechaCreacion: '2026-01-10');
        $this->crearPaquete('Paquete nuevo', fechaCreacion: '2026-09-01');

        $this->get('/paquetes')->assertSeeInOrder(['Paquete nuevo', 'Paquete viejo']);
    }

    // Disponibles: a 3 meses o más (como pide Realizar Reserva) y con cupo. La fecha fija es el 06/10/2026, así que el
    // límite es el 06/01/2027.
    public function test_muestra_solo_las_salidas_que_se_pueden_reservar(): void
    {
        $paquete = $this->crearPaquete('Camino Inca Clásico');
        $this->crearExcursion($paquete, '2026-09-07');
        $this->crearExcursion($paquete, '2026-12-14');
        $this->crearExcursion($paquete, '2027-01-06');
        $this->crearExcursion($paquete, '2027-02-15');
        $this->crearExcursion($paquete, '2027-02-01', plazasRetenidas: 12);

        $respuesta = $this->get('/paquetes');

        $respuesta->assertSee('06/01/2027');
        $respuesta->assertSee('15/02/2027');
        $respuesta->assertDontSee('07/09/2026');
        $respuesta->assertDontSee('14/12/2026');
        $respuesta->assertDontSee('01/02/2027');
    }

    public function test_cada_salida_lleva_al_formulario_de_reserva(): void
    {
        $paquete = $this->crearPaquete('Camino Inca Clásico');
        $this->crearExcursion($paquete, '2027-02-15');

        $this->get('/paquetes')
            ->assertSee('href="/reservar/'.$paquete->id_paquete.'/2027-02-15"', false);
    }

    // Flujo alternativo A2.
    public function test_ver_el_paquete_lleva_a_su_detalle(): void
    {
        $paquete = $this->crearPaquete('Camino Inca Clásico');

        $this->get('/paquetes')->assertSee('href="/paquetes/'.$paquete->id_paquete.'"', false);
    }

    public function test_avisa_cuando_no_hay_paquetes_activos(): void
    {
        $this->get('/paquetes')->assertSee('Por ahora no hay paquetes disponibles');
    }

    /* ------------------------------ Búsqueda ------------------------------ */

    public function test_busca_por_nombre_sin_importar_mayusculas_ni_tildes(): void
    {
        $this->crearPaquete('Camino Inca Clásico');
        $this->crearPaquete('Camino Inca Corto');

        $this->buscar('CLASICO')
            ->assertHasNoErrors()
            ->assertSee('Camino Inca Clásico')
            ->assertDontSee('Camino Inca Corto');
    }

    public function test_busca_por_una_sola_fecha_de_salida(): void
    {
        $clasico = $this->crearPaquete('Camino Inca Clásico');
        $corto = $this->crearPaquete('Camino Inca Corto');
        $this->crearExcursion($clasico, '2027-02-15');
        $this->crearExcursion($corto, '2027-02-22');

        $this->buscar('', '2027-02-15', '2027-02-15')
            ->assertHasNoErrors()
            ->assertSee('Camino Inca Clásico')
            ->assertDontSee('Camino Inca Corto');
    }

    public function test_busca_por_un_intervalo_de_fechas(): void
    {
        $this->crearExcursion($this->crearPaquete('Camino Inca Clásico'), '2027-02-15');
        $this->crearExcursion($this->crearPaquete('Camino Inca Corto'), '2027-03-22');
        $this->crearExcursion($this->crearPaquete('Camino Inca Extendido'), '2027-05-03');

        $this->buscar('', '2027-02-01', '2027-02-28')
            ->assertHasNoErrors()
            ->assertSee('Camino Inca Clásico')
            ->assertDontSee('Camino Inca Corto')
            ->assertDontSee('Camino Inca Extendido');
    }

    // Las fechas de los extremos cuentan: 01/02 y 28/02 se incluyen; 01/03 queda afuera.
    public function test_el_intervalo_incluye_los_dos_extremos(): void
    {
        $this->crearExcursion($this->crearPaquete('Camino Inca Clásico'), '2027-02-01');
        $this->crearExcursion($this->crearPaquete('Camino Inca Corto'), '2027-02-28');
        $this->crearExcursion($this->crearPaquete('Camino Inca Extendido'), '2027-03-01');

        $this->buscar('', '2027-02-01', '2027-02-28')
            ->assertSee('Camino Inca Clásico')
            ->assertSee('Camino Inca Corto')
            ->assertDontSee('Camino Inca Extendido');
    }

    public function test_solo_desde_busca_las_salidas_de_esa_fecha_en_adelante(): void
    {
        $this->crearExcursion($this->crearPaquete('Camino Inca Clásico'), '2027-02-15');
        $this->crearExcursion($this->crearPaquete('Camino Inca Corto'), '2027-03-22');

        $this->buscar('', '2027-03-01', '')
            ->assertSee('Camino Inca Corto')
            ->assertDontSee('Camino Inca Clásico');
    }

    public function test_solo_hasta_busca_las_salidas_hasta_esa_fecha(): void
    {
        $this->crearExcursion($this->crearPaquete('Camino Inca Clásico'), '2027-02-15');
        $this->crearExcursion($this->crearPaquete('Camino Inca Corto'), '2027-03-22');

        $this->buscar('', '', '2027-02-28')
            ->assertSee('Camino Inca Clásico')
            ->assertDontSee('Camino Inca Corto');
    }

    public function test_combina_el_nombre_con_el_intervalo(): void
    {
        $this->crearExcursion($this->crearPaquete('Camino Inca Clásico'), '2027-02-15');
        $this->crearExcursion($this->crearPaquete('Camino Inca Corto'), '2027-02-22');

        $this->buscar('corto', '2027-02-01', '2027-02-28')
            ->assertSee('Camino Inca Corto')
            ->assertDontSee('Camino Inca Clásico');
    }

    public function test_una_fecha_sin_cupo_no_coincide(): void
    {
        $paquete = $this->crearPaquete('Camino Inca Clásico');
        $this->crearExcursion($paquete, '2027-02-01', plazasRetenidas: 12);

        $this->buscar('', '2027-02-01', '2027-02-01')
            ->assertSee('No encontramos paquetes para la salida del 01/02/2027');
    }

    public function test_una_fecha_a_menos_de_tres_meses_no_coincide(): void
    {
        $paquete = $this->crearPaquete('Camino Inca Clásico');
        $this->crearExcursion($paquete, '2026-12-14');

        $this->buscar('', '2026-12-14', '2026-12-14')
            ->assertSee('No encontramos paquetes para la salida del 14/12/2026');
    }

    public function test_una_fecha_que_ya_paso_no_coincide(): void
    {
        $paquete = $this->crearPaquete('Camino Inca Clásico');
        $this->crearExcursion($paquete, '2026-09-07');

        $this->buscar('', '2026-09-01', '2026-09-30')
            ->assertSee('No encontramos paquetes para las salidas entre el 01/09/2026 y el 30/09/2026');
    }

    public function test_sin_criterio_muestra_todos_los_paquetes(): void
    {
        $this->crearPaquete('Camino Inca Clásico');
        $this->crearPaquete('Camino Inca Corto');

        $this->buscar('   ')
            ->assertHasNoErrors()
            ->assertSee('Camino Inca Clásico')
            ->assertSee('Camino Inca Corto');
    }

    // Flujo alternativo A1.
    public function test_avisa_cuando_ningun_paquete_coincide(): void
    {
        $this->crearPaquete('Camino Inca Clásico');

        $this->buscar('Salkantay')
            ->assertSee('No encontramos paquetes para «Salkantay»')
            ->assertDontSee('Camino Inca Clásico');
    }

    // Flujo alternativo A4: el error se informa y el listado queda como estaba.
    public function test_rechaza_una_fecha_con_formato_invalido(): void
    {
        $this->crearPaquete('Camino Inca Clásico');

        $this->buscar('', '2027-02-31')
            ->assertHasErrors(['desde' => 'date_format'])
            ->assertSee('Camino Inca Clásico');

        $this->buscar('', '15/02/2027')->assertHasErrors(['desde' => 'date_format']);
        $this->buscar('', '', '15/2/2027')->assertHasErrors(['hasta' => 'date_format']);
    }

    public function test_rechaza_un_hasta_anterior_al_desde(): void
    {
        $this->crearPaquete('Camino Inca Clásico');

        $this->buscar('', '2027-02-28', '2027-02-01')
            ->assertHasErrors(['hasta'])
            ->assertSee('Camino Inca Clásico');
    }

    // La búsqueda aplicada sólo la cambia buscarPaquetes(), después de validar el criterio.
    public function test_la_busqueda_aplicada_no_se_puede_cambiar_desde_el_navegador(): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::test('listar-paquetes')->set('busquedaDesde', '123');
    }

    public function test_ver_todos_vuelve_al_listado_completo(): void
    {
        $this->crearPaquete('Camino Inca Clásico');
        $this->crearPaquete('Camino Inca Corto');

        $this->buscar('Clásico')
            ->call('verTodos')
            ->assertSet('criterio', '')
            ->assertSet('busqueda', '')
            ->assertSee('Camino Inca Corto');
    }

    // Escribe los criterios y toca «Buscar», como lo haría el cliente.
    private function buscar(string $nombre = '', string $desde = '', string $hasta = '')
    {
        return Livewire::test('listar-paquetes')
            ->set('criterio', $nombre)
            ->set('desde', $desde)
            ->set('hasta', $hasta)
            ->call('buscarPaquetes');
    }

    private function crearPaquete(
        string $nombre,
        EstadoPaquete $estado = EstadoPaquete::Activo,
        string $fechaCreacion = '2026-10-01',
    ): Paquete {
        return Paquete::create([
            'id_recorrido' => $this->recorrido->id_recorrido,
            'nombre' => $nombre,
            'precio_base' => 750,
            'costo_noche_extra_cusco' => 60,
            'costo_equipo_camping' => 45,
            'cantidad_porteadores' => 6,
            'estado' => $estado,
            'fecha_creacion' => $fechaCreacion,
        ]);
    }

    private function crearExcursion(Paquete $paquete, string $fechaSalida, int $plazasRetenidas = 0): Excursion
    {
        return Excursion::create([
            'id_paquete' => $paquete->id_paquete,
            'id_guia' => $this->guia->id_usuario,
            'fecha_salida' => $fechaSalida,
            'cupo' => 12,
            'plazas_retenidas' => $plazasRetenidas,
        ]);
    }
}
