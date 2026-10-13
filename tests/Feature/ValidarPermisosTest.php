<?php

namespace Tests\Feature;

use App\Enums\EstadoPaquete;
use App\Enums\EstadoPermiso;
use App\Enums\EstadoReserva;
use App\Enums\EstadoSaldo;
use App\Enums\Rol;
use App\Filament\Resources\Reservas\Pages\ListReservas;
use App\Filament\Resources\Reservas\Pages\ValidarPermisosReserva;
use App\Filament\Resources\Reservas\Pages\ViewReserva;
use App\Filament\Resources\Reservas\ReservaResource;
use App\Models\Excursion;
use App\Models\Excursionista;
use App\Models\Guia;
use App\Models\Paquete;
use App\Models\Recorrido;
use App\Models\Reserva;
use App\Models\Usuario;
use DomainException;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ValidarPermisosTest extends TestCase
{
    use RefreshDatabase;

    // Los documentos de los tres integrantes de la reserva 000124-7, como en los seeders.
    private const DOCUMENTOS = ['AAG604118', 'AAG604119', 'AAG611025'];

    private Usuario $administrador;

    private Usuario $usuarioGuia;

    private Excursion $excursion;

    private Reserva $reserva;

    protected function setUp(): void
    {
        parent::setUp();

        // La fecha límite de confirmación de la reserva es el 18/12/2026 a las 23:59:59.
        $this->travelTo('2026-10-13 10:00:00');

        $this->crearDatos();
    }

    /* ------------------------------ Curso normal ------------------------------ */

    public function test_si_todos_obtuvieron_el_permiso_la_reserva_queda_confirmada(): void
    {
        $this->abrirPagina()
            ->assertSee('Lucía Fernández')
            ->assertSee('AAG611025')
            ->set('marcas.0', EstadoPermiso::Obtenido->value)
            ->set('marcas.1', EstadoPermiso::Obtenido->value)
            ->set('marcas.2', EstadoPermiso::Obtenido->value)
            ->assertSee('todos los integrantes obtuvieron el permiso')
            ->callAction($this->accionConfirmar())
            ->assertHasNoErrors()
            ->assertNotified('Reserva 000124-7 en «Confirmada».')
            ->assertRedirect(ReservaResource::getUrl('view', ['record' => $this->reserva]));

        $this->assertSame(EstadoReserva::Confirmada, $this->reserva->fresh()->estado);
        $this->assertPermisos([EstadoPermiso::Obtenido, EstadoPermiso::Obtenido, EstadoPermiso::Obtenido]);
    }

    /* ----------------------------------- A1 ----------------------------------- */

    public function test_si_uno_no_obtuvo_el_permiso_la_reserva_completa_queda_sin_permiso(): void
    {
        $this->abrirPagina()
            ->set('marcas.0', EstadoPermiso::Obtenido->value)
            ->set('marcas.1', EstadoPermiso::NoObtenido->value)
            ->set('marcas.2', EstadoPermiso::Obtenido->value)
            ->assertSee('Resultado: 1 integrante(s) sin permiso. Al confirmar, la reserva completa pasa a «Sin Permiso».')
            ->callAction($this->accionConfirmar())
            ->assertNotified('Reserva 000124-7 en «Sin Permiso».')
            ->assertRedirect(ReservaResource::getUrl('view', ['record' => $this->reserva]));

        $this->assertSame(EstadoReserva::SinPermiso, $this->reserva->fresh()->estado);
        $this->assertPermisos([EstadoPermiso::Obtenido, EstadoPermiso::NoObtenido, EstadoPermiso::Obtenido]);
    }

    /* ----------------------------------- A2 ----------------------------------- */

    public function test_cancelar_no_guarda_nada(): void
    {
        $this->abrirPagina()
            ->set('marcas.0', EstadoPermiso::Obtenido->value)
            ->set('marcas.1', EstadoPermiso::NoObtenido->value)
            ->set('marcas.2', EstadoPermiso::Obtenido->value)
            ->assertActionHasUrl($this->accion('cancelar'), ReservaResource::getUrl('view', ['record' => $this->reserva]));

        // Marcar no escribe en la base: sin confirmar, todo sigue como estaba.
        $this->assertSame(EstadoReserva::Pendiente, $this->reserva->fresh()->estado);
        $this->assertPermisos([EstadoPermiso::Pendiente, EstadoPermiso::Pendiente, EstadoPermiso::Pendiente]);
    }

    /* ----------------------------------- A3 ----------------------------------- */

    public static function estadosQueNoAdmitenValidacion(): array
    {
        return [
            'Confirmada' => [EstadoReserva::Confirmada],
            'Cancelada' => [EstadoReserva::Cancelada],
            'Sin Permiso' => [EstadoReserva::SinPermiso],
        ];
    }

    #[DataProvider('estadosQueNoAdmitenValidacion')]
    public function test_una_reserva_que_no_esta_pendiente_no_admite_validacion(EstadoReserva $estado): void
    {
        $this->reserva->update(['estado' => $estado]);

        $this->abrirPagina()
            ->assertSee('La reserva 000124-7 está en «'.$estado->value.'» y ya no admite validación de permisos.')
            ->assertDontSee('Lucía Fernández')
            ->assertDontSee('Confirmar validación')
            ->call('confirmarValidacionPermisos');

        $this->assertSame($estado, $this->reserva->fresh()->estado);
        $this->assertPermisos([EstadoPermiso::Pendiente, EstadoPermiso::Pendiente, EstadoPermiso::Pendiente]);
    }

    #[DataProvider('estadosQueNoAdmitenValidacion')]
    public function test_el_modelo_rechaza_validar_una_reserva_que_no_esta_pendiente(EstadoReserva $estado): void
    {
        $this->reserva->update(['estado' => $estado]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('ya no admite validación de permisos');

        $this->reserva->fresh()->iniciarValidacionPermisos();
    }

    #[DataProvider('estadosQueNoAdmitenValidacion')]
    public function test_el_modelo_no_registra_permisos_si_la_reserva_no_esta_pendiente(EstadoReserva $estado): void
    {
        $this->reserva->update(['estado' => $estado]);
        $reserva = $this->reserva->fresh();

        try {
            $reserva->registrarPermiso('AAG604118', EstadoPermiso::Obtenido);
            $this->fail('Se registró un permiso en una reserva «'.$estado->value.'».');
        } catch (DomainException $excepcion) {
            $this->assertStringContainsString('ya no admite validación de permisos', $excepcion->getMessage());
        }

        $this->assertPermisos([EstadoPermiso::Pendiente, EstadoPermiso::Pendiente, EstadoPermiso::Pendiente]);
    }

    #[DataProvider('estadosQueNoAdmitenValidacion')]
    public function test_el_modelo_no_cambia_el_estado_si_la_reserva_no_esta_pendiente(EstadoReserva $estado): void
    {
        // Todos los permisos ya registrados: lo único que frena el cambio de estado es el estado de la reserva.
        Excursionista::where('id_reserva', $this->reserva->id_reserva)->update(['estado_permiso' => EstadoPermiso::Obtenido]);
        $this->reserva->update(['estado' => $estado]);

        try {
            $this->reserva->fresh()->ejecutarValidacionPermisos();
            $this->fail('Una reserva «'.$estado->value.'» cambió de estado.');
        } catch (DomainException $excepcion) {
            $this->assertStringContainsString('ya no admite validación de permisos', $excepcion->getMessage());
        }

        $this->assertSame($estado, $this->reserva->fresh()->estado);
    }

    /* ---------------------- Fecha límite de confirmación ---------------------- */

    public function test_con_el_plazo_de_confirmacion_vencido_no_se_valida(): void
    {
        $this->travelTo('2026-12-19 00:00:00');

        $this->abrirPagina()
            ->assertSee('El plazo para confirmar los permisos de la reserva 000124-7 venció el 18/12/2026: ya no admite validación de permisos.')
            ->assertDontSee('Confirmar validación')
            ->call('confirmarValidacionPermisos');

        $this->assertSame(EstadoReserva::Pendiente, $this->reserva->fresh()->estado);
    }

    public function test_el_ultimo_segundo_del_plazo_todavia_se_puede_validar(): void
    {
        $this->travelTo('2026-12-18 23:59:59');

        $this->assertCount(3, $this->reserva->iniciarValidacionPermisos());
    }

    public function test_el_modelo_no_registra_ni_confirma_con_el_plazo_vencido(): void
    {
        $this->travelTo('2026-12-19 00:00:00');

        foreach ([
            fn () => $this->reserva->registrarPermiso('AAG604118', EstadoPermiso::Obtenido),
            fn () => $this->reserva->ejecutarValidacionPermisos(),
        ] as $paso) {
            try {
                $paso();
                $this->fail('Se validó una reserva con el plazo vencido.');
            } catch (DomainException $excepcion) {
                $this->assertStringContainsString('venció el 18/12/2026', $excepcion->getMessage());
            }
        }

        $this->assertSame(EstadoReserva::Pendiente, $this->reserva->fresh()->estado);
        $this->assertPermisos([EstadoPermiso::Pendiente, EstadoPermiso::Pendiente, EstadoPermiso::Pendiente]);
    }

    public function test_si_el_plazo_vence_con_la_pagina_abierta_no_se_valida(): void
    {
        $pagina = $this->abrirPagina();
        $this->marcarTodos($pagina, EstadoPermiso::Obtenido);

        $this->travelTo('2026-12-19 00:00:00');
        $pagina->call('confirmarValidacionPermisos')
            ->assertNotified('No se validaron los permisos');

        $this->assertSame(EstadoReserva::Pendiente, $this->reserva->fresh()->estado);
        $this->assertPermisos([EstadoPermiso::Pendiente, EstadoPermiso::Pendiente, EstadoPermiso::Pendiente]);
    }

    /* ------------------------- Validación en el servidor ---------------------- */

    public function test_no_se_puede_confirmar_si_falta_marcar_a_un_integrante(): void
    {
        $this->abrirPagina()
            ->set('marcas.0', EstadoPermiso::Obtenido->value)
            ->set('marcas.1', EstadoPermiso::Obtenido->value)
            ->assertSee('Resultado: falta registrar el permiso de 1 integrante(s).')
            ->call('confirmarValidacionPermisos')
            ->assertNotified('Falta registrar el permiso de 1 integrante(s).')
            ->assertNoRedirect();

        $this->assertSame(EstadoReserva::Pendiente, $this->reserva->fresh()->estado);
        $this->assertPermisos([EstadoPermiso::Pendiente, EstadoPermiso::Pendiente, EstadoPermiso::Pendiente]);
    }

    public function test_el_modelo_no_admite_confirmaciones_parciales(): void
    {
        $this->reserva->registrarPermiso('AAG604118', EstadoPermiso::Obtenido);

        try {
            $this->reserva->ejecutarValidacionPermisos();
            $this->fail('Se confirmó la validación con integrantes sin registrar.');
        } catch (DomainException $excepcion) {
            $this->assertStringContainsString('Falta registrar el permiso', $excepcion->getMessage());
        }

        $this->assertSame(EstadoReserva::Pendiente, $this->reserva->fresh()->estado);
    }

    public function test_un_documento_de_otra_reserva_se_rechaza(): void
    {
        $this->abrirPagina()
            ->call('procesarRegistroPermiso', 'AAZ000001', EstadoPermiso::Obtenido->value)
            ->assertHasErrors(['marcas'])
            ->assertSet('permisos', []);
    }

    public function test_el_modelo_rechaza_un_documento_de_otra_reserva(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('El documento AAZ000001 no es de un integrante de la reserva 000124-7.');

        $this->reserva->registrarPermiso('AAZ000001', EstadoPermiso::Obtenido);
    }

    // Documentos que PHP, comparando con ==, toma por el mismo número. CU-14 los admite: sólo pide letras y números.
    public function test_documentos_numericos_parecidos_no_se_confunden(): void
    {
        $reserva = $this->crearReserva('000126-0', EstadoReserva::Pendiente, ['012345', '12345'], [['Ana', 'Paz'], ['Luis', 'Paz']]);

        $this->actingAs($this->administrador);

        Livewire::test(ValidarPermisosReserva::class, ['record' => $reserva->getRouteKey()])
            ->assertSet('integrantes.0.documento_pasaporte', '012345')
            ->set('marcas.0', EstadoPermiso::NoObtenido->value)
            ->set('marcas.1', EstadoPermiso::Obtenido->value)
            ->assertHasNoErrors()
            ->call('confirmarValidacionPermisos')
            ->assertNotified('Reserva 000126-0 en «Sin Permiso».');

        $guardados = Excursionista::where('id_reserva', $reserva->id_reserva)->pluck('estado_permiso', 'documento_pasaporte');
        $this->assertSame(EstadoPermiso::NoObtenido, $guardados['012345']);
        $this->assertSame(EstadoPermiso::Obtenido, $guardados['12345']);
    }

    // CU-14 no deja cargar puntos, pero la página no depende de eso: el campo se llama con la posición del integrante.
    public function test_un_documento_con_punto_no_rompe_la_pantalla(): void
    {
        $reserva = $this->crearReserva('000126-0', EstadoReserva::Pendiente, ['AB.123'], [['Ana', 'Paz']]);

        $this->actingAs($this->administrador);

        Livewire::test(ValidarPermisosReserva::class, ['record' => $reserva->getRouteKey()])
            ->set('marcas.0', EstadoPermiso::Obtenido->value)
            ->assertHasNoErrors()
            ->assertSet('permisos', ['AB.123' => EstadoPermiso::Obtenido->value])
            ->call('confirmarValidacionPermisos')
            ->assertNotified('Reserva 000126-0 en «Confirmada».');

        $this->assertSame(EstadoPermiso::Obtenido, Excursionista::where('id_reserva', $reserva->id_reserva)->value('estado_permiso'));
    }

    public function test_el_modelo_rechaza_un_documento_que_solo_es_igual_como_numero(): void
    {
        $reserva = $this->crearReserva('000126-0', EstadoReserva::Pendiente, ['012345']);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('El documento 12345 no es de un integrante de la reserva 000126-0.');

        $reserva->registrarPermiso('12345', EstadoPermiso::Obtenido);
    }

    public function test_el_valor_pendiente_se_rechaza(): void
    {
        $this->abrirPagina()
            ->set('marcas.0', EstadoPermiso::Pendiente->value)
            ->assertHasErrors(['marcas.0'])
            ->call('procesarRegistroPermiso', 'AAG604119', EstadoPermiso::Pendiente->value)
            ->assertHasErrors(['marcas.1'])
            ->call('procesarRegistroPermiso', 'AAG611025', 'Aprobado')
            ->assertHasErrors(['marcas.2'])
            ->assertSet('permisos', []);
    }

    public function test_el_modelo_rechaza_el_valor_pendiente(): void
    {
        $this->expectException(DomainException::class);

        $this->reserva->registrarPermiso('AAG604118', EstadoPermiso::Pendiente);
    }

    public function test_los_permisos_registrados_no_se_pueden_cambiar_desde_el_navegador(): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);

        $this->abrirPagina()->set('permisos.AAZ000001', EstadoPermiso::Obtenido->value);
    }

    /* ------------------------------ Concurrencia ------------------------------ */

    public function test_si_la_reserva_se_cancela_con_la_pagina_abierta_no_se_valida_nada(): void
    {
        $pagina = $this->abrirPagina();
        $this->marcarTodos($pagina, EstadoPermiso::Obtenido);

        // Mientras tanto, el cliente la cancela (CU-21) o la cancela el sistema (CU-26).
        Reserva::whereKey($this->reserva->id_reserva)->update(['estado' => EstadoReserva::Cancelada]);

        $pagina->call('confirmarValidacionPermisos')
            ->assertNotified('No se validaron los permisos')
            ->assertNoRedirect()
            ->assertSet('aviso', 'La reserva 000124-7 está en «Cancelada» y ya no admite validación de permisos.');

        $this->assertSame(EstadoReserva::Cancelada, $this->reserva->fresh()->estado);
        $this->assertPermisos([EstadoPermiso::Pendiente, EstadoPermiso::Pendiente, EstadoPermiso::Pendiente]);
    }

    public function test_si_la_validacion_llega_dos_veces_la_segunda_no_avisa_un_error(): void
    {
        // Dos pestañas abiertas sobre la misma reserva, o un doble clic: la segunda confirmación llega con la reserva ya
        // validada por la primera.
        $primera = $this->abrirPagina();
        $segunda = $this->abrirPagina();
        $this->marcarTodos($primera, EstadoPermiso::Obtenido);
        $this->marcarTodos($segunda, EstadoPermiso::NoObtenido);

        $primera->call('confirmarValidacionPermisos');

        $segunda->call('confirmarValidacionPermisos')
            ->assertNotified('Los permisos de la reserva 000124-7 ya se validaron: está en «Confirmada».')
            ->assertRedirect(ReservaResource::getUrl('view', ['record' => $this->reserva]));

        // Queda lo que guardó la primera: la segunda no pisó nada.
        $this->assertSame(EstadoReserva::Confirmada, $this->reserva->fresh()->estado);
        $this->assertPermisos([EstadoPermiso::Obtenido, EstadoPermiso::Obtenido, EstadoPermiso::Obtenido]);
    }

    /* -------------------------- Resultado anticipado -------------------------- */

    public function test_el_resultado_anticipado_usa_la_misma_regla_que_la_validacion(): void
    {
        $this->assertSame(EstadoReserva::Confirmada, Reserva::anticiparResultadoValidacion([EstadoPermiso::Obtenido, EstadoPermiso::Obtenido]));
        $this->assertSame(EstadoReserva::SinPermiso, Reserva::anticiparResultadoValidacion([EstadoPermiso::Obtenido, EstadoPermiso::NoObtenido]));

        // Como en todosLosPermisosObtenidos(), sólo «Obtenido» cuenta como permiso obtenido.
        $this->assertSame(EstadoReserva::SinPermiso, Reserva::anticiparResultadoValidacion([EstadoPermiso::Obtenido, EstadoPermiso::Pendiente]));
    }

    public function test_marcar_un_integrante_no_vuelve_a_consultar_la_excursion_ni_el_paquete(): void
    {
        $pagina = $this->abrirPagina();

        DB::enableQueryLog();
        $pagina->set('marcas.0', EstadoPermiso::Obtenido->value)
            ->assertSee('Camino Inca Clásico')
            ->assertSee('18/01/2027');
        $consultas = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        $this->assertEmpty($consultas->filter(fn (string $consulta) => str_contains($consulta, '"excursion"') || str_contains($consulta, '"paquete"')));
    }

    /* --------------------------------- Acceso --------------------------------- */

    public function test_un_guia_no_puede_entrar_a_la_pagina(): void
    {
        $this->actingAs($this->usuarioGuia)
            ->get(ReservaResource::getUrl('permisos', ['record' => $this->reserva]))
            ->assertForbidden();
    }

    public function test_el_administrador_entra_a_la_pagina(): void
    {
        $this->actingAs($this->administrador)
            ->get(ReservaResource::getUrl('permisos', ['record' => $this->reserva]))
            ->assertOk()
            ->assertSee('Validar permisos · Reserva 000124-7');
    }

    public function test_la_accion_validar_permisos_solo_se_ve_con_la_reserva_pendiente(): void
    {
        $confirmada = $this->crearReserva('000125-9', EstadoReserva::Confirmada, ['AAH715300']);

        $this->actingAs($this->administrador);

        Livewire::test(ListReservas::class)
            ->assertTableActionVisible('validarPermisos', $this->reserva)
            ->assertTableActionHidden('validarPermisos', $confirmada);

        Livewire::test(ViewReserva::class, ['record' => $this->reserva->getRouteKey()])
            ->assertActionVisible('validarPermisos')
            ->assertActionHasUrl('validarPermisos', ReservaResource::getUrl('permisos', ['record' => $this->reserva]));

        Livewire::test(ViewReserva::class, ['record' => $confirmada->getRouteKey()])
            ->assertActionHidden('validarPermisos');
    }

    /* ---------------------------------- Cupo ---------------------------------- */

    public function test_el_cupo_disponible_no_cambia(): void
    {
        $cupoAntes = $this->excursion->obtenerCupoDisponible();

        $pagina = $this->abrirPagina();
        $this->marcarTodos($pagina, EstadoPermiso::NoObtenido);
        $pagina->callAction($this->accionConfirmar());

        $this->assertSame(EstadoReserva::SinPermiso, $this->reserva->fresh()->estado);
        $this->assertSame($cupoAntes, $this->excursion->fresh()->obtenerCupoDisponible());
    }

    /* -------------------------------- Ayudantes ------------------------------- */

    private function abrirPagina()
    {
        $this->actingAs($this->administrador);

        $pagina = Livewire::test(ValidarPermisosReserva::class, ['record' => $this->reserva->getRouteKey()]);

        // Cada campo se llama con la posición del integrante: marcas.0 es el primero de self::DOCUMENTOS, y así. Con el
        // aviso de A3 no hay integrantes.
        if ($pagina->get('aviso') === null) {
            $this->assertSame(self::DOCUMENTOS, array_column($pagina->get('integrantes'), 'documento_pasaporte'));
        }

        return $pagina;
    }

    private function marcarTodos($pagina, EstadoPermiso $estadoPermiso): void
    {
        foreach (array_keys(self::DOCUMENTOS) as $posicion) {
            $pagina->set('marcas.'.$posicion, $estadoPermiso->value);
        }
    }

    private function accion(string $nombre): TestAction
    {
        return TestAction::make($nombre)->schemaComponent('acciones', 'content');
    }

    private function accionConfirmar(): TestAction
    {
        return $this->accion('confirmar');
    }

    // Los permisos guardados, en el orden de self::DOCUMENTOS.
    private function assertPermisos(array $esperados): void
    {
        $guardados = Excursionista::where('id_reserva', $this->reserva->id_reserva)
            ->pluck('estado_permiso', 'documento_pasaporte');

        foreach (self::DOCUMENTOS as $posicion => $documento) {
            $this->assertSame($esperados[$posicion], $guardados[$documento], 'Permiso de '.$documento);
        }
    }

    private function crearDatos(): void
    {
        $this->administrador = Usuario::create(['correo' => 'admin@caminodelinca.test', 'password' => 'admin1234', 'rol' => Rol::Administrador]);

        $this->usuarioGuia = Usuario::create(['correo' => 'guia@caminodelinca.test', 'password' => 'guia1234', 'rol' => Rol::Guia]);
        Guia::create(['id_usuario' => $this->usuarioGuia->id_usuario, 'nombre' => 'Rosa', 'apellido' => 'Quispe']);

        $recorrido = Recorrido::create(['nombre' => 'Camino Clásico', 'duracion_dias' => 4, 'cantidad_campings' => 3]);

        $paquete = Paquete::create([
            'id_recorrido' => $recorrido->id_recorrido,
            'nombre' => 'Camino Inca Clásico',
            'precio_base' => 650,
            'costo_noche_extra_cusco' => 40,
            'costo_equipo_camping' => 25,
            'cantidad_porteadores' => 2,
            'estado' => EstadoPaquete::Activo,
            'fecha_creacion' => '2026-01-05',
        ]);

        $this->excursion = Excursion::create([
            'id_paquete' => $paquete->id_paquete,
            'id_guia' => $this->usuarioGuia->id_usuario,
            'fecha_salida' => '2027-01-18',
            'cupo' => 12,
            'plazas_retenidas' => 0,
        ]);

        $this->reserva = $this->crearReserva('000124-7', EstadoReserva::Pendiente, self::DOCUMENTOS, [
            ['Lucía', 'Fernández'], ['Martín', 'Fernández'], ['Sofía', 'Ruiz'],
        ]);
    }

    private function crearReserva(string $numeroReserva, EstadoReserva $estado, array $documentos, array $nombres = []): Reserva
    {
        $reserva = Reserva::create([
            'id_excursion' => $this->excursion->id_excursion,
            'numero_reserva' => $numeroReserva,
            'correo_electronico' => 'titular.'.$numeroReserva.'@mail.com',
            'fecha_reserva' => '2026-09-30 10:30:00',
            'estado' => $estado,
            'estado_saldo' => EstadoSaldo::Adeudado,
            'noches_extra_antes' => 0,
            'noches_extra_despues' => 0,
            'fecha_limite_saldo' => '2026-12-18 23:59:59',
            'fecha_limite_confirmacion' => '2026-12-18 23:59:59',
        ]);

        foreach ($documentos as $posicion => $documento) {
            Excursionista::create([
                'id_reserva' => $reserva->id_reserva,
                'nombre' => $nombres[$posicion][0] ?? 'Integrante',
                'apellido' => $nombres[$posicion][1] ?? (string) $posicion,
                'documento_pasaporte' => $documento,
                'equipo_camping' => false,
                'estado_permiso' => EstadoPermiso::Pendiente,
            ]);
        }

        return $reserva;
    }
}
