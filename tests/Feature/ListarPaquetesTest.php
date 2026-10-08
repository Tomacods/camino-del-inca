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
use Tests\TestCase;

// CU-05 Listar Paquetes: curso normal (pasos 1 y 2), flujo alternativo A3 y reglas de negocio del listado.
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
        $this->crearExcursion($paquete, '2027-01-04');

        $respuesta = $this->get('/paquetes');

        $respuesta->assertOk();
        $respuesta->assertSee('Camino Inca Clásico');
        $respuesta->assertSee('Camino Inca de 4 días · 4 días');
        $respuesta->assertSee('USD 750');
        $respuesta->assertSee('04/01/2027');
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

    public function test_muestra_solo_las_salidas_futuras_que_tienen_cupo(): void
    {
        $paquete = $this->crearPaquete('Camino Inca Clásico');
        $this->crearExcursion($paquete, '2026-09-07');
        $this->crearExcursion($paquete, '2027-01-04');
        $this->crearExcursion($paquete, '2027-02-01', plazasRetenidas: 12);

        $respuesta = $this->get('/paquetes');

        $respuesta->assertSee('04/01/2027');
        $respuesta->assertDontSee('07/09/2026');
        $respuesta->assertDontSee('01/02/2027');
    }

    public function test_cada_salida_lleva_al_formulario_de_reserva(): void
    {
        $paquete = $this->crearPaquete('Camino Inca Clásico');
        $this->crearExcursion($paquete, '2027-01-04');

        $this->get('/paquetes')
            ->assertSee('href="/reservar/'.$paquete->id_paquete.'/2027-01-04"', false);
    }

    public function test_avisa_cuando_no_hay_paquetes_activos(): void
    {
        $this->get('/paquetes')->assertSee('Por ahora no hay paquetes disponibles');
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
