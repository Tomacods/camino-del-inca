<?php

namespace Tests\Feature;

use App\Enums\EstadoPaquete;
use App\Enums\EstadoPermiso;
use App\Enums\EstadoReserva;
use App\Enums\EstadoSaldo;
use App\Enums\Rol;
use App\Enums\TipoNotificacion;
use App\Filament\Resources\Excursiones\ExcursionResource;
use App\Filament\Resources\Excursiones\Pages\ListarIntegrantesExcursion;
use App\Filament\Resources\Excursiones\Pages\ListExcursiones;
use App\Mail\NotificacionCliente;
use App\Models\Etapa;
use App\Models\Excursion;
use App\Models\Excursionista;
use App\Models\Guia;
use App\Models\Paquete;
use App\Models\Recorrido;
use App\Models\Reserva;
use App\Models\Usuario;
use DomainException;
use Filament\Actions\Testing\TestAction;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\ServicioCorreoQueFalla;
use Tests\TestCase;

class ReportarEtapaTest extends TestCase
{
    use RefreshDatabase;

    private Usuario $administrador;

    private Usuario $usuarioGuia;

    private Usuario $otroUsuarioGuia;

    private Recorrido $recorrido;

    // Las cuatro etapas del recorrido, por orden: Piscacucho, Wayllabamba, Pacaymayo y Machu Picchu (la final).
    private array $etapas = [];

    // De Rosa Quispe: la que salió hoy y ya se inició, con reservas en todos los estados, y la que salió hoy y todavía
    // no se inició.
    private Excursion $excursionEnCurso;

    private Excursion $excursionSinIniciar;

    // De Andrés Mamani, también en curso.
    private Excursion $excursionDeOtroGuia;

    protected function setUp(): void
    {
        parent::setUp();

        // Lunes: las salidas son siempre lunes.
        $this->travelTo('2026-10-19 08:30:00');

        Mail::fake();

        $this->crearDatos();
    }

    /* ------------------------------ Curso normal ------------------------------ */

    public function test_el_guia_reporta_la_primera_etapa_y_las_siguientes_en_orden(): void
    {
        $listado = $this->listado()->assertTableActionVisible('reportarEtapa', $this->excursionEnCurso);

        foreach (['Piscacucho', 'Wayllabamba', 'Pacaymayo'] as $nombreEtapa) {
            $listado->callAction($this->accion($this->excursionEnCurso))->assertNotified('Etapa reportada');

            $this->assertSame($nombreEtapa, $this->excursionEnCurso->fresh()->etapaActual->nombre);
        }

        $listado->assertTableActionVisible('reportarEtapa', $this->excursionEnCurso);
        $this->assertSame(EstadoReserva::Confirmada, Reserva::buscarReserva('000124-7')->estado);
        Mail::assertNothingQueued();
    }

    // Paso 5: informa la etapa registrada y la siguiente del itinerario.
    public function test_el_aviso_dice_la_etapa_actual_y_la_siguiente(): void
    {
        $this->listado()
            ->callAction($this->accion($this->excursionEnCurso))
            ->assertNotified(Notification::make()
                ->title('Etapa reportada')
                ->body('Camino Inca Clásico · salida 19/10/2026: la etapa actual es «Piscacucho». La siguiente es «Wayllabamba».')
                ->success());
    }

    // Paso 2: las etapas planificadas en orden, la última reportada y la siguiente.
    public function test_la_confirmacion_muestra_las_etapas_la_ultima_reportada_y_la_siguiente(): void
    {
        $this->reportarHasta(2);

        $this->listado()
            ->mountAction($this->accion($this->excursionEnCurso))
            ->assertMountedActionModalSee('Reportar etapa: Pacaymayo')
            ->assertMountedActionModalSee('Paquete Camino Inca Clásico · Salida 19/10/2026 · Última etapa reportada: Wayllabamba.')
            ->assertMountedActionModalSeeHtml('Wayllabamba · última reportada')
            ->assertMountedActionModalSeeHtml('<strong>Pacaymayo · a reportar</strong>')
            ->assertMountedActionModalSee('Machu Picchu')
            ->assertMountedActionModalDontSee('Es la etapa final');
    }

    public function test_la_primera_confirmacion_dice_que_no_hay_etapa_reportada(): void
    {
        $this->listado()
            ->mountAction($this->accion($this->excursionEnCurso))
            ->assertMountedActionModalSee('Última etapa reportada: ninguna.');
    }

    public function test_la_tabla_muestra_la_etapa_actual(): void
    {
        $this->listado()
            ->assertTableColumnStateSet('etapaActual.nombre', null, $this->excursionEnCurso)
            ->callAction($this->accion($this->excursionEnCurso))
            ->assertTableColumnStateSet('etapaActual.nombre', 'Piscacucho', $this->excursionEnCurso)
            ->callAction($this->accion($this->excursionEnCurso))
            ->assertTableColumnStateSet('etapaActual.nombre', 'Wayllabamba', $this->excursionEnCurso);
    }

    public function test_el_guia_reporta_desde_la_pagina_de_integrantes(): void
    {
        $this->paginaIntegrantes($this->excursionEnCurso)
            ->assertActionVisible('reportarEtapa')
            ->callAction('reportarEtapa')
            ->assertNotified('Etapa reportada')
            ->callAction('reportarEtapa')
            ->assertNotified('Etapa reportada');

        $this->assertSame('Wayllabamba', $this->excursionEnCurso->fresh()->etapaActual->nombre);
    }

    // Las acciones de la tabla miran las etapas y la etapa actual en cada fila: se traen una sola vez con la tabla.
    public function test_el_listado_no_consulta_las_etapas_por_cada_excursion(): void
    {
        $this->reportarHasta(2);
        $this->excursionDeOtroGuia->update(['id_guia' => $this->usuarioGuia->id_usuario]);
        $this->crearExcursion('2026-10-26', $this->usuarioGuia);
        $this->crearExcursion('2026-10-12', $this->usuarioGuia, '2026-10-12 07:00:00');

        $this->actingAs($this->usuarioGuia);

        DB::enableQueryLog();
        Livewire::test(ListExcursiones::class)->assertCanSeeTableRecords([$this->excursionEnCurso, $this->excursionDeOtroGuia]);
        $consultas = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        // Una para las etapas de los recorridos y otra para las etapas actuales.
        $this->assertLessThanOrEqual(2, count(array_filter($consultas, fn (string $consulta) => str_contains($consulta, 'from "etapa"'))));
    }

    // No hay plazo en días: si el guía se atrasa, igual puede terminar el recorrido y las reservas llegan a «Finalizada».
    public function test_se_puede_reportar_despues_del_ultimo_dia_del_recorrido(): void
    {
        $this->reportarHasta(3);
        $this->travelTo('2026-11-02 10:00:00');

        $this->listado()
            ->assertTableActionVisible('reportarEtapa', $this->excursionEnCurso)
            ->callAction($this->accion($this->excursionEnCurso))
            ->assertNotified('Recorrido finalizado');

        $this->assertSame(EstadoReserva::Finalizada, Reserva::buscarReserva('000124-7')->estado);
    }

    /* ----------------------------------- A1 ----------------------------------- */

    public function test_sin_iniciar_el_recorrido_no_muestra_la_accion(): void
    {
        $this->listado()
            ->assertTableActionHidden('reportarEtapa', $this->excursionSinIniciar)
            ->assertTableActionVisible('iniciarRecorrido', $this->excursionSinIniciar);

        $this->paginaIntegrantes($this->excursionSinIniciar)->assertActionHidden('reportarEtapa');
    }

    public function test_sin_iniciar_el_recorrido_no_se_registra(): void
    {
        $this->assertNoSeReporta($this->excursionSinIniciar, $this->etapas[1]->id_etapa,
            'El recorrido todavía no se inició: primero tenés que iniciarlo.');

        $this->assertNull($this->excursionSinIniciar->fresh()->id_etapa_actual);
    }

    // Aunque se arme el pedido a mano, Filament vuelve a evaluar visible() y no ejecuta la acción.
    public function test_sin_iniciar_el_recorrido_la_accion_forzada_no_registra(): void
    {
        $this->listado()
            ->call('mountAction', 'reportarEtapa', [], ['table' => true, 'recordKey' => (string) $this->excursionSinIniciar->getKey()])
            ->call('callMountedAction')
            ->assertNotNotified('Etapa reportada');

        $this->assertNull($this->excursionSinIniciar->fresh()->id_etapa_actual);
    }

    /* ----------------------------------- A2 ----------------------------------- */

    public function test_la_etapa_final_finaliza_solo_las_reservas_confirmadas(): void
    {
        $this->reportarHasta(3);

        $this->listado()->callAction($this->accion($this->excursionEnCurso));

        $this->assertSame('Machu Picchu', $this->excursionEnCurso->fresh()->etapaActual->nombre);
        $this->assertEstados([
            '000124-7' => EstadoReserva::Finalizada,
            '000125-9' => EstadoReserva::Finalizada,
            '000126-0' => EstadoReserva::Pendiente,
            '000127-2' => EstadoReserva::SinPermiso,
            '000128-4' => EstadoReserva::Cancelada,
        ]);
        // La reserva de otra excursión del mismo guía no cambia.
        $this->assertSame(EstadoReserva::Confirmada, Reserva::buscarReserva('000129-6')->estado);
    }

    public function test_la_etapa_final_envia_un_correo_etapa_final_a_cada_titular_finalizado(): void
    {
        $this->reportarHasta(3);

        $this->listado()->callAction($this->accion($this->excursionEnCurso));

        Mail::assertQueuedCount(2);

        foreach (['000124-7', '000125-9'] as $numeroReserva) {
            Mail::assertQueued(NotificacionCliente::class, fn (NotificacionCliente $correo) => $correo->tipo === TipoNotificacion::EtapaFinal
                && $correo->hasTo('titular.'.$numeroReserva.'@mail.com')
                && $correo->datos['reserva']['estado'] === EstadoReserva::Finalizada);
        }
    }

    // A2 4.5.
    public function test_el_aviso_dice_que_el_recorrido_termino_y_cuantas_reservas_se_finalizaron(): void
    {
        $this->reportarHasta(3);

        $this->listado()
            ->callAction($this->accion($this->excursionEnCurso))
            ->assertNotified(Notification::make()
                ->title('Recorrido finalizado')
                ->body('Camino Inca Clásico · salida 19/10/2026: se reportó la etapa final, «Machu Picchu». 2 reserva(s) pasaron a '
                    .'«Finalizada» y se enviará el correo «Etapa Final» a cada titular.')
                ->success());
    }

    public function test_la_confirmacion_de_la_etapa_final_adelanta_lo_que_va_a_pasar(): void
    {
        $this->reportarHasta(3);

        $this->listado()
            ->mountAction($this->accion($this->excursionEnCurso))
            ->assertMountedActionModalSee('Reportar etapa: Machu Picchu')
            ->assertMountedActionModalSee('Es la etapa final: al confirmar, el recorrido termina, las reservas «Confirmada» (2) pasan a '
                .'«Finalizada» y se avisa por correo a cada titular.');
    }

    public function test_una_excursion_sin_reservas_confirmadas_tambien_termina(): void
    {
        $this->excursionDeOtroGuia->update(['id_etapa_actual' => $this->etapas[3]->id_etapa]);
        $this->actingAs($this->otroUsuarioGuia);

        Livewire::test(ListExcursiones::class)
            ->callAction($this->accion($this->excursionDeOtroGuia))
            ->assertNotified(Notification::make()
                ->title('Recorrido finalizado')
                ->body('Camino Inca Corto · salida 19/10/2026: se reportó la etapa final, «Machu Picchu». No había reservas «Confirmada» '
                    .'para finalizar.')
                ->success());

        Mail::assertNothingQueued();
    }

    // La precondición: el recorrido todavía no reportó su etapa final.
    public function test_despues_de_la_etapa_final_la_accion_desaparece(): void
    {
        $this->reportarHasta(3);

        $this->listado()
            ->callAction($this->accion($this->excursionEnCurso))
            ->assertTableActionHidden('reportarEtapa', $this->excursionEnCurso);

        $this->paginaIntegrantes($this->excursionEnCurso)->assertActionHidden('reportarEtapa');
    }

    public function test_despues_de_la_etapa_final_no_se_puede_reportar_mas(): void
    {
        $this->reportarHasta(4);

        $this->assertNoSeReporta($this->excursionEnCurso, $this->etapas[4]->id_etapa,
            'El recorrido ya terminó: la etapa final, «Machu Picchu», ya se reportó.');

        $this->assertSame('Machu Picchu', $this->excursionEnCurso->fresh()->etapaActual->nombre);
    }

    // A8 de CU-13: el correo se invoca después de la transacción. Si no sale, las reservas quedan «Finalizada» igual y
    // el aviso lo dice.
    public function test_un_correo_que_no_se_puede_enviar_no_deshace_la_finalizacion(): void
    {
        // Se deja de lado el correo de prueba del setUp(), para que los envíos pasen por el servicio que falla.
        Mail::swap(new MailManager($this->app));
        ServicioCorreoQueFalla::instalar();
        Log::spy();

        $this->reportarHasta(3);

        $this->listado()
            ->callAction($this->accion($this->excursionEnCurso))
            ->assertNotified(Notification::make()
                ->title('Recorrido finalizado')
                ->body('Camino Inca Clásico · salida 19/10/2026: se reportó la etapa final, «Machu Picchu». 2 reserva(s) pasaron a '
                    .'«Finalizada». No se pudo enviar el correo «Etapa Final» de la(s) reserva(s) 000124-7, 000125-9: quedó '
                    .'anotado en el registro.')
                ->warning());

        $this->assertSame('Machu Picchu', $this->excursionEnCurso->fresh()->etapaActual->nombre);
        $this->assertSame(EstadoReserva::Finalizada, Reserva::buscarReserva('000124-7')->estado);
        $this->assertSame(EstadoReserva::Finalizada, Reserva::buscarReserva('000125-9')->estado);
        Log::shouldHaveReceived('error')->twice();
    }

    /* ------------------------------ Dos pedidos ------------------------------- */

    // Doble clic o reintento después de un corte (A3) cuando el primer pedido sí había llegado: la misma etapa no avanza
    // dos veces.
    public function test_el_mismo_reporte_dos_veces_no_avanza_dos_etapas(): void
    {
        $idPiscacucho = $this->etapas[1]->id_etapa;
        $this->excursionEnCurso->actualizarEtapaActual($idPiscacucho);

        $this->assertNoSeReporta($this->excursionEnCurso->fresh(), $idPiscacucho,
            'La etapa «Piscacucho» ya se reportó. La siguiente es «Wayllabamba».');

        // También desde una copia vieja de la excursión, que todavía no sabe que se reportó.
        $this->assertNoSeReporta(Excursion::find($this->excursionEnCurso->getKey())->forceFill(['id_etapa_actual' => null]), $idPiscacucho,
            'La etapa «Piscacucho» ya se reportó. La siguiente es «Wayllabamba».');

        $this->assertSame('Piscacucho', $this->excursionEnCurso->fresh()->etapaActual->nombre);
    }

    // La confirmación estaba abierta y, mientras tanto, en otra pestaña el guía ya reportó esa etapa. Al confirmar, la
    // acción sigue visible (falta la final), pero la etapa que se vio ya no es la siguiente: no se reporta otra.
    public function test_si_se_reporto_en_otra_pestana_no_avanza_otra_etapa(): void
    {
        $listado = $this->listado()->mountAction($this->accion($this->excursionEnCurso));

        $this->excursionEnCurso->actualizarEtapaActual($this->etapas[1]->id_etapa);

        $listado->callMountedAction()
            ->assertNotified(Notification::make()
                ->title('No se reportó la etapa')
                ->body('La etapa «Piscacucho» ya se reportó. La siguiente es «Wayllabamba».')
                ->warning());

        $this->assertSame('Piscacucho', $this->excursionEnCurso->fresh()->etapaActual->nombre);
    }

    public function test_la_etapa_final_dos_veces_no_duplica_los_correos(): void
    {
        $this->reportarHasta(3);

        $listado = $this->listado();
        $otraPestana = $this->listado()->mountAction($this->accion($this->excursionEnCurso));

        $listado->callAction($this->accion($this->excursionEnCurso))->assertNotified('Recorrido finalizado');
        $otraPestana->callMountedAction()->assertNotNotified('Recorrido finalizado');

        try {
            $this->excursionEnCurso->fresh()->actualizarEtapaActual($this->etapas[4]->id_etapa);
            $this->fail('Tenía que avisar que el recorrido ya terminó.');
        } catch (DomainException) {
        }

        Mail::assertQueuedCount(2);
    }

    public function test_no_se_pueden_saltear_etapas(): void
    {
        $this->assertNoSeReporta($this->excursionEnCurso, $this->etapas[3]->id_etapa,
            'Las etapas se reportan en orden: la siguiente es «Piscacucho».');

        $this->assertNull($this->excursionEnCurso->fresh()->id_etapa_actual);
    }

    public function test_no_se_puede_volver_a_una_etapa_anterior(): void
    {
        $this->reportarHasta(3);

        $this->assertNoSeReporta($this->excursionEnCurso->fresh(), $this->etapas[1]->id_etapa,
            'Las etapas se reportan en orden: la siguiente es «Machu Picchu».');

        $this->assertSame('Pacaymayo', $this->excursionEnCurso->fresh()->etapaActual->nombre);
    }

    /* ------------------------------ Confirmación ------------------------------ */

    public function test_si_el_guia_cancela_la_confirmacion_no_cambia_nada(): void
    {
        $this->listado()
            ->mountAction($this->accion($this->excursionEnCurso))
            ->call('unmountAction')
            ->assertNotNotified();

        $this->assertNull($this->excursionEnCurso->fresh()->id_etapa_actual);
    }

    /* --------------------------------- Acceso --------------------------------- */

    public function test_el_guia_no_puede_reportar_la_excursion_de_otro_guia(): void
    {
        // Aunque arme el pedido a mano con la clave de la otra excursión, Filament la busca en la consulta del recurso, que
        // sólo tiene las suyas: no la encuentra y no monta la acción.
        $this->listado()
            ->assertCanNotSeeTableRecords([$this->excursionDeOtroGuia])
            ->call('mountAction', 'reportarEtapa', [], ['table' => true, 'recordKey' => (string) $this->excursionDeOtroGuia->getKey()])
            ->assertSet('mountedActions', [])
            ->call('callMountedAction')
            ->assertNotNotified('Etapa reportada');

        $this->assertNull($this->excursionDeOtroGuia->fresh()->id_etapa_actual);

        $this->actingAs($this->usuarioGuia)
            ->get(ExcursionResource::getUrl('integrantes', ['record' => $this->excursionDeOtroGuia]))
            ->assertNotFound();
    }

    public function test_el_otro_guia_si_puede_reportar_la_suya(): void
    {
        $this->actingAs($this->otroUsuarioGuia);

        Livewire::test(ListExcursiones::class)
            ->assertTableActionVisible('reportarEtapa', $this->excursionDeOtroGuia)
            ->callAction($this->accion($this->excursionDeOtroGuia))
            ->assertNotified('Etapa reportada');

        $this->assertSame('Piscacucho', $this->excursionDeOtroGuia->fresh()->etapaActual->nombre);
        $this->assertNull($this->excursionEnCurso->fresh()->id_etapa_actual);
    }

    public function test_el_administrador_no_ve_ni_ejecuta_la_accion(): void
    {
        $this->actingAs($this->administrador);

        $this->get(ExcursionResource::getUrl('index'))->assertForbidden();
        $this->get(ExcursionResource::getUrl('integrantes', ['record' => $this->excursionEnCurso]))->assertForbidden();

        // La consulta con la que la acción busca la excursión no tiene ninguna para el administrador.
        $this->assertNull(ExcursionResource::getEloquentQuery()->find($this->excursionEnCurso->getKey()));

        $this->assertNull($this->excursionEnCurso->fresh()->id_etapa_actual);
    }

    /* --------------------------------- Modelo --------------------------------- */

    // Las etapas se crearon desordenadas: el recorrido las devuelve por su orden.
    public function test_las_etapas_vienen_en_el_orden_del_recorrido(): void
    {
        $this->assertSame(['Piscacucho', 'Wayllabamba', 'Pacaymayo', 'Machu Picchu'],
            $this->excursionEnCurso->getEtapas()->pluck('nombre')->all());
    }

    public function test_la_siguiente_etapa_es_la_primera_mientras_no_se_reporte_ninguna(): void
    {
        $this->assertNull($this->excursionEnCurso->getEtapaActual());
        $this->assertTrue($this->etapas[1]->is($this->excursionEnCurso->obtenerSiguienteEtapa()));
    }

    public function test_la_siguiente_etapa_sigue_el_orden_y_no_hay_otra_despues_de_la_final(): void
    {
        $this->excursionEnCurso->setRelation('etapaActual', $this->etapas[2]);
        $this->assertTrue($this->etapas[3]->is($this->excursionEnCurso->obtenerSiguienteEtapa()));

        $this->excursionEnCurso->setRelation('etapaActual', $this->etapas[4]);
        $this->assertNull($this->excursionEnCurso->obtenerSiguienteEtapa());
    }

    public function test_es_etapa_final_solo_con_la_ultima_etapa_reportada(): void
    {
        $this->assertFalse($this->excursionEnCurso->esEtapaFinal());

        $this->excursionEnCurso->setRelation('etapaActual', $this->etapas[3]);
        $this->assertFalse($this->excursionEnCurso->esEtapaFinal());
        $this->assertTrue($this->excursionEnCurso->esSiguienteEtapaFinal());

        $this->excursionEnCurso->setRelation('etapaActual', $this->etapas[4]);
        $this->assertTrue($this->excursionEnCurso->esEtapaFinal());
        $this->assertFalse($this->excursionEnCurso->esSiguienteEtapaFinal());
    }

    public function test_recuperar_etapa_actual_devuelve_las_etapas_la_actual_y_la_siguiente(): void
    {
        $this->excursionEnCurso->setRelation('etapaActual', $this->etapas[2]);

        $datos = $this->excursionEnCurso->recuperarEtapaActual();

        $this->assertCount(4, $datos['etapas']);
        $this->assertTrue($this->etapas[2]->is($datos['etapa_actual']));
        $this->assertTrue($this->etapas[3]->is($datos['siguiente']));
    }

    public function test_admite_el_reporte_con_el_recorrido_iniciado_y_sin_la_etapa_final(): void
    {
        $this->assertTrue($this->excursionEnCurso->admiteReporteEtapa());
        $this->assertFalse($this->excursionSinIniciar->admiteReporteEtapa());

        $this->excursionEnCurso->setRelation('etapaActual', $this->etapas[4]);
        $this->assertFalse($this->excursionEnCurso->admiteReporteEtapa());
    }

    public function test_actualizar_etapa_actual_actualiza_la_misma_instancia(): void
    {
        $reservasFinalizadas = $this->excursionEnCurso->actualizarEtapaActual($this->etapas[1]->id_etapa);

        $this->assertCount(0, $reservasFinalizadas);
        $this->assertSame($this->etapas[1]->id_etapa, $this->excursionEnCurso->id_etapa_actual);
        $this->assertSame('Piscacucho', $this->excursionEnCurso->getEtapaActual()->nombre);
    }

    public function test_reportar_la_etapa_final_devuelve_las_reservas_finalizadas(): void
    {
        $this->reportarHasta(3);

        $reservasFinalizadas = $this->excursionEnCurso->fresh()->actualizarEtapaActual($this->etapas[4]->id_etapa);

        $this->assertSame(['000124-7', '000125-9'], $reservasFinalizadas->pluck('numero_reserva')->all());
    }

    public function test_finalizar_reserva_pasa_una_confirmada_a_finalizada(): void
    {
        $reserva = Reserva::buscarReserva('000124-7');

        $reserva->finalizarReserva();

        $this->assertSame(EstadoReserva::Finalizada, $reserva->fresh()->estado);
    }

    public function test_finalizar_reserva_no_acepta_una_que_no_este_confirmada(): void
    {
        $reserva = Reserva::buscarReserva('000128-4');

        try {
            $reserva->finalizarReserva();
            $this->fail('Tenía que avisar que la reserva no está confirmada.');
        } catch (DomainException $excepcion) {
            $this->assertSame('La reserva 000128-4 está en «Cancelada»: sólo se finaliza una reserva «Confirmada».', $excepcion->getMessage());
        }

        $this->assertSame(EstadoReserva::Cancelada, $reserva->fresh()->estado);
    }

    /* -------------------------------- Ayudantes ------------------------------- */

    private function listado()
    {
        $this->actingAs($this->usuarioGuia);

        return Livewire::test(ListExcursiones::class);
    }

    private function paginaIntegrantes(Excursion $excursion)
    {
        $this->actingAs($this->usuarioGuia);

        return Livewire::test(ListarIntegrantesExcursion::class, ['record' => $excursion->getRouteKey()]);
    }

    private function accion(Excursion $excursion): TestAction
    {
        return TestAction::make('reportarEtapa')->table($excursion);
    }

    // Deja reportadas, en la excursión en curso, las etapas hasta la de ese orden.
    private function reportarHasta(int $orden): void
    {
        for ($ordenEtapa = 1; $ordenEtapa <= $orden; $ordenEtapa++) {
            $this->excursionEnCurso->actualizarEtapaActual($this->etapas[$ordenEtapa]->id_etapa);
        }
    }

    private function assertNoSeReporta(Excursion $excursion, int $idEtapa, string $aviso): void
    {
        try {
            $excursion->actualizarEtapaActual($idEtapa);
            $this->fail('Tenía que avisar que no se puede reportar esa etapa.');
        } catch (DomainException $excepcion) {
            $this->assertSame($aviso, $excepcion->getMessage());
        }
    }

    private function assertEstados(array $estadosPorNumero): void
    {
        foreach ($estadosPorNumero as $numeroReserva => $estado) {
            $this->assertSame($estado, Reserva::buscarReserva($numeroReserva)->estado, 'Reserva '.$numeroReserva);
        }
    }

    private function crearDatos(): void
    {
        $this->administrador = Usuario::create(['correo' => 'admin@caminodelinca.test', 'password' => 'admin1234', 'rol' => Rol::Administrador]);
        $this->usuarioGuia = $this->crearGuia('guia@caminodelinca.test', 'Rosa', 'Quispe');
        $this->otroUsuarioGuia = $this->crearGuia('luko@caminodelinca.test', 'Andrés', 'Mamani');

        $this->recorrido = Recorrido::create(['nombre' => 'Camino Clásico', 'duracion_dias' => 4, 'cantidad_campings' => 3]);

        // Desordenadas a propósito: el orden lo da la columna, no el momento en que se cargaron.
        foreach ([3 => 'Pacaymayo', 1 => 'Piscacucho', 4 => 'Machu Picchu', 2 => 'Wayllabamba'] as $orden => $nombre) {
            $this->etapas[$orden] = Etapa::create(['id_recorrido' => $this->recorrido->id_recorrido, 'nombre' => $nombre, 'orden' => $orden]);
        }

        $paquete = $this->crearPaquete('Camino Inca Clásico');

        $this->excursionEnCurso = $this->crearExcursion('2026-10-19', $this->usuarioGuia, '2026-10-19 07:00:00', $paquete);
        $this->excursionSinIniciar = $this->crearExcursion('2026-10-19', $this->usuarioGuia, null, $this->crearPaquete('Camino Inca Express'));
        $this->excursionDeOtroGuia = $this->crearExcursion('2026-10-19', $this->otroUsuarioGuia, '2026-10-19 07:00:00', $this->crearPaquete('Camino Inca Corto'));
        $excursionProxima = $this->crearExcursion('2026-10-26', $this->usuarioGuia, null, $paquete);

        $this->crearReserva($this->excursionEnCurso, '000124-7', EstadoReserva::Confirmada, 2);
        $this->crearReserva($this->excursionEnCurso, '000125-9', EstadoReserva::Confirmada, 1);
        $this->crearReserva($this->excursionEnCurso, '000126-0', EstadoReserva::Pendiente, 1);
        $this->crearReserva($this->excursionEnCurso, '000127-2', EstadoReserva::SinPermiso, 1);
        $this->crearReserva($this->excursionEnCurso, '000128-4', EstadoReserva::Cancelada, 1);
        $this->crearReserva($excursionProxima, '000129-6', EstadoReserva::Confirmada, 1);
    }

    private function crearGuia(string $correo, string $nombre, string $apellido): Usuario
    {
        $usuario = Usuario::create(['correo' => $correo, 'password' => 'guia1234', 'rol' => Rol::Guia]);
        Guia::create(['id_usuario' => $usuario->id_usuario, 'nombre' => $nombre, 'apellido' => $apellido]);

        return $usuario;
    }

    private function crearPaquete(string $nombre): Paquete
    {
        return Paquete::create([
            'id_recorrido' => $this->recorrido->id_recorrido,
            'nombre' => $nombre,
            'precio_base' => 750,
            'costo_noche_extra_cusco' => 60,
            'costo_equipo_camping' => 45,
            'cantidad_porteadores' => 2,
            'estado' => EstadoPaquete::Activo,
            'fecha_creacion' => '2026-01-05',
        ]);
    }

    private function crearExcursion(string $fechaSalida, Usuario $usuarioGuia, ?string $inicioRecorrido = null, ?Paquete $paquete = null): Excursion
    {
        return Excursion::create([
            'id_paquete' => ($paquete ?? $this->crearPaquete('Camino Inca '.$fechaSalida))->id_paquete,
            'id_guia' => $usuarioGuia->id_usuario,
            'fecha_salida' => $fechaSalida,
            'cupo' => 12,
            'plazas_retenidas' => 0,
            'fecha_hora_inicio_recorrido' => $inicioRecorrido,
        ]);
    }

    private function crearReserva(Excursion $excursion, string $numeroReserva, EstadoReserva $estado, int $cantidadIntegrantes): void
    {
        $reserva = Reserva::create([
            'id_excursion' => $excursion->id_excursion,
            'numero_reserva' => $numeroReserva,
            'correo_electronico' => 'titular.'.$numeroReserva.'@mail.com',
            'fecha_reserva' => '2026-06-30 10:30:00',
            'estado' => $estado,
            'estado_saldo' => EstadoSaldo::Abonado,
            'noches_extra_antes' => 0,
            'noches_extra_despues' => 0,
            'fecha_limite_saldo' => null,
            'fecha_limite_confirmacion' => '2026-09-19 23:59:59',
        ]);

        for ($posicion = 1; $posicion <= $cantidadIntegrantes; $posicion++) {
            Excursionista::create([
                'id_reserva' => $reserva->id_reserva,
                'nombre' => 'Integrante',
                'apellido' => (string) $posicion,
                'documento_pasaporte' => $numeroReserva.'-'.$posicion,
                'equipo_camping' => false,
                'estado_permiso' => $estado === EstadoReserva::SinPermiso ? EstadoPermiso::NoObtenido : EstadoPermiso::Obtenido,
            ]);
        }
    }
}
