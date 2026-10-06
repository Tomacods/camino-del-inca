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

class RealizarReservaTest extends TestCase
{
    use RefreshDatabase;

    private Paquete $paquete;

    private int $idGuia;

    protected function setUp(): void
    {
        parent::setUp();

        // Hoy es el 06/10/2026: con 3 meses de anticipación, se puede reservar desde la salida del 06/01/2027.
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
        $this->idGuia = $usuario->id_usuario;
    }

    public function test_el_curso_normal_llega_al_resumen_sin_guardar_nada_en_la_base(): void
    {
        $excursion = $this->crearExcursion('2027-01-18');

        $componente = $this->abrir('2027-01-18')
            ->assertSee('Camino Inca Clásico')
            ->assertSee('18/01/2027')
            ->assertSee('Quedan 12 lugares')
            ->assertSee('Lleva equipo de camping (USD 45)')
            ->set('correoElectronico', 'ana.perez@mail.com')
            ->set('cantidadIntegrantes', 2);

        $this->cargarIntegrante($componente, 'Ana', 'Pérez', 'AAA111', equipoCamping: true)
            ->assertHasNoErrors()
            ->assertSee('Integrante 2 de 2');

        $this->cargarIntegrante($componente, 'Bruno', 'Gómez', 'BBB222')
            ->assertHasNoErrors()
            ->assertSet('paso', 2)
            ->call('sumarNoche', 'antes')
            ->call('sumarNoche', 'despues')
            ->assertHasNoErrors()
            ->assertSee('Tu selección')
            ->assertSee('Ana Pérez')
            ->assertSee('Bruno Gómez')
            ->assertSeeInOrder(['Llevan equipo de camping', '1 de 2'])
            ->assertSee('1 antes del recorrido y 1 después');

        $this->assertDatabaseCount('reserva', 0);
        $this->assertDatabaseCount('excursionista', 0);
        $this->assertSame(0, $excursion->fresh()->plazas_retenidas);
    }

    public function test_anterior_vuelve_al_integrante_ya_cargado(): void
    {
        $this->crearExcursion('2027-01-18');
        $componente = $this->abrir('2027-01-18')
            ->set('correoElectronico', 'ana.perez@mail.com')
            ->set('cantidadIntegrantes', 2);

        $this->cargarIntegrante($componente, 'Ana', 'Pérez', 'AAA111')
            ->call('anteriorIntegrante')
            ->assertSet('integranteActual', 0)
            ->assertSet('nombre', 'Ana')
            ->assertSet('documentoPasaporte', 'AAA111');
    }

    public function test_si_baja_la_cantidad_se_descartan_los_integrantes_que_sobran(): void
    {
        $this->crearExcursion('2027-01-18');
        $componente = $this->abrir('2027-01-18')
            ->set('correoElectronico', 'ana.perez@mail.com')
            ->set('cantidadIntegrantes', 3);

        $this->cargarIntegrante($componente, 'Ana', 'Pérez', 'AAA111');
        $this->cargarIntegrante($componente, 'Bruno', 'Gómez', 'BBB222')
            ->set('cantidadIntegrantes', 1)
            ->assertSet('integranteActual', 0)
            ->assertSet('nombre', 'Ana')
            ->assertCount('integrantes', 1);
    }

    public function test_avisa_si_la_salida_no_cumple_la_anticipacion_minima(): void
    {
        $this->crearExcursion('2026-12-14');

        $this->abrir('2026-12-14')
            ->assertSee('Esta salida ya no se puede reservar')
            ->assertSee('Las reservas se hacen con al menos 3 meses de anticipación. Elegí otra fecha de salida.')
            ->assertSee('Ver los paquetes')
            ->assertDontSee('Titular de la reserva');
    }

    public function test_avisa_si_la_salida_no_existe(): void
    {
        $this->crearExcursion('2027-01-18');

        $this->abrir('2027-02-15')
            ->assertSee('Esta salida no está disponible para reservar.')
            ->assertDontSee('Titular de la reserva');
    }

    public function test_avisa_sin_error_si_la_fecha_esta_mal_escrita(): void
    {
        $this->crearExcursion('2027-01-18');

        foreach (['2027-02-30', '18-01-2027', '2027-1-18', 'mañana'] as $fechaSalida) {
            $this->abrir($fechaSalida)
                ->assertSee('Esta salida no está disponible para reservar.')
                ->assertDontSee('Titular de la reserva');
        }

        $this->get('/reservar/'.$this->paquete->id_paquete.'/2027-02-30')
            ->assertOk()
            ->assertSee('Esta salida no está disponible para reservar.');
    }

    public function test_un_paquete_que_no_es_un_numero_da_404(): void
    {
        $this->get('/reservar/clasico/2027-01-18')->assertNotFound();
    }

    public function test_avisa_si_el_paquete_esta_inactivo(): void
    {
        $this->crearExcursion('2027-01-18');
        $this->paquete->update(['estado' => EstadoPaquete::Inactivo]);

        $this->abrir('2027-01-18')
            ->assertSee('Esta salida no está disponible para reservar.')
            ->assertDontSee('Titular de la reserva');
    }

    public function test_avisa_si_no_quedan_lugares_al_entrar(): void
    {
        $this->crearExcursion('2027-01-18', cupo: 2, plazasRetenidas: 2);

        $this->abrir('2027-01-18')
            ->assertSee('No quedan lugares en esta salida. Elegí otra fecha u otro paquete.')
            ->assertSee('Ver los paquetes')
            ->assertDontSee('Titular de la reserva');
    }

    public function test_no_acepta_mas_integrantes_que_los_lugares_disponibles(): void
    {
        $this->crearExcursion('2027-01-18', cupo: 3);

        $this->abrir('2027-01-18')
            ->set('cantidadIntegrantes', 4)
            ->assertHasErrors(['cantidadIntegrantes'])
            ->assertSee('Quedan 3 lugares en esta salida. Elegí otra fecha u otro paquete si necesitás más.');
    }

    public function test_vuelve_a_validar_el_cupo_al_avanzar(): void
    {
        $excursion = $this->crearExcursion('2027-01-18', cupo: 3);
        $componente = $this->abrir('2027-01-18')
            ->set('correoElectronico', 'ana.perez@mail.com')
            ->set('cantidadIntegrantes', 2);

        // Mientras tanto, otro cliente retiene dos lugares.
        $excursion->update(['plazas_retenidas' => 2]);

        $this->cargarIntegrante($componente, 'Ana', 'Pérez', 'AAA111')
            ->assertHasErrors(['cantidadIntegrantes'])
            ->assertSet('integranteActual', 0);
    }

    public function test_avisa_si_el_correo_no_tiene_un_formato_valido(): void
    {
        $this->crearExcursion('2027-01-18');

        $this->abrir('2027-01-18')
            ->set('correoElectronico', 'ana.perez@')
            ->assertHasErrors(['correoElectronico'])
            ->assertSee('El correo no tiene un formato válido.');
    }

    public function test_avisa_que_datos_del_integrante_faltan(): void
    {
        $this->crearExcursion('2027-01-18');

        $this->abrir('2027-01-18')
            ->set('correoElectronico', 'ana.perez@mail.com')
            ->call('siguienteIntegrante')
            ->assertHasErrors(['nombre', 'apellido', 'documentoPasaporte'])
            ->assertSee('Ingresá el nombre.')
            ->assertSee('Ingresá el apellido.')
            ->assertSee('El documento o pasaporte es obligatorio.')
            ->assertSet('paso', 1);
    }

    public function test_el_documento_solo_admite_letras_y_numeros(): void
    {
        $this->crearExcursion('2027-01-18');

        $this->abrir('2027-01-18')
            ->set('documentoPasaporte', 'AB-123')
            ->assertHasErrors(['documentoPasaporte'])
            ->assertSee('El documento o pasaporte sólo puede tener letras y números, hasta 20.');
    }

    public function test_no_acepta_un_documento_repetido_en_el_grupo(): void
    {
        $this->crearExcursion('2027-01-18');
        $componente = $this->abrir('2027-01-18')
            ->set('correoElectronico', 'ana.perez@mail.com')
            ->set('cantidadIntegrantes', 2);

        $this->cargarIntegrante($componente, 'Ana', 'Pérez', 'AAA111');
        $this->cargarIntegrante($componente, 'Bruno', 'Gómez', 'aaa111')
            ->assertHasErrors(['documentoPasaporte'])
            ->assertSee('Ya cargaste un integrante con ese documento.')
            ->assertSet('paso', 1);
    }

    public function test_no_acepta_mas_de_dos_noches_extra_en_total(): void
    {
        $this->crearExcursion('2027-01-18');

        $this->llegarAlResumen()
            ->call('sumarNoche', 'antes')
            ->call('sumarNoche', 'antes')
            ->call('sumarNoche', 'despues')
            ->assertHasErrors(['nochesExtra'])
            ->assertSee('Podés sumar hasta 2 noches extra en total.')
            ->assertSet('nochesExtraAntes', 2)
            ->assertSet('nochesExtraDespues', 0);
    }

    public function test_cancelar_lleva_al_inicio(): void
    {
        $this->crearExcursion('2027-01-18');

        $this->abrir('2027-01-18')
            ->set('correoElectronico', 'ana.perez@mail.com')
            ->call('cancelar')
            ->assertRedirect('/');
    }

    public function test_confirmar_y_pagar_esta_deshabilitado(): void
    {
        $this->crearExcursion('2027-01-18');

        $this->llegarAlResumen()
            ->assertSeeHtmlInOrder(['disabled="disabled"', 'Confirmar y pagar', 'Disponible próximamente']);
    }

    public function test_el_navegador_no_puede_cambiar_el_paso(): void
    {
        $this->crearExcursion('2027-01-18');

        $this->expectException(CannotUpdateLockedPropertyException::class);

        $this->abrir('2027-01-18')->set('paso', 2);
    }

    private function abrir(string $fechaSalida)
    {
        return Livewire::test('realizar-reserva', ['idPaquete' => $this->paquete->id_paquete, 'fechaSalida' => $fechaSalida]);
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

    private function llegarAlResumen()
    {
        $componente = $this->abrir('2027-01-18')
            ->set('correoElectronico', 'ana.perez@mail.com')
            ->set('cantidadIntegrantes', 2);

        $this->cargarIntegrante($componente, 'Ana', 'Pérez', 'AAA111', equipoCamping: true);

        return $this->cargarIntegrante($componente, 'Bruno', 'Gómez', 'BBB222')->assertSet('paso', 2);
    }

    private function crearExcursion(string $fechaSalida, int $cupo = 12, int $plazasRetenidas = 0): Excursion
    {
        return Excursion::create([
            'id_paquete' => $this->paquete->id_paquete,
            'id_guia' => $this->idGuia,
            'fecha_salida' => $fechaSalida,
            'cupo' => $cupo,
            'plazas_retenidas' => $plazasRetenidas,
        ]);
    }
}
