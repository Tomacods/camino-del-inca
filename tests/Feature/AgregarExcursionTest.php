<?php

namespace Tests\Feature;

use App\Enums\EstadoPaquete;
use App\Enums\EstadoReserva;
use App\Enums\EstadoSaldo;
use App\Enums\Rol;
use App\Filament\Resources\Paquetes\Pages\ListPaquetes;
use App\Filament\Resources\Paquetes\Pages\ViewPaquete;
use App\Models\Excursion;
use App\Models\Guia;
use App\Models\Paquete;
use App\Models\Recorrido;
use App\Models\Reserva;
use App\Models\Usuario;
use DomainException;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Select;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

// CU-27 Agregar Excursión (R-27): una salida nueva para un paquete «Activo», aunque tenga reservas activas.
class AgregarExcursionTest extends TestCase
{
    use RefreshDatabase;

    // La consulta con la que Paquete::confirmarGuiasDisponibles() bloquea a los guías, después de validar el formulario.
    private const BLOQUEO_DE_GUIAS = 'from "guia" where "id_usuario" in';

    private Usuario $administrador;

    private Guia $rosa;

    private Guia $luis;

    private Recorrido $recorrido;

    private Paquete $paquete;

    private Excursion $excursion;

    private Reserva $reserva;

    protected function setUp(): void
    {
        parent::setUp();

        // Un miércoles. El 01/03, el 08/03 y el 15/03/2027 son lunes.
        Carbon::setTestNow('2026-10-21');

        $this->administrador = Usuario::create(['correo' => 'admin@caminodelinca.test', 'password' => 'admin1234', 'rol' => Rol::Administrador]);
        $this->rosa = $this->crearGuia('rosa@caminodelinca.test', 'Rosa', 'Quispe');
        $this->luis = $this->crearGuia('luis@caminodelinca.test', 'Luis', 'Mamani');

        $this->recorrido = Recorrido::create(['nombre' => 'Camino Inca de 4 días', 'duracion_dias' => 4, 'cantidad_campings' => 3]);
        $this->paquete = $this->crearPaquete('Camino Inca Clásico', $this->recorrido);
        $this->excursion = $this->crearExcursion($this->paquete, '2027-03-01', $this->rosa);
        $this->reserva = $this->crearReserva(EstadoReserva::Confirmada);
    }

    public function test_agrega_una_salida_a_un_paquete_con_una_reserva_confirmada(): void
    {
        $this->assertFalse($this->paquete->sePuedeModificar());

        $this->consulta()
            ->callAction('agregarExcursion', data: [
                'fecha_salida' => '2027-03-08',
                'cupo' => 10,
                'id_guia' => $this->luis->id_usuario,
            ])
            ->assertHasNoFormErrors()
            ->assertNotified('Excursión agregada');

        $nueva = Excursion::buscarPorFechaSalida($this->paquete->id_paquete, '2027-03-08');

        $this->assertNotNull($nueva);
        $this->assertEquals(10, $nueva->cupo);
        $this->assertEquals($this->luis->id_usuario, $nueva->id_guia);
        $this->assertEquals(0, $nueva->plazas_retenidas);
        $this->assertSame(2, $this->paquete->excursiones()->count());
    }

    // No toca precios, servicios ni excursiones existentes: la salida y la reserva que ya estaban quedan iguales.
    public function test_las_excursiones_existentes_y_sus_reservas_no_cambian(): void
    {
        $this->paquete->agregarExcursion(['fecha_salida' => '2027-03-08', 'cupo' => 10, 'id_guia' => $this->rosa->id_usuario]);

        $this->excursion->refresh();
        $this->reserva->refresh();
        $this->paquete->refresh();

        $this->assertSame('2027-03-01', $this->excursion->fecha_salida->format('Y-m-d'));
        $this->assertEquals(12, $this->excursion->cupo);
        $this->assertEquals($this->rosa->id_usuario, $this->excursion->id_guia);
        $this->assertSame(EstadoReserva::Confirmada, $this->reserva->estado);
        $this->assertEquals($this->excursion->id_excursion, $this->reserva->id_excursion);
        $this->assertEquals(750, $this->paquete->precio_base);
        $this->assertSame(EstadoPaquete::Activo, $this->paquete->estado);
    }

    // En el listado, «Modificar» queda deshabilitado por la reserva, pero «Agregar excursión» se ofrece.
    public function test_tambien_se_agrega_desde_la_fila_del_listado(): void
    {
        $this->listado()
            ->assertActionDisabled(TestAction::make('edit')->table($this->paquete))
            ->assertActionVisible(TestAction::make('agregarExcursion')->table($this->paquete))
            ->callAction(TestAction::make('agregarExcursion')->table($this->paquete), data: [
                'fecha_salida' => '2027-03-15',
                'cupo' => 8,
                'id_guia' => $this->rosa->id_usuario,
            ])
            ->assertNotified('Excursión agregada');

        $this->assertNotNull(Excursion::buscarPorFechaSalida($this->paquete->id_paquete, '2027-03-15'));
    }

    public function test_no_se_ofrece_en_un_paquete_inactivo(): void
    {
        $inactivo = $this->crearPaquete('Paquete dado de baja', $this->recorrido);
        $inactivo->darDeBaja();

        $this->listado()
            ->assertActionVisible(TestAction::make('agregarExcursion')->table($this->paquete))
            ->assertActionHidden(TestAction::make('agregarExcursion')->table($inactivo));

        $this->actingAs($this->administrador);
        Livewire::test(ViewPaquete::class, ['record' => $inactivo->getRouteKey()])
            ->assertActionHidden('agregarExcursion');
    }

    // El paquete se dio de baja con el formulario abierto: el modelo lo vuelve a controlar.
    public function test_el_modelo_rechaza_un_paquete_inactivo(): void
    {
        $inactivo = $this->crearPaquete('Paquete dado de baja', $this->recorrido);
        $inactivo->darDeBaja();

        $this->assertRechazada($inactivo, '2027-03-08', $this->luis, 'El paquete está «Inactivo»: sólo se agregan excursiones a un paquete «Activo».');
    }

    public function test_rechaza_una_fecha_que_no_es_lunes(): void
    {
        $this->consulta()
            ->callAction('agregarExcursion', data: [
                'fecha_salida' => '2027-03-09',
                'cupo' => 10,
                'id_guia' => $this->luis->id_usuario,
            ])
            ->assertHasFormErrors(['fecha_salida' => 'La salida tiene que ser un lunes.']);

        $this->assertRechazada($this->paquete, '2027-03-09', $this->luis, 'La salida tiene que ser un lunes.');
    }

    public function test_rechaza_una_fecha_repetida(): void
    {
        $this->consulta()
            ->callAction('agregarExcursion', data: [
                'fecha_salida' => '2027-03-01',
                'cupo' => 10,
                'id_guia' => $this->luis->id_usuario,
            ])
            ->assertHasFormErrors(['fecha_salida' => 'El paquete ya tiene una salida en esa fecha.']);

        $this->assertRechazada($this->paquete, '2027-03-01', $this->luis, 'El paquete ya tiene una salida el 01/03/2027.');
    }

    // Hoy es miércoles 21/10/2026: el lunes 19/10 ya pasó. Una salida nueva nunca es una que ya salió (yaSalio()).
    public function test_rechaza_una_fecha_que_no_es_posterior_a_hoy(): void
    {
        $this->consulta()
            ->callAction('agregarExcursion', data: [
                'fecha_salida' => '2026-10-19',
                'cupo' => 10,
                'id_guia' => $this->luis->id_usuario,
            ])
            ->assertHasFormErrors(['fecha_salida' => 'La fecha de salida tiene que ser posterior a hoy.']);

        $this->travelTo(Carbon::parse('2026-10-26 09:00:00'));

        $this->assertRechazada($this->paquete, '2026-10-26', $this->luis, 'La fecha de salida tiene que ser posterior a hoy.');
    }

    public function test_rechaza_un_cupo_menor_que_uno(): void
    {
        $this->consulta()
            ->callAction('agregarExcursion', data: [
                'fecha_salida' => '2027-03-08',
                'cupo' => 0,
                'id_guia' => $this->luis->id_usuario,
            ])
            ->assertHasFormErrors(['cupo' => 'El cupo tiene que ser mayor que cero.']);

        $this->assertNull(Excursion::buscarPorFechaSalida($this->paquete->id_paquete, '2027-03-08'));
    }

    // Luis conduce otra excursión del 08/03 al 11/03: no se ofrece para el 08/03, sí para el 15/03.
    public function test_un_guia_ocupado_no_se_ofrece_para_esa_fecha_y_si_para_otra(): void
    {
        $this->crearExcursion($this->crearPaquete('Camino Inca Extendido', $this->recorrido), '2027-03-08', $this->luis);

        $this->consulta()
            ->mountAction('agregarExcursion')
            ->fillForm(['fecha_salida' => '2027-03-08'])
            ->assertFormFieldExists('id_guia', fn (Select $campo): bool => array_keys($campo->getOptions()) === [$this->rosa->id_usuario])
            ->fillForm(['fecha_salida' => '2027-03-15'])
            ->assertFormFieldExists('id_guia', fn (Select $campo): bool => array_keys($campo->getOptions()) === [$this->luis->id_usuario, $this->rosa->id_usuario]);
    }

    public function test_sin_fecha_el_guia_queda_deshabilitado(): void
    {
        $this->consulta()
            ->mountAction('agregarExcursion')
            ->assertFormFieldDisabled('id_guia')
            ->assertFormFieldExists(
                'id_guia',
                fn (Select $campo): bool => $campo->getOptions() === [] && $campo->getPlaceholder() === 'Elegí primero la fecha de salida',
            );
    }

    // Otro administrador asignó a Luis mientras el formulario estaba abierto: el modelo lo vuelve a controlar.
    public function test_rechaza_un_guia_ocupado(): void
    {
        $this->crearExcursion($this->crearPaquete('Camino Inca Extendido', $this->recorrido), '2027-03-08', $this->luis);

        $this->assertRechazada($this->paquete, '2027-03-08', $this->luis, 'El guía de la salida del 08/03/2027 ya conduce otra excursión en esas fechas.');
    }

    // Las salidas del mismo paquete también ocupan al guía: con un recorrido de 8 días, la del 01/03 termina el 08/03.
    public function test_compara_con_las_salidas_del_mismo_paquete(): void
    {
        $largo = Recorrido::create(['nombre' => 'Camino Inca de 8 días', 'duracion_dias' => 8, 'cantidad_campings' => 7]);
        $paquete = $this->crearPaquete('Camino Inca Largo', $largo);
        $this->crearExcursion($paquete, '2027-03-01', $this->luis);

        $this->assertRechazada($paquete, '2027-03-08', $this->luis, 'El guía de la salida del 08/03/2027 ya conduce otra excursión en esas fechas.');
        $this->assertSame('2027-03-15', $paquete->agregarExcursion([
            'fecha_salida' => '2027-03-15',
            'cupo' => 10,
            'id_guia' => $this->luis->id_usuario,
        ])->fecha_salida->format('Y-m-d'));
    }

    // Otro administrador asigna a Luis después de validar el formulario: el modelo, con los guías bloqueados, lo rechaza,
    // avisa el motivo y el formulario queda abierto para elegir otro guía.
    public function test_si_el_modelo_la_rechaza_avisa_el_motivo_y_no_la_agrega(): void
    {
        $otro = $this->crearPaquete('Camino Inca Extendido', $this->recorrido);
        $yaOcupado = false;

        DB::listen(function (QueryExecuted $consulta) use ($otro, &$yaOcupado) {
            if ($yaOcupado || ! str_contains($consulta->sql, self::BLOQUEO_DE_GUIAS)) {
                return;
            }

            $yaOcupado = true;
            $this->crearExcursion($otro, '2027-03-08', $this->luis);
        });

        $this->consulta()
            ->callAction('agregarExcursion', data: [
                'fecha_salida' => '2027-03-08',
                'cupo' => 10,
                'id_guia' => $this->luis->id_usuario,
            ])
            ->assertNotified('No se agregó la excursión')
            ->assertActionMounted('agregarExcursion');

        $this->assertNull(Excursion::buscarPorFechaSalida($this->paquete->id_paquete, '2027-03-08'));
    }

    private function assertRechazada(Paquete $paquete, string $fechaSalida, Guia $guia, string $mensaje): void
    {
        $cantidad = Excursion::count();

        try {
            $paquete->agregarExcursion(['fecha_salida' => $fechaSalida, 'cupo' => 10, 'id_guia' => $guia->id_usuario]);
            $this->fail('Se agregó una excursión que se tenía que rechazar.');
        } catch (DomainException $excepcion) {
            $this->assertSame($mensaje, $excepcion->getMessage());
        }

        $this->assertSame($cantidad, Excursion::count());
    }

    private function consulta()
    {
        $this->actingAs($this->administrador);

        return Livewire::test(ViewPaquete::class, ['record' => $this->paquete->getRouteKey()]);
    }

    private function listado()
    {
        $this->actingAs($this->administrador);

        return Livewire::test(ListPaquetes::class);
    }

    private function crearGuia(string $correo, string $nombre, string $apellido): Guia
    {
        $usuario = Usuario::create(['correo' => $correo, 'password' => 'guia1234', 'rol' => Rol::Guia]);

        return Guia::create(['id_usuario' => $usuario->id_usuario, 'nombre' => $nombre, 'apellido' => $apellido]);
    }

    private function crearPaquete(string $nombre, Recorrido $recorrido): Paquete
    {
        return Paquete::crearPaquete([
            'id_recorrido' => $recorrido->id_recorrido,
            'nombre' => $nombre,
            'precio_base' => 750,
            'costo_noche_extra_cusco' => 60,
            'costo_equipo_camping' => 45,
            'cantidad_porteadores' => 6,
        ]);
    }

    private function crearExcursion(Paquete $paquete, string $fechaSalida, Guia $guia): Excursion
    {
        return Excursion::create([
            'id_paquete' => $paquete->id_paquete,
            'id_guia' => $guia->id_usuario,
            'fecha_salida' => $fechaSalida,
            'cupo' => 12,
        ]);
    }

    private function crearReserva(EstadoReserva $estado): Reserva
    {
        return Reserva::create([
            'id_excursion' => $this->excursion->id_excursion,
            'numero_reserva' => '000501-3',
            'correo_electronico' => 'cliente@correo.com',
            'fecha_reserva' => '2026-10-01 10:00:00',
            'estado' => $estado,
            'estado_saldo' => EstadoSaldo::Abonado,
            'noches_extra_antes' => 0,
            'noches_extra_despues' => 0,
            'fecha_limite_saldo' => null,
            'fecha_limite_confirmacion' => '2027-02-01 23:59:59',
        ]);
    }
}
