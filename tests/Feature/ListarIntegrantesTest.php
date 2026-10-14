<?php

namespace Tests\Feature;

use App\Enums\EstadoPaquete;
use App\Enums\EstadoPermiso;
use App\Enums\EstadoReserva;
use App\Enums\EstadoSaldo;
use App\Enums\Rol;
use App\Filament\Resources\Excursiones\ExcursionResource;
use App\Filament\Resources\Excursiones\Pages\ListarIntegrantesExcursion;
use App\Filament\Resources\Excursiones\Pages\ListExcursiones;
use App\Models\Excursion;
use App\Models\Excursionista;
use App\Models\Guia;
use App\Models\Paquete;
use App\Models\Recorrido;
use App\Models\Reserva;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

class ListarIntegrantesTest extends TestCase
{
    use RefreshDatabase;

    private Usuario $administrador;

    private Usuario $usuarioGuia;

    private Usuario $otroUsuarioGuia;

    private Paquete $paquete;

    // Las tres de Rosa Quispe: una ya realizada, una sin reservas confirmadas (A1) y una con dos reservas confirmadas.
    private Excursion $excursionRealizada;

    private Excursion $excursionSinConfirmadas;

    private Excursion $excursion;

    // De Andrés Mamani.
    private Excursion $excursionDeOtroGuia;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-14 10:00:00');

        $this->crearDatos();
    }

    /* ------------------------ Curso normal: el listado ------------------------ */

    public function test_el_guia_ve_solo_sus_excursiones(): void
    {
        $this->actingAs($this->usuarioGuia);

        Livewire::test(ListExcursiones::class)
            ->assertSee('Mis excursiones')
            ->assertCanSeeTableRecords([$this->excursionSinConfirmadas, $this->excursion, $this->excursionRealizada], inOrder: true)
            ->assertCanNotSeeTableRecords([$this->excursionDeOtroGuia])
            ->assertCountTableRecords(3);
    }

    // Hoy es 14/10/2026: la de hoy cuenta como próxima.
    public function test_primero_las_proximas_salidas_y_despues_las_pasadas(): void
    {
        $excursionDeHoy = $this->crearExcursion($this->paquete, '2026-10-14', $this->usuarioGuia);
        $excursionMasVieja = $this->crearExcursion($this->paquete, '2026-05-04', $this->usuarioGuia);

        $this->actingAs($this->usuarioGuia);

        Livewire::test(ListExcursiones::class)
            ->assertCanSeeTableRecords([
                $excursionDeHoy,
                $this->excursionSinConfirmadas,
                $this->excursion,
                $this->excursionRealizada,
                $excursionMasVieja,
            ], inOrder: true)
            ->assertCountTableRecords(5);
    }

    public function test_el_listado_cuenta_solo_los_integrantes_de_reservas_confirmadas(): void
    {
        $this->actingAs($this->usuarioGuia);

        Livewire::test(ListExcursiones::class)
            ->assertTableColumnStateSet('integrantes', 3, $this->excursion)
            ->assertTableColumnStateSet('integrantes', 0, $this->excursionSinConfirmadas)
            ->assertTableColumnStateSet('cupo', 12, $this->excursion);
    }

    public function test_cada_excursion_lleva_a_su_pagina_de_integrantes(): void
    {
        $this->actingAs($this->usuarioGuia);

        Livewire::test(ListExcursiones::class)
            ->assertTableActionHasUrl('listarIntegrantes', ExcursionResource::getUrl('integrantes', ['record' => $this->excursion]), $this->excursion);
    }

    public function test_el_listado_no_consulta_los_integrantes_por_cada_excursion(): void
    {
        $this->actingAs($this->usuarioGuia);

        $consultas = $this->consultasAExcursionista(fn () => Livewire::test(ListExcursiones::class));

        $this->assertCount(1, $consultas);
    }

    /* ---------------------- Curso normal: los integrantes --------------------- */

    public function test_el_guia_ve_el_encabezado_de_la_excursion(): void
    {
        $this->abrirPagina($this->excursion)
            ->assertSee('Integrantes · Camino Inca Clásico · 01/03/2027')
            ->assertSee('Rosa Quispe')
            ->assertSeeInOrder(['Cupo', '12', 'Integrantes confirmados', '3']);
    }

    public function test_el_guia_ve_los_integrantes_de_las_reservas_confirmadas_agrupados_por_reserva(): void
    {
        // Las reservas por número; dentro de cada una, los integrantes por apellido.
        $this->abrirPagina($this->excursion)
            ->assertSeeInOrder([
                'Reserva 000125-9',
                'Federico', 'Ríos', 'AAH715300', 'No', 'Obtenido',
                'Reserva 000126-0',
                'Tomás', 'Acosta', 'AAJ820145', 'No', 'Obtenido',
                'Gabriela', 'Sosa', 'AAJ820144', 'Sí', 'Obtenido',
            ])
            ->assertDontSee('Esta excursión todavía no tiene integrantes confirmados');
    }

    public function test_no_se_ven_los_integrantes_de_reservas_en_otros_estados(): void
    {
        $this->abrirPagina($this->excursion)
            ->assertDontSee('Lucía')
            ->assertDontSee('Morales')
            ->assertDontSee('López')
            ->assertDontSee('Benítez')
            ->assertDontSee('000124-7');
    }

    public function test_la_excursion_solo_devuelve_las_reservas_confirmadas(): void
    {
        $excursion = Excursion::seleccionarExcursion($this->paquete->id_paquete, '2027-03-01');

        $this->assertSame(['000125-9', '000126-0'], $excursion->getReservasConfirmadas()->pluck('numero_reserva')->all());
        $this->assertSame(3, $excursion->contarIntegrantesConfirmados());

        $datos = $excursion->getEncabezado();

        $this->assertSame('Camino Inca Clásico', $datos['paquete']);
        $this->assertSame('2027-03-01', $datos['fecha_salida']->format('Y-m-d'));
        $this->assertSame('Rosa Quispe', $datos['guia']);
        $this->assertSame(12, $datos['cupo']);
        $this->assertSame(3, $datos['integrantes']);
    }

    public function test_los_datos_de_un_integrante(): void
    {
        $gabriela = Excursionista::where('documento_pasaporte', 'AAJ820144')->firstOrFail();

        $this->assertSame([
            'nombre' => 'Gabriela',
            'apellido' => 'Sosa',
            'documento_pasaporte' => 'AAJ820144',
            'equipo_camping' => true,
            'estado_permiso' => EstadoPermiso::Obtenido,
        ], $gabriela->getDatos());
    }

    public function test_mostrar_los_integrantes_no_consulta_por_cada_reserva(): void
    {
        $consultas = $this->consultasAExcursionista(fn () => $this->abrirPagina($this->excursion));

        $this->assertCount(1, $consultas);
    }

    public function test_los_datos_de_la_pagina_no_se_pueden_cambiar_desde_el_navegador(): void
    {
        $this->expectException(CannotUpdateLockedPropertyException::class);

        $this->abrirPagina($this->excursion)->set('reservas', []);
    }

    /* ----------------------------------- A1 ----------------------------------- */

    public function test_una_excursion_sin_reservas_confirmadas_muestra_el_aviso(): void
    {
        $this->abrirPagina($this->excursionSinConfirmadas)
            ->assertSee('Esta excursión todavía no tiene integrantes confirmados')
            ->assertDontSee('Lucía')
            ->assertDontSee('Reserva 000127-1');
    }

    // Las reservas de una salida realizada están en «Finalizada»: sólo se listan las confirmadas.
    public function test_una_excursion_realizada_muestra_el_aviso(): void
    {
        $this->abrirPagina($this->excursionRealizada)
            ->assertSee('Esta excursión todavía no tiene integrantes confirmados')
            ->assertDontSee('Benítez');
    }

    /* ----------------------------------- A2 ----------------------------------- */

    public function test_un_guia_sin_excursiones_ve_el_listado_vacio_con_un_mensaje(): void
    {
        $usuarioSinExcursiones = $this->crearGuia('ojosverdes@caminodelinca.test', 'Julia', 'Condori');

        $this->actingAs($usuarioSinExcursiones);

        Livewire::test(ListExcursiones::class)
            ->assertCountTableRecords(0)
            ->assertSee('Todavía no tenés excursiones asignadas');
    }

    /* --------------------------------- Acceso --------------------------------- */

    public function test_el_guia_entra_a_sus_excursiones(): void
    {
        $this->actingAs($this->usuarioGuia);

        $this->get(ExcursionResource::getUrl('index'))->assertOk();
        $this->get(ExcursionResource::getUrl('integrantes', ['record' => $this->excursion]))
            ->assertOk()
            ->assertSee('Gabriela');
    }

    public function test_un_guia_no_puede_abrir_los_integrantes_de_una_excursion_de_otro_guia(): void
    {
        $this->actingAs($this->usuarioGuia)
            ->get(ExcursionResource::getUrl('integrantes', ['record' => $this->excursionDeOtroGuia]))
            ->assertNotFound();
    }

    public function test_el_otro_guia_ve_solo_la_suya(): void
    {
        $this->actingAs($this->otroUsuarioGuia);

        Livewire::test(ListExcursiones::class)
            ->assertCanSeeTableRecords([$this->excursionDeOtroGuia])
            ->assertCountTableRecords(1);

        $this->get(ExcursionResource::getUrl('integrantes', ['record' => $this->excursion]))->assertNotFound();
    }

    public function test_el_administrador_no_entra(): void
    {
        $this->actingAs($this->administrador);

        $this->get(ExcursionResource::getUrl('index'))->assertForbidden();
        $this->get(ExcursionResource::getUrl('integrantes', ['record' => $this->excursion]))->assertForbidden();
    }

    /* -------------------------------- Ayudantes ------------------------------- */

    private function abrirPagina(Excursion $excursion)
    {
        $this->actingAs($this->usuarioGuia);

        return Livewire::test(ListarIntegrantesExcursion::class, ['record' => $excursion->getRouteKey()]);
    }

    // Las consultas a la tabla de integrantes mientras se ejecuta $accion.
    private function consultasAExcursionista(callable $accion): array
    {
        DB::enableQueryLog();
        $accion();
        $consultas = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        return array_values(array_filter($consultas, fn (string $consulta) => str_contains($consulta, 'from "excursionista"')));
    }

    private function crearDatos(): void
    {
        $this->administrador = Usuario::create(['correo' => 'admin@caminodelinca.test', 'password' => 'admin1234', 'rol' => Rol::Administrador]);
        $this->usuarioGuia = $this->crearGuia('guia@caminodelinca.test', 'Rosa', 'Quispe');
        $this->otroUsuarioGuia = $this->crearGuia('luko@caminodelinca.test', 'Andrés', 'Mamani');

        $recorrido = Recorrido::create(['nombre' => 'Camino Clásico', 'duracion_dias' => 4, 'cantidad_campings' => 3]);

        $this->paquete = $this->crearPaquete($recorrido, 'Camino Inca Clásico');
        $paqueteCorto = $this->crearPaquete($recorrido, 'Camino Inca Corto');

        $this->excursionRealizada = $this->crearExcursion($this->paquete, '2026-09-07', $this->usuarioGuia);
        $this->excursionSinConfirmadas = $this->crearExcursion($paqueteCorto, '2027-02-08', $this->usuarioGuia);
        $this->excursion = $this->crearExcursion($this->paquete, '2027-03-01', $this->usuarioGuia);
        $this->excursionDeOtroGuia = $this->crearExcursion($this->paquete, '2027-01-04', $this->otroUsuarioGuia);

        $this->crearReserva($this->excursionRealizada, '000118-1', EstadoReserva::Finalizada, [['Paula', 'Benítez', 'AAB905731', false]]);
        $this->crearReserva($this->excursionSinConfirmadas, '000127-1', EstadoReserva::Pendiente, [['Lucía', 'Fernández', 'AAG604118', true]]);

        // Se cargan desordenadas para ver que la página las ordena.
        $this->crearReserva($this->excursion, '000126-0', EstadoReserva::Confirmada, [
            ['Gabriela', 'Sosa', 'AAJ820144', true],
            ['Tomás', 'Acosta', 'AAJ820145', false],
        ]);
        $this->crearReserva($this->excursion, '000125-9', EstadoReserva::Confirmada, [['Federico', 'Ríos', 'AAH715300', false]]);
        $this->crearReserva($this->excursion, '000124-7', EstadoReserva::Pendiente, [['Lucía', 'Fernández', 'AAG604119', true]]);
        $this->crearReserva($this->excursion, '000123-5', EstadoReserva::SinPermiso, [['Diego', 'Morales', 'AAF517402', false]]);
        $this->crearReserva($this->excursion, '000121-1', EstadoReserva::Cancelada, [['Martina', 'López', 'AAD330917', false]]);

        $this->crearReserva($this->excursionDeOtroGuia, '000122-3', EstadoReserva::Confirmada, [['Valentina', 'Díaz', 'AAE402215', true]]);
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

    // Cada integrante: nombre, apellido, documento y si alquila el equipo de camping. Los de reservas confirmadas o
    // finalizadas tienen el permiso obtenido; el resto, pendiente.
    private function crearReserva(Excursion $excursion, string $numeroReserva, EstadoReserva $estado, array $integrantes): Reserva
    {
        $reserva = Reserva::create([
            'id_excursion' => $excursion->id_excursion,
            'numero_reserva' => $numeroReserva,
            'correo_electronico' => 'titular.'.$numeroReserva.'@mail.com',
            'fecha_reserva' => '2026-06-01 10:30:00',
            'estado' => $estado,
            'estado_saldo' => EstadoSaldo::Abonado,
            'noches_extra_antes' => 0,
            'noches_extra_despues' => 0,
            'fecha_limite_saldo' => null,
            'fecha_limite_confirmacion' => $excursion->fecha_salida->copy()->subMonth()->endOfDay(),
        ]);

        $permiso = in_array($estado, [EstadoReserva::Confirmada, EstadoReserva::Finalizada], true)
            ? EstadoPermiso::Obtenido
            : EstadoPermiso::Pendiente;

        foreach ($integrantes as [$nombre, $apellido, $documento, $equipoCamping]) {
            Excursionista::create([
                'id_reserva' => $reserva->id_reserva,
                'nombre' => $nombre,
                'apellido' => $apellido,
                'documento_pasaporte' => $documento,
                'equipo_camping' => $equipoCamping,
                'estado_permiso' => $permiso,
            ]);
        }

        return $reserva;
    }
}
