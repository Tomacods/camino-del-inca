<?php

namespace Tests\Feature;

use App\Enums\EstadoPaquete;
use App\Enums\Rol;
use App\Jobs\LiberarCupoRetenido;
use App\Models\Excursion;
use App\Models\Guia;
use App\Models\Paquete;
use App\Models\Recorrido;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LiberarCupoRetenidoTest extends TestCase
{
    use RefreshDatabase;

    private Excursion $excursion;

    protected function setUp(): void
    {
        parent::setUp();

        // 5 plazas retenidas: 2 de la retención que se prueba y 3 de otros clientes. Así se nota si se descuenta de más.
        $this->excursion = $this->crearExcursion(plazasRetenidas: 5);
    }

    public function test_libera_las_plazas_de_la_retencion(): void
    {
        (new LiberarCupoRetenido($this->excursion->id_excursion, 2, 'retencion-1'))->handle();

        $this->assertSame(3, $this->excursion->fresh()->plazas_retenidas);
    }

    public function test_la_misma_retencion_se_libera_una_sola_vez(): void
    {
        (new LiberarCupoRetenido($this->excursion->id_excursion, 2, 'retencion-1'))->handle();
        (new LiberarCupoRetenido($this->excursion->id_excursion, 2, 'retencion-1'))->handle();

        $this->assertSame(3, $this->excursion->fresh()->plazas_retenidas);
    }

    public function test_dos_retenciones_de_la_misma_excursion_se_liberan_cada_una(): void
    {
        (new LiberarCupoRetenido($this->excursion->id_excursion, 2, 'retencion-1'))->handle();
        (new LiberarCupoRetenido($this->excursion->id_excursion, 2, 'retencion-2'))->handle();

        $this->assertSame(1, $this->excursion->fresh()->plazas_retenidas);
    }

    private function crearExcursion(int $plazasRetenidas): Excursion
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
        Guia::create(['id_usuario' => $usuario->id_usuario, 'nombre' => 'Rosa', 'apellido' => 'Quispe']);

        return Excursion::create([
            'id_paquete' => $paquete->id_paquete,
            'id_guia' => $usuario->id_usuario,
            'fecha_salida' => '2027-01-18',
            'cupo' => 12,
            'plazas_retenidas' => $plazasRetenidas,
        ]);
    }
}
