<?php

namespace Tests\Feature;

use App\Models\Recorrido;
use Database\Seeders\RecorridoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HitosDelCaminoTest extends TestCase
{
    use RefreshDatabase;

    // Los hitos que muestra la línea de tiempo de la portada, en orden.
    private const HITOS = [
        'Kilómetro 82 (Piscacucho)',
        'Wayllabamba',
        'Abra Warmiwañusca',
        'Wiñay Wayna',
        'Machu Picchu',
    ];

    public function test_la_portada_muestra_los_cinco_hitos_en_orden_y_termina_en_el_boton(): void
    {
        $respuesta = $this->get('/');

        $respuesta->assertOk();
        $respuesta->assertSeeInOrder([...self::HITOS, 'Ver fechas de salida']);
        $respuesta->assertSee('4.215');
    }

    // Si alguien renombra o reordena una etapa en el seeder, la línea de tiempo tiene que acompañar el cambio.
    public function test_los_hitos_son_etapas_del_recorrido_de_4_dias_en_el_mismo_orden(): void
    {
        $this->seed(RecorridoSeeder::class);

        $etapas = Recorrido::where('nombre', 'Camino Inca de 4 días')->firstOrFail()->etapas;
        $ordenDeCadaHito = collect(self::HITOS)->map(fn ($nombre) => $etapas->firstWhere('nombre', $nombre)?->orden);

        $this->assertNotContains(null, $ordenDeCadaHito);
        $this->assertSame($ordenDeCadaHito->sort()->values()->all(), $ordenDeCadaHito->all());
    }

    // Cada hito y el botón final tienen su foto en dos recortes; una ruta mal escrita deja el fondo vacío sin dar error.
    public function test_las_fotos_del_recorrido_existen_y_llevan_sus_creditos(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        preg_match_all('#/(img/recorrido/[a-z0-9-]+-(?:horizontal|vertical)\.webp)#', $html, $coincidencias);
        $fotos = array_unique($coincidencias[1]);

        $this->assertCount(12, $fotos);
        foreach ($fotos as $foto) {
            $this->assertFileExists(public_path($foto));
        }
        $this->assertStringContainsString('Pexels', $html);
        $this->assertStringContainsString('Pixabay', $html);
    }

    public function test_el_boton_lleva_a_los_paquetes_o_a_la_direccion_que_se_le_indica(): void
    {
        $this->blade('<x-hitos-del-camino />')->assertSee('href="/paquetes"', false);
        $this->blade('<x-hitos-del-camino href="#fechas" />')->assertSee('href="#fechas"', false);
    }
}
