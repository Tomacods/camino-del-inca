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
use Livewire\Livewire;
use Tests\TestCase;

// Prueba de unidad de caja negra, por partición equivalente, del formulario de CU-14 Realizar Reserva
// (componente realizar-reserva). Cada caso CN-xx del Documento de Estrategia de Pruebas es un método: el CN-01 cubre
// todas las clases válidas y cada uno de los demás cambia un solo dato por un valor de una clase inválida, con el resto
// de los datos válidos. Los números entre paréntesis son los de la tabla de clases de equivalencia del documento.
class ParticionEquivalenteReservaTest extends TestCase
{
    use RefreshDatabase;

    private Paquete $paquete;

    protected function setUp(): void
    {
        parent::setUp();

        // Hoy es el 06/10/2026: la salida del 18/01/2027 cumple los 3 meses de anticipación.
        $this->travelTo(Carbon::parse('2026-10-06 10:00:00'));

        $recorrido = Recorrido::create(['nombre' => 'Camino Inca de 4 días', 'duracion_dias' => 4, 'cantidad_campings' => 3]);

        $this->paquete = Paquete::create([
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

        // Cupo disponible 3: la cantidad válida va de 1 a 3.
        Excursion::create([
            'id_paquete' => $this->paquete->id_paquete,
            'id_guia' => $usuario->id_usuario,
            'fecha_salida' => '2027-01-18',
            'cupo' => 3,
            'plazas_retenidas' => 0,
        ]);
    }

    // (1) (5) (9) (12) (15) (20) (21) (22): todos los datos válidos.
    public function test_cn01_datos_validos_en_todas_las_clases(): void
    {
        $componente = $this->completarPrimerIntegrante(cantidad: 2, equipoCamping: true)
            ->assertHasNoErrors()
            ->assertSet('integranteActual', 1);

        $this->cargarIntegrante($componente, 'Bruno', 'Gómez', 'BBB222', equipoCamping: false)
            ->assertHasNoErrors()
            ->assertSet('paso', 2)
            ->call('sumarNoche', 'antes')
            ->call('sumarNoche', 'despues')
            ->assertHasNoErrors()
            ->assertSet('nochesExtraAntes', 1)
            ->assertSet('nochesExtraDespues', 1)
            ->assertSee('Ana Pérez')
            ->assertSee('Bruno Gómez');

        $integrantes = $componente->get('integrantes');
        $this->assertTrue($integrantes[0]['equipoCamping']);
        $this->assertFalse($integrantes[1]['equipoCamping']);
        $this->assertDatabaseCount('reserva', 0);
    }

    // (2) Correo vacío.
    public function test_cn02_correo_vacio(): void
    {
        $this->rechazaPrimerIntegrante('correoElectronico', 'Ingresá tu correo electrónico.', correo: '');
    }

    // (3) Correo sin formato de correo.
    public function test_cn03_correo_sin_formato_de_correo(): void
    {
        $this->rechazaPrimerIntegrante('correoElectronico', 'El correo no tiene un formato válido.', correo: 'ana.perez@');
    }

    // (4) Correo con formato de correo pero de 101 caracteres.
    public function test_cn04_correo_de_mas_de_100_caracteres(): void
    {
        $correo = str_repeat('a', 40).'@'.str_repeat('b', 56).'.com';
        $this->assertSame(101, strlen($correo));

        $this->rechazaPrimerIntegrante('correoElectronico', 'El correo no tiene un formato válido.', correo: $correo);
    }

    // (6) Cantidad de integrantes menor que 1.
    public function test_cn05_cantidad_menor_que_uno(): void
    {
        $this->rechazaPrimerIntegrante('cantidadIntegrantes', 'Indicá cuántas personas viajan.', cantidad: 0);
    }

    // (7) Cantidad de integrantes mayor que el cupo disponible (3).
    public function test_cn06_cantidad_mayor_que_el_cupo_disponible(): void
    {
        $this->rechazaPrimerIntegrante(
            'cantidadIntegrantes',
            'Quedan 3 lugares en esta salida. Elegí otra fecha u otro paquete si necesitás más.',
            cantidad: 4,
        );
    }

    // (8) Cantidad de integrantes que no es un número entero.
    public function test_cn07_cantidad_que_no_es_un_numero_entero(): void
    {
        $this->rechazaPrimerIntegrante('cantidadIntegrantes', 'Indicá cuántas personas viajan.', cantidad: '1.5');
    }

    // (10) Nombre vacío.
    public function test_cn08_nombre_vacio(): void
    {
        $this->rechazaPrimerIntegrante('nombre', 'Ingresá el nombre.', nombre: '');
    }

    // (11) Nombre de 51 caracteres.
    public function test_cn09_nombre_de_mas_de_50_caracteres(): void
    {
        $this->rechazaPrimerIntegrante('nombre', 'El nombre no puede tener más de 50 caracteres.', nombre: str_repeat('a', 51));
    }

    // (13) Apellido vacío.
    public function test_cn10_apellido_vacio(): void
    {
        $this->rechazaPrimerIntegrante('apellido', 'Ingresá el apellido.', apellido: '');
    }

    // (14) Apellido de 51 caracteres.
    public function test_cn11_apellido_de_mas_de_50_caracteres(): void
    {
        $this->rechazaPrimerIntegrante('apellido', 'El apellido no puede tener más de 50 caracteres.', apellido: str_repeat('a', 51));
    }

    // (16) Documento o pasaporte vacío.
    public function test_cn12_documento_vacio(): void
    {
        $this->rechazaPrimerIntegrante('documentoPasaporte', 'El documento o pasaporte es obligatorio.', documento: '');
    }

    // (17) Documento con un carácter que no es letra ni número.
    public function test_cn13_documento_con_caracteres_no_permitidos(): void
    {
        $this->rechazaPrimerIntegrante(
            'documentoPasaporte',
            'El documento o pasaporte sólo puede tener letras y números, hasta 20.',
            documento: 'AB-123',
        );
    }

    // (18) Documento de 21 letras y números.
    public function test_cn14_documento_de_mas_de_20_caracteres(): void
    {
        $documento = 'AB'.str_repeat('1', 19);
        $this->assertSame(21, strlen($documento));

        $this->rechazaPrimerIntegrante(
            'documentoPasaporte',
            'El documento o pasaporte sólo puede tener letras y números, hasta 20.',
            documento: $documento,
        );
    }

    // (19) Documento repetido en el grupo: el segundo integrante repite el del primero, con otras mayúsculas.
    public function test_cn15_documento_repetido_en_el_grupo(): void
    {
        $componente = $this->completarPrimerIntegrante(cantidad: 2)->assertSet('integranteActual', 1);

        $this->cargarIntegrante($componente, 'Bruno', 'Gómez', 'aaa111')
            ->assertOnlyHasErrors(['documentoPasaporte'])
            ->assertSee('Ya cargaste un integrante con ese documento.')
            ->assertSet('integranteActual', 1)
            ->assertSet('paso', 1);

        $this->assertCount(1, $componente->get('integrantes'));
    }

    // (23) Más de 2 noches extra en total.
    public function test_cn16_mas_de_dos_noches_extra(): void
    {
        $componente = $this->completarPrimerIntegrante(cantidad: 2);

        $this->cargarIntegrante($componente, 'Bruno', 'Gómez', 'BBB222')
            ->assertSet('paso', 2)
            ->call('sumarNoche', 'antes')
            ->call('sumarNoche', 'antes')
            ->assertHasNoErrors()
            ->call('sumarNoche', 'despues')
            ->assertOnlyHasErrors(['nochesExtra'])
            ->assertSee('Podés sumar hasta 2 noches extra en total.')
            ->assertSet('nochesExtraAntes', 2)
            ->assertSet('nochesExtraDespues', 0);
    }

    // Carga el primer integrante con un solo dato inválido y comprueba que sólo ese campo tenga error, con su mensaje,
    // y que el formulario no avance ni guarde al integrante.
    private function rechazaPrimerIntegrante(string $campo, string $mensaje, ...$datos): void
    {
        $this->completarPrimerIntegrante(...$datos)
            ->assertOnlyHasErrors([$campo])
            ->assertSee($mensaje)
            ->assertSet('integranteActual', 0)
            ->assertSet('paso', 1)
            ->assertSet('integrantes', []);

        $this->assertDatabaseCount('reserva', 0);
    }

    private function completarPrimerIntegrante(
        string $correo = 'ana.perez@mail.com',
        int|string $cantidad = 1,
        string $nombre = 'Ana',
        string $apellido = 'Pérez',
        string $documento = 'AAA111',
        bool $equipoCamping = false,
    ) {
        $componente = Livewire::test('realizar-reserva', ['idPaquete' => $this->paquete->id_paquete, 'fechaSalida' => '2027-01-18'])
            ->set('correoElectronico', $correo)
            ->set('cantidadIntegrantes', $cantidad);

        return $this->cargarIntegrante($componente, $nombre, $apellido, $documento, $equipoCamping);
    }

    private function cargarIntegrante($componente, string $nombre, string $apellido, string $documento, bool $equipoCamping = false)
    {
        return $componente
            ->set('nombre', $nombre)
            ->set('apellido', $apellido)
            ->set('documentoPasaporte', $documento)
            ->set('equipoCamping', $equipoCamping)
            ->call('siguienteIntegrante');
    }
}
