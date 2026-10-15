<?php

namespace Tests\Feature;

use App\Enums\EstadoPaquete;
use App\Enums\Rol;
use App\Filament\Resources\Excursiones\ExcursionResource;
use App\Filament\Resources\Excursiones\Pages\ListarIntegrantesExcursion;
use App\Filament\Resources\Excursiones\Pages\ListExcursiones;
use App\Models\Excursion;
use App\Models\Guia;
use App\Models\Paquete;
use App\Models\Recorrido;
use App\Models\Usuario;
use DomainException;
use Filament\Actions\Testing\TestAction;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class IniciarRecorridoTest extends TestCase
{
    use RefreshDatabase;

    private Usuario $administrador;

    private Usuario $usuarioGuia;

    private Usuario $otroUsuarioGuia;

    private Paquete $paquete;

    // Las tres de Rosa Quispe: la que sale hoy, la del lunes que viene y la del lunes pasado, que no se inició.
    private Excursion $excursionDeHoy;

    private Excursion $excursionProxima;

    private Excursion $excursionPasada;

    // De Andrés Mamani, también sale hoy.
    private Excursion $excursionDeOtroGuia;

    protected function setUp(): void
    {
        parent::setUp();

        // Lunes: las salidas son siempre lunes.
        $this->travelTo('2026-10-19 08:30:00');

        $this->crearDatos();
    }

    /* ------------------------------ Curso normal ------------------------------ */

    public function test_el_guia_inicia_el_recorrido_de_su_excursion_el_dia_de_salida(): void
    {
        $this->listado()
            ->assertTableActionVisible('iniciarRecorrido', $this->excursionDeHoy)
            ->callAction($this->accion($this->excursionDeHoy))
            ->assertNotified('Recorrido iniciado')
            ->assertTableActionHidden('iniciarRecorrido', $this->excursionDeHoy);

        $excursion = $this->excursionDeHoy->fresh();

        $this->assertSame('2026-10-19 08:30:00', $excursion->fecha_hora_inicio_recorrido->format('Y-m-d H:i:s'));
        $this->assertNull($excursion->id_etapa_actual);
    }

    public function test_el_aviso_dice_la_fecha_y_la_hora_del_inicio(): void
    {
        $this->listado()
            ->callAction($this->accion($this->excursionDeHoy))
            ->assertNotified(Notification::make()
                ->title('Recorrido iniciado')
                ->body('Camino Inca Clásico · salida 19/10/2026: el recorrido quedó iniciado el 19/10/2026 a las 08:30.')
                ->success());
    }

    public function test_la_confirmacion_muestra_el_paquete_el_recorrido_y_la_salida(): void
    {
        $this->listado()
            ->mountAction($this->accion($this->excursionDeHoy))
            ->assertMountedActionModalSee('Paquete Camino Inca Clásico · Recorrido Camino Clásico · Salida 19/10/2026');
    }

    public function test_la_tabla_muestra_el_inicio_del_recorrido(): void
    {
        $this->listado()
            ->assertTableColumnStateSet('fecha_hora_inicio_recorrido', null, $this->excursionDeHoy)
            ->callAction($this->accion($this->excursionDeHoy))
            ->assertTableColumnFormattedStateSet('fecha_hora_inicio_recorrido', '19/10/2026 08:30', $this->excursionDeHoy);
    }

    // Nada en el diseño lo prohíbe: la de hoy no tiene reservas.
    public function test_se_puede_iniciar_una_excursion_sin_integrantes_confirmados(): void
    {
        $this->assertSame(0, $this->excursionDeHoy->contarIntegrantesConfirmados());

        $this->listado()->callAction($this->accion($this->excursionDeHoy));

        $this->assertTrue($this->excursionDeHoy->fresh()->recorridoIniciado());
    }

    public function test_el_guia_inicia_el_recorrido_desde_la_pagina_de_integrantes(): void
    {
        $this->paginaIntegrantes($this->excursionDeHoy)
            ->assertActionVisible('iniciarRecorrido')
            ->callAction('iniciarRecorrido')
            ->assertNotified('Recorrido iniciado')
            ->assertActionHidden('iniciarRecorrido');

        $this->assertSame('2026-10-19 08:30:00', $this->excursionDeHoy->fresh()->fecha_hora_inicio_recorrido->format('Y-m-d H:i:s'));
    }

    /* ----------------------------------- A1 ----------------------------------- */

    public function test_un_recorrido_ya_iniciado_no_muestra_la_accion(): void
    {
        $this->excursionDeHoy->update(['fecha_hora_inicio_recorrido' => '2026-10-19 07:00:00']);

        $this->listado()->assertTableActionHidden('iniciarRecorrido', $this->excursionDeHoy);
        $this->paginaIntegrantes($this->excursionDeHoy)->assertActionHidden('iniciarRecorrido');
    }

    public function test_un_recorrido_ya_iniciado_no_cambia_la_hora_original(): void
    {
        $this->excursionDeHoy->update(['fecha_hora_inicio_recorrido' => '2026-10-19 07:00:00']);

        try {
            $this->excursionDeHoy->iniciarRecorrido();
            $this->fail('Tenía que avisar que el recorrido ya se inició.');
        } catch (DomainException $excepcion) {
            $this->assertSame('El recorrido ya se inició el 19/10/2026 a las 07:00.', $excepcion->getMessage());
        }

        $this->assertSame('2026-10-19 07:00:00', $this->excursionDeHoy->fresh()->fecha_hora_inicio_recorrido->format('Y-m-d H:i:s'));
    }

    // La confirmación estaba abierta y, mientras tanto, en otra pestaña el guía ya lo inició. Al confirmar, Filament vuelve
    // a leer la excursión y a evaluar visible(): la acción ya no corresponde y no se ejecuta.
    public function test_si_se_inicio_en_otra_pestana_no_cambia_la_hora(): void
    {
        $listado = $this->listado()->mountAction($this->accion($this->excursionDeHoy));

        $this->travelTo('2026-10-19 08:45:00');
        $this->excursionDeHoy->iniciarRecorrido();
        $this->travelTo('2026-10-19 08:50:00');

        $listado->callMountedAction()->assertNotNotified('Recorrido iniciado');

        $this->assertSame('2026-10-19 08:45:00', $this->excursionDeHoy->fresh()->fecha_hora_inicio_recorrido->format('Y-m-d H:i:s'));
    }

    /* ----------------------------------- A2 ----------------------------------- */

    public function test_antes_del_dia_de_salida_no_muestra_la_accion(): void
    {
        $this->listado()->assertTableActionHidden('iniciarRecorrido', $this->excursionProxima);
        $this->paginaIntegrantes($this->excursionProxima)->assertActionHidden('iniciarRecorrido');
    }

    public function test_despues_del_dia_de_salida_no_muestra_la_accion(): void
    {
        $this->listado()->assertTableActionHidden('iniciarRecorrido', $this->excursionPasada);
    }

    public function test_antes_del_dia_de_salida_no_se_registra(): void
    {
        $this->assertNoSeInicia($this->excursionProxima, 'El recorrido sólo se puede iniciar el día de la salida (26/10/2026).');
    }

    public function test_despues_del_dia_de_salida_no_se_registra(): void
    {
        $this->assertNoSeInicia($this->excursionPasada, 'El recorrido sólo se puede iniciar el día de la salida (12/10/2026).');
    }

    // La confirmación se abrió el lunes a la noche y el guía confirma pasada la medianoche: ya es martes.
    public function test_si_confirma_pasada_la_medianoche_del_dia_de_salida_no_se_registra(): void
    {
        $this->travelTo('2026-10-19 23:58:00');
        $listado = $this->listado()->mountAction($this->accion($this->excursionDeHoy));

        $this->travelTo('2026-10-20 00:01:00');
        $listado->callMountedAction()->assertNotNotified('Recorrido iniciado');

        $this->assertNull($this->excursionDeHoy->fresh()->fecha_hora_inicio_recorrido);
    }

    // Al revés: la lista se abrió el domingo a la noche y el lunes ya se puede, sin recargar.
    public function test_el_dia_se_controla_en_cada_pedido_y_no_al_abrir_la_lista(): void
    {
        $this->travelTo('2026-10-25 23:58:00');
        $listado = $this->listado();
        $listado->assertTableActionHidden('iniciarRecorrido', $this->excursionProxima);

        $this->travelTo('2026-10-26 00:01:00');
        $listado->callAction($this->accion($this->excursionProxima))->assertNotified('Recorrido iniciado');

        $this->assertSame('2026-10-26 00:01:00', $this->excursionProxima->fresh()->fecha_hora_inicio_recorrido->format('Y-m-d H:i:s'));
    }

    /* ----------------------------------- A3 ----------------------------------- */

    public function test_si_el_guia_cancela_la_confirmacion_no_cambia_nada(): void
    {
        $this->listado()
            ->mountAction($this->accion($this->excursionDeHoy))
            ->call('unmountAction')
            ->assertNotNotified();

        $this->assertNull($this->excursionDeHoy->fresh()->fecha_hora_inicio_recorrido);
    }

    /* --------------------------------- Acceso --------------------------------- */

    public function test_el_guia_no_puede_iniciar_la_excursion_de_otro_guia(): void
    {
        // Aunque arme el pedido a mano con la clave de la otra excursión, Filament la busca en la consulta del recurso, que
        // sólo tiene las suyas: no la encuentra y no monta la acción.
        $this->listado()
            ->assertCanNotSeeTableRecords([$this->excursionDeOtroGuia])
            ->call('mountAction', 'iniciarRecorrido', [], ['table' => true, 'recordKey' => (string) $this->excursionDeOtroGuia->getKey()])
            ->assertSet('mountedActions', [])
            ->call('callMountedAction')
            ->assertNotNotified('Recorrido iniciado');

        $this->assertNull($this->excursionDeOtroGuia->fresh()->fecha_hora_inicio_recorrido);

        $this->actingAs($this->usuarioGuia)
            ->get(ExcursionResource::getUrl('integrantes', ['record' => $this->excursionDeOtroGuia]))
            ->assertNotFound();
    }

    public function test_el_otro_guia_si_puede_iniciar_la_suya(): void
    {
        $this->actingAs($this->otroUsuarioGuia);

        Livewire::test(ListExcursiones::class)
            ->assertTableActionVisible('iniciarRecorrido', $this->excursionDeOtroGuia)
            ->callAction($this->accion($this->excursionDeOtroGuia))
            ->assertNotified('Recorrido iniciado');

        $this->assertTrue($this->excursionDeOtroGuia->fresh()->recorridoIniciado());
        $this->assertNull($this->excursionDeHoy->fresh()->fecha_hora_inicio_recorrido);
    }

    public function test_el_administrador_no_ve_ni_ejecuta_la_accion(): void
    {
        $this->actingAs($this->administrador);

        $this->get(ExcursionResource::getUrl('index'))->assertForbidden();
        $this->get(ExcursionResource::getUrl('integrantes', ['record' => $this->excursionDeHoy]))->assertForbidden();

        // La consulta con la que la acción busca la excursión no tiene ninguna para el administrador.
        $this->assertNull(ExcursionResource::getEloquentQuery()->find($this->excursionDeHoy->getKey()));

        $this->assertNull($this->excursionDeHoy->fresh()->fecha_hora_inicio_recorrido);
    }

    /* ------------------------------ Dos pedidos ------------------------------- */

    public function test_iniciar_dos_veces_seguidas_no_pisa_la_primera_hora(): void
    {
        $this->excursionDeHoy->iniciarRecorrido();

        $this->travelTo('2026-10-19 09:15:00');

        try {
            $this->excursionDeHoy->fresh()->iniciarRecorrido();
            $this->fail('Tenía que avisar que el recorrido ya se inició.');
        } catch (DomainException) {
        }

        // También desde una copia vieja de la excursión, que todavía no sabe que se inició.
        try {
            Excursion::find($this->excursionDeHoy->getKey())->forceFill(['fecha_hora_inicio_recorrido' => null])->iniciarRecorrido();
            $this->fail('Tenía que avisar que el recorrido ya se inició.');
        } catch (DomainException) {
        }

        $this->assertSame('2026-10-19 08:30:00', $this->excursionDeHoy->fresh()->fecha_hora_inicio_recorrido->format('Y-m-d H:i:s'));
    }

    /* --------------------------------- Modelo --------------------------------- */

    public function test_el_recorrido_no_esta_iniciado_mientras_no_tenga_fecha_y_hora_de_inicio(): void
    {
        $this->assertFalse($this->excursionDeHoy->recorridoIniciado());

        $this->excursionDeHoy->fecha_hora_inicio_recorrido = '2026-10-19 07:00:00';

        $this->assertTrue($this->excursionDeHoy->recorridoIniciado());
    }

    public function test_es_dia_de_salida_a_cualquier_hora_de_ese_dia(): void
    {
        $this->assertTrue($this->excursionDeHoy->esDiaDeSalida('2026-10-19 00:00:00'));
        $this->assertTrue($this->excursionDeHoy->esDiaDeSalida('2026-10-19 23:59:59'));
        $this->assertTrue($this->excursionDeHoy->esDiaDeSalida(now()));
    }

    public function test_no_es_dia_de_salida_el_dia_anterior_ni_el_siguiente(): void
    {
        $this->assertFalse($this->excursionDeHoy->esDiaDeSalida('2026-10-18 23:59:59'));
        $this->assertFalse($this->excursionDeHoy->esDiaDeSalida('2026-10-20 00:00:00'));
    }

    public function test_admite_el_inicio_solo_el_dia_de_salida_y_sin_iniciar(): void
    {
        $this->assertTrue($this->excursionDeHoy->admiteInicioRecorrido(now()));
        $this->assertFalse($this->excursionProxima->admiteInicioRecorrido(now()));
        $this->assertFalse($this->excursionPasada->admiteInicioRecorrido(now()));

        $this->excursionDeHoy->fecha_hora_inicio_recorrido = '2026-10-19 07:00:00';

        $this->assertFalse($this->excursionDeHoy->admiteInicioRecorrido(now()));
    }

    public function test_iniciar_recorrido_actualiza_la_misma_instancia(): void
    {
        $this->excursionDeHoy->iniciarRecorrido();

        $this->assertTrue($this->excursionDeHoy->recorridoIniciado());
        $this->assertSame('2026-10-19 08:30:00', $this->excursionDeHoy->fecha_hora_inicio_recorrido->format('Y-m-d H:i:s'));
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
        return TestAction::make('iniciarRecorrido')->table($excursion);
    }

    private function assertNoSeInicia(Excursion $excursion, string $aviso): void
    {
        try {
            $excursion->iniciarRecorrido();
            $this->fail('Tenía que avisar que no es el día de la salida.');
        } catch (DomainException $excepcion) {
            $this->assertSame($aviso, $excepcion->getMessage());
        }

        $this->assertNull($excursion->fresh()->fecha_hora_inicio_recorrido);
    }

    private function crearDatos(): void
    {
        $this->administrador = Usuario::create(['correo' => 'admin@caminodelinca.test', 'password' => 'admin1234', 'rol' => Rol::Administrador]);
        $this->usuarioGuia = $this->crearGuia('guia@caminodelinca.test', 'Rosa', 'Quispe');
        $this->otroUsuarioGuia = $this->crearGuia('luko@caminodelinca.test', 'Andrés', 'Mamani');

        $recorrido = Recorrido::create(['nombre' => 'Camino Clásico', 'duracion_dias' => 4, 'cantidad_campings' => 3]);

        $this->paquete = $this->crearPaquete($recorrido, 'Camino Inca Clásico');
        $paqueteCorto = $this->crearPaquete($recorrido, 'Camino Inca Corto');

        $this->excursionDeHoy = $this->crearExcursion($this->paquete, '2026-10-19', $this->usuarioGuia);
        $this->excursionProxima = $this->crearExcursion($this->paquete, '2026-10-26', $this->usuarioGuia);
        $this->excursionPasada = $this->crearExcursion($this->paquete, '2026-10-12', $this->usuarioGuia);
        $this->excursionDeOtroGuia = $this->crearExcursion($paqueteCorto, '2026-10-19', $this->otroUsuarioGuia);
    }

    private function crearGuia(string $correo, string $nombre, string $apellido): Usuario
    {
        $usuario = Usuario::create(['correo' => $correo, 'password' => 'guia1234', 'rol' => Rol::Guia]);
        Guia::create(['id_usuario' => $usuario->id_usuario, 'nombre' => $nombre, 'apellido' => $apellido]);

        return $usuario;
    }

    private function crearPaquete(Recorrido $recorrido, string $nombre): Paquete
    {
        return Paquete::create([
            'id_recorrido' => $recorrido->id_recorrido,
            'nombre' => $nombre,
            'precio_base' => 750,
            'costo_noche_extra_cusco' => 60,
            'costo_equipo_camping' => 45,
            'cantidad_porteadores' => 2,
            'estado' => EstadoPaquete::Activo,
            'fecha_creacion' => '2026-01-05',
        ]);
    }

    private function crearExcursion(Paquete $paquete, string $fechaSalida, Usuario $usuarioGuia): Excursion
    {
        return Excursion::create([
            'id_paquete' => $paquete->id_paquete,
            'id_guia' => $usuarioGuia->id_usuario,
            'fecha_salida' => $fechaSalida,
            'cupo' => 12,
            'plazas_retenidas' => 0,
        ]);
    }
}
