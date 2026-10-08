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
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
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

    public function test_el_correo_queda_en_minusculas_y_sin_espacios_en_las_puntas(): void
    {
        $this->crearExcursion('2027-01-18');

        $this->abrir('2027-01-18')
            ->set('correoElectronico', ' Ana.Perez@Mail.com ')
            ->assertHasNoErrors()
            ->assertSet('correoElectronico', 'ana.perez@mail.com');
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

    public function test_volver_con_anterior_no_traba_al_integrante_anterior(): void
    {
        $this->crearExcursion('2027-01-18');
        $componente = $this->abrir('2027-01-18')
            ->set('correoElectronico', 'ana.perez@mail.com')
            ->set('cantidadIntegrantes', 2);

        // En el integrante 2 se escribe el mismo documento que el 1 y se vuelve al 1 sin corregirlo.
        $this->cargarIntegrante($componente, 'Ana', 'Pérez', 'AAA111')
            ->set('documentoPasaporte', 'AAA111')
            ->call('anteriorIntegrante')
            ->call('siguienteIntegrante')
            ->assertHasNoErrors()
            ->assertSet('integranteActual', 1);
    }

    public function test_el_documento_repetido_se_marca_al_avanzar_desde_el_posterior(): void
    {
        $this->crearExcursion('2027-01-18');

        // Desde el resumen se vuelve al integrante 1 y se le pone el documento del 2, que ya estaba cargado.
        $this->llegarAlResumen()
            ->call('volver')
            ->call('anteriorIntegrante')
            ->set('documentoPasaporte', 'BBB222')
            ->call('siguienteIntegrante')
            ->assertHasNoErrors()
            ->assertSet('integranteActual', 1)
            ->call('siguienteIntegrante')
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

    public function test_confirmar_retiene_el_cupo_y_deriva_al_pago(): void
    {
        Queue::fake();
        $excursion = $this->crearExcursion('2027-01-18');
        $vence = now()->addMinutes(config('reserva.minutos_retencion'));

        $this->llegarAlResumen(correo: 'Ana.Perez@Mail.com')
            ->call('sumarNoche', 'antes')
            ->call('confirmarReserva')
            ->assertHasNoErrors()
            ->assertRedirect('/reservar/pago');

        $enCurso = session('reserva_en_curso');

        $this->assertSame(2, $excursion->fresh()->plazas_retenidas);
        $this->assertTrue(Str::isUuid($enCurso['id_retencion']));
        $this->assertSame([
            'id_excursion' => $excursion->id_excursion,
            'correo_electronico' => 'ana.perez@mail.com',
            'noches_extra_antes' => 1,
            'noches_extra_despues' => 0,
            'integrantes' => [
                ['nombre' => 'Ana', 'apellido' => 'Pérez', 'documento_pasaporte' => 'AAA111', 'equipo_camping' => true],
                ['nombre' => 'Bruno', 'apellido' => 'Gómez', 'documento_pasaporte' => 'BBB222', 'equipo_camping' => false],
            ],
            'vence' => $vence->toIso8601String(),
        ], Arr::except($enCurso, 'id_retencion'));

        Queue::assertPushedTimes(LiberarCupoRetenido::class, 1);
        Queue::assertPushed(LiberarCupoRetenido::class, fn ($tarea) => $tarea->idExcursion === $excursion->id_excursion
            && $tarea->cantidadPlazas === 2
            && $tarea->idRetencion === $enCurso['id_retencion']
            && $tarea->delay->equalTo($vence));

        $this->assertDatabaseCount('reserva', 0);
        $this->assertDatabaseCount('excursionista', 0);
    }

    public function test_si_otro_cliente_ocupa_los_lugares_antes_de_confirmar_no_retiene_nada(): void
    {
        Queue::fake();
        $excursion = $this->crearExcursion('2027-01-18', cupo: 3);
        $componente = $this->llegarAlResumen();

        // Mientras el cliente carga los datos, otro retiene dos de los tres lugares.
        $excursion->update(['plazas_retenidas' => 2]);

        $componente->call('confirmarReserva')
            ->assertNoRedirect()
            ->assertSee('Los lugares ya no están disponibles')
            ->assertSee('Mientras cargabas los datos se ocuparon los lugares que quedaban en esta salida. Elegí otra fecha u otro paquete.')
            ->assertSee('Ver los paquetes')
            ->assertSet('integrantes', [])
            ->assertSet('correoElectronico', '')
            ->assertSet('nombre', '')
            ->assertSet('paso', 1);

        $this->assertSame(2, $excursion->fresh()->plazas_retenidas);
        $this->assertNull(session('reserva_en_curso'));
        Queue::assertNothingPushed();
    }

    public function test_confirmar_con_otra_reserva_en_curso_libera_primero_la_anterior(): void
    {
        // La cola es la de la base, como en desarrollo: la retención anterior se libera en el momento y la tarea
        // demorada de la nueva queda guardada en la tabla jobs.
        config(['queue.default' => 'database']);
        $excursion = $this->crearExcursion('2027-01-18');
        $otraSalida = $this->crearExcursion('2027-02-01', plazasRetenidas: 3);
        session(['reserva_en_curso' => $this->reservaEnCurso($otraSalida, 'retencion-anterior', cantidadIntegrantes: 3)]);

        $this->llegarAlResumen()
            ->call('confirmarReserva')
            ->assertRedirect('/reservar/pago');

        // Se liberaron las 3 plazas de la anterior, en la otra salida, y se retuvieron las 2 de la nueva.
        $this->assertSame(0, $otraSalida->fresh()->plazas_retenidas);
        $this->assertSame(2, $excursion->fresh()->plazas_retenidas);
        $this->assertNotSame('retencion-anterior', session('reserva_en_curso.id_retencion'));
        $this->assertDatabaseCount('jobs', 1);
    }

    public function test_no_se_puede_confirmar_desde_la_pantalla_1(): void
    {
        Queue::fake();
        $excursion = $this->crearExcursion('2027-01-18');

        $this->abrir('2027-01-18')
            ->set('correoElectronico', 'ana.perez@mail.com')
            ->call('confirmarReserva')
            ->assertNoRedirect()
            ->assertSet('paso', 1);

        $this->assertSame(0, $excursion->fresh()->plazas_retenidas);
        $this->assertNull(session('reserva_en_curso'));
        Queue::assertNothingPushed();
    }

    public function test_no_se_puede_confirmar_con_integrantes_sin_cargar(): void
    {
        Queue::fake();
        $excursion = $this->crearExcursion('2027-01-18');

        // Desde el navegador se cambia la cantidad en la pantalla 2: quedan 2 integrantes cargados para 3 lugares.
        $this->llegarAlResumen()
            ->set('cantidadIntegrantes', 3)
            ->call('confirmarReserva')
            ->assertNoRedirect();

        $this->assertSame(0, $excursion->fresh()->plazas_retenidas);
        $this->assertNull(session('reserva_en_curso'));
        Queue::assertNothingPushed();
    }

    public function test_el_plazo_de_la_retencion_sale_de_la_configuracion(): void
    {
        Queue::fake();
        config(['reserva.minutos_retencion' => 1]);
        $this->crearExcursion('2027-01-18');

        $this->llegarAlResumen()
            ->assertSee('Cuando confirmes, guardamos tus lugares durante 1 minuto para que completes el pago.')
            ->call('confirmarReserva');

        $this->assertSame(now()->addMinute()->toIso8601String(), session('reserva_en_curso.vence'));
        Queue::assertPushed(LiberarCupoRetenido::class, fn ($tarea) => $tarea->delay->equalTo(now()->addMinute()));
    }

    public function test_cancelar_en_el_formulario_deja_intacta_la_reserva_en_curso(): void
    {
        // El cliente está pagando otra salida en otra pestaña: 2 de sus plazas y 3 de otros clientes.
        $this->crearExcursion('2027-01-18');
        $otraSalida = $this->crearExcursion('2027-02-01', plazasRetenidas: 5);
        $enCurso = $this->reservaEnCurso($otraSalida, 'retencion-1', cantidadIntegrantes: 2);
        session(['reserva_en_curso' => $enCurso]);

        $this->abrir('2027-01-18')
            ->assertSee('Titular de la reserva')
            ->set('correoElectronico', 'ana.perez@mail.com')
            ->call('cancelar')
            ->assertRedirect('/');

        $this->assertSame(5, $otraSalida->fresh()->plazas_retenidas);
        $this->assertSame($enCurso, session('reserva_en_curso'));
    }

    public function test_si_ya_tiene_lugares_guardados_para_esta_salida_lo_avisa_en_vez_del_formulario(): void
    {
        config(['reserva.minutos_retencion' => 3]);
        $excursion = $this->crearExcursion('2027-01-18', plazasRetenidas: 2);
        session(['reserva_en_curso' => $this->reservaEnCurso($excursion, 'retencion-1', cantidadIntegrantes: 2)]);

        // El cliente confirmó hace 30 segundos y volvió atrás con el navegador.
        $this->travel(30)->seconds();

        $this->abrir('2027-01-18')
            ->assertSet('impedimento', 'reserva-en-curso')
            ->assertSee('Ya tenés lugares guardados para esta salida')
            ->assertSee('Te quedan 02:30 para completar el pago.')
            ->assertSeeHtml('href="/reservar/pago"')
            ->assertSee('Ir al pago')
            ->assertSee('Cancelarla')
            ->assertDontSee('Titular de la reserva')
            ->assertDontSee('Ver los paquetes');

        $this->assertSame(2, $excursion->fresh()->plazas_retenidas);
    }

    public function test_si_retuvo_los_ultimos_lugares_y_vuelve_atras_ve_sus_lugares_y_no_sin_cupo(): void
    {
        // Quedaban 2 lugares y el cliente los retuvo: el cupo disponible es 0 por su propia retención.
        $excursion = $this->crearExcursion('2027-01-18', cupo: 2, plazasRetenidas: 2);
        session(['reserva_en_curso' => $this->reservaEnCurso($excursion, 'retencion-1', cantidadIntegrantes: 2)]);

        $this->abrir('2027-01-18')
            ->assertSee('Ya tenés lugares guardados para esta salida')
            ->assertDontSee('No quedan lugares en esta salida.');
    }

    public function test_cancelarla_libera_los_lugares_y_deja_el_formulario_listo_con_el_cupo_actualizado(): void
    {
        $excursion = $this->crearExcursion('2027-01-18', cupo: 2, plazasRetenidas: 2);
        session(['reserva_en_curso' => $this->reservaEnCurso($excursion, 'retencion-1', cantidadIntegrantes: 2)]);

        $this->abrir('2027-01-18')
            ->call('cancelarReservaEnCurso')
            ->assertSet('impedimento', null)
            ->assertSee('Titular de la reserva')
            ->assertSee('Quedan 2 lugares');

        $this->assertSame(0, $excursion->fresh()->plazas_retenidas);
        $this->assertNull(session('reserva_en_curso'));
    }

    public function test_si_la_reserva_en_curso_de_esta_salida_ya_vencio_la_libera_y_muestra_el_formulario(): void
    {
        $excursion = $this->crearExcursion('2027-01-18', cupo: 2, plazasRetenidas: 2);
        session(['reserva_en_curso' => $this->reservaEnCurso($excursion, 'retencion-1', cantidadIntegrantes: 2)]);
        $this->travel(config('reserva.minutos_retencion') + 1)->minutes();

        $this->abrir('2027-01-18')
            ->assertSet('impedimento', null)
            ->assertSee('Titular de la reserva')
            ->assertSee('Quedan 2 lugares');

        $this->assertSame(0, $excursion->fresh()->plazas_retenidas);
        $this->assertNull(session('reserva_en_curso'));
    }

    public function test_una_reserva_en_curso_de_otra_salida_no_cambia_nada(): void
    {
        $this->crearExcursion('2027-01-18');
        $otraSalida = $this->crearExcursion('2027-02-01', plazasRetenidas: 2);
        $enCurso = $this->reservaEnCurso($otraSalida, 'retencion-1', cantidadIntegrantes: 2);
        session(['reserva_en_curso' => $enCurso]);

        $this->abrir('2027-01-18')
            ->assertSet('impedimento', null)
            ->assertSee('Titular de la reserva')
            ->assertDontSee('Ya tenés lugares guardados para esta salida');

        $this->assertSame(2, $otraSalida->fresh()->plazas_retenidas);
        $this->assertSame($enCurso, session('reserva_en_curso'));
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

    private function llegarAlResumen(string $correo = 'ana.perez@mail.com')
    {
        $componente = $this->abrir('2027-01-18')
            ->set('correoElectronico', $correo)
            ->set('cantidadIntegrantes', 2);

        $this->cargarIntegrante($componente, 'Ana', 'Pérez', 'AAA111', equipoCamping: true);

        return $this->cargarIntegrante($componente, 'Bruno', 'Gómez', 'BBB222')->assertSet('paso', 2);
    }

    // Lo que deja en la sesión una confirmación anterior: sólo importan la excursión, los integrantes y el identificador.
    private function reservaEnCurso(Excursion $excursion, string $idRetencion, int $cantidadIntegrantes): array
    {
        $integrantes = [];

        for ($numero = 1; $numero <= $cantidadIntegrantes; $numero++) {
            $integrantes[] = ['nombre' => 'Integrante', 'apellido' => (string) $numero, 'documento_pasaporte' => 'PAS'.$numero, 'equipo_camping' => false];
        }

        return [
            'id_retencion' => $idRetencion,
            'id_excursion' => $excursion->id_excursion,
            'correo_electronico' => 'titular@mail.com',
            'noches_extra_antes' => 0,
            'noches_extra_despues' => 0,
            'integrantes' => $integrantes,
            'vence' => now()->addMinutes(config('reserva.minutos_retencion'))->toIso8601String(),
        ];
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
