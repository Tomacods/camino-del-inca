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

// R-01 y R-02: los guías que se ofrecen para una salida al crear o modificar un paquete (Guia::disponiblesPara).
class GuiasDisponiblesTest extends TestCase
{
    use RefreshDatabase;

    private Guia $rosa;

    private Guia $luis;

    private Recorrido $recorrido;

    private Paquete $otroPaquete;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-21');

        $this->rosa = $this->crearGuia('rosa@caminodelinca.test', 'Rosa', 'Quispe');
        $this->luis = $this->crearGuia('luis@caminodelinca.test', 'Luis', 'Mamani');

        $this->recorrido = Recorrido::create(['nombre' => 'Camino Inca de 4 días', 'duracion_dias' => 4, 'cantidad_campings' => 3]);
        $this->otroPaquete = Paquete::crearPaquete([
            'id_recorrido' => $this->recorrido->id_recorrido,
            'nombre' => 'Camino Inca Extendido',
            'precio_base' => 950,
            'costo_noche_extra_cusco' => 60,
            'costo_equipo_camping' => 45,
            'cantidad_porteadores' => 8,
        ]);
    }

    public function test_sin_excursiones_todos_los_guias_estan_disponibles_ordenados_por_apellido(): void
    {
        $this->assertSame(
            [$this->luis->id_usuario, $this->rosa->id_usuario],
            $this->disponiblesPara('2027-03-01'),
        );
    }

    // La excursión de Rosa ocupa del lunes 01/03 al jueves 04/03 (4 días, el último incluido).
    public function test_no_ofrece_un_guia_con_otra_excursion_superpuesta(): void
    {
        $this->crearExcursion($this->rosa, '2027-03-01');

        $this->assertSame([$this->luis->id_usuario], $this->disponiblesPara('2027-03-01'));
        $this->assertSame([$this->luis->id_usuario], $this->disponiblesPara('2027-03-04'));
        $this->assertSame([$this->luis->id_usuario], $this->disponiblesPara('2027-02-26'));
    }

    // Los límites: una salida que empieza el día después del último día de la otra, o que termina el día antes de que
    // la otra empiece (del 25/02 al 28/02).
    public function test_ofrece_al_guia_si_su_otra_excursion_termina_antes_o_empieza_despues(): void
    {
        $this->crearExcursion($this->rosa, '2027-03-01');

        $this->assertContains($this->rosa->id_usuario, $this->disponiblesPara('2027-03-05'));
        $this->assertContains($this->rosa->id_usuario, $this->disponiblesPara('2027-03-08'));
        $this->assertContains($this->rosa->id_usuario, $this->disponiblesPara('2027-02-25'));
    }

    // Como en buscarConflictoDeGuia: las excursiones de un paquete dado de baja no ocupan al guía.
    public function test_las_excursiones_de_un_paquete_inactivo_no_ocupan_al_guia(): void
    {
        $this->crearExcursion($this->rosa, '2027-03-01');
        $this->otroPaquete->update(['estado' => EstadoPaquete::Inactivo]);

        $this->assertContains($this->rosa->id_usuario, $this->disponiblesPara('2027-03-01'));
    }

    // Al modificar una excursión, la propia no cuenta: su guía sigue en la lista.
    public function test_la_excursion_que_se_modifica_no_ocupa_a_su_guia(): void
    {
        $excursion = $this->crearExcursion($this->rosa, '2027-03-01');

        $this->assertContains($this->rosa->id_usuario, $this->disponiblesPara('2027-03-01', $excursion->id_excursion));
    }

    public function test_excluir_una_excursion_no_libera_al_guia_de_las_otras(): void
    {
        $excursion = $this->crearExcursion($this->rosa, '2027-03-08');
        $this->crearExcursion($this->rosa, '2027-03-01');

        $this->assertNotContains($this->rosa->id_usuario, $this->disponiblesPara('2027-03-01', $excursion->id_excursion));
    }

    private function disponiblesPara(string $fechaSalida, ?int $idExcursionExcluida = null): array
    {
        return Guia::disponiblesPara(Carbon::parse($fechaSalida), $this->recorrido->duracion_dias, $idExcursionExcluida)
            ->pluck('id_usuario')
            ->all();
    }

    private function crearExcursion(Guia $guia, string $fechaSalida): Excursion
    {
        return Excursion::create([
            'id_paquete' => $this->otroPaquete->id_paquete,
            'id_guia' => $guia->id_usuario,
            'fecha_salida' => $fechaSalida,
            'cupo' => 12,
        ]);
    }

    private function crearGuia(string $correo, string $nombre, string $apellido): Guia
    {
        $usuario = Usuario::create(['correo' => $correo, 'password' => 'guia1234', 'rol' => Rol::Guia]);

        return Guia::create(['id_usuario' => $usuario->id_usuario, 'nombre' => $nombre, 'apellido' => $apellido]);
    }
}
