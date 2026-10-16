<?php

namespace Tests\Feature;

use App\Enums\EstadoPaquete;
use App\Enums\EstadoPermiso;
use App\Enums\EstadoReserva;
use App\Enums\EstadoSaldo;
use App\Enums\Rol;
use App\Enums\TipoPago;
use App\Models\Excursion;
use App\Models\Guia;
use App\Models\Pago;
use App\Models\Paquete;
use App\Models\Recorrido;
use App\Models\Reserva;
use App\Models\Usuario;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

// Modificar Reserva (CU-20). Hoy es el 13/10/2026: la anticipación mínima de 3 meses deja salir desde el 13/01/2027. Un
// paquete con cuatro salidas, todas lunes: la de la reserva (01/02/2027), una con lugar (01/03/2027), una con un solo
// lugar (08/03/2027) y una a menos de 3 meses (11/01/2027). La reserva 000125-9 está «Confirmada», con dos integrantes
// con el permiso «Obtenido», el saldo «Adeudado» y la seña pagada.
class ModificarReservaTest extends TestCase
{
    use RefreshDatabase;

    private Paquete $paquete;

    private Excursion $origen;

    private Excursion $destino;

    private Excursion $sinCupo;

    private Excursion $menosDeTresMeses;

    private Reserva $reserva;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-13 10:00:00'));

        $this->crearPaquete();
        $this->origen = $this->crearExcursion('2027-02-01', 12);
        $this->destino = $this->crearExcursion('2027-03-01', 12);
        $this->sinCupo = $this->crearExcursion('2027-03-08', 1);
        $this->menosDeTresMeses = $this->crearExcursion('2027-01-11', 12);

        $this->reserva = $this->crearReservaConfirmada();
    }

    /* --------------------------------- Reserva -------------------------------- */

    public function test_el_cambio_pasa_la_reserva_al_destino_y_la_deja_pendiente(): void
    {
        $this->assertSame(10, $this->origen->obtenerCupoDisponible());
        $this->assertSame(12, $this->destino->obtenerCupoDisponible());

        $this->reserva->modificarExcursion($this->destino);

        $reserva = $this->reserva->fresh();
        $this->assertSame($this->destino->id_excursion, $reserva->id_excursion);
        $this->assertSame(EstadoReserva::Pendiente, $reserva->estado);
        $this->assertSame([EstadoPermiso::Pendiente, EstadoPermiso::Pendiente], $reserva->excursionistas->pluck('estado_permiso')->all());

        // Un mes antes de la nueva salida, a las 23:59:59.
        $this->assertSame('2027-02-01 23:59:59', $reserva->fecha_limite_confirmacion->format('Y-m-d H:i:s'));
        $this->assertSame('2027-02-01 23:59:59', $reserva->fecha_limite_saldo->format('Y-m-d H:i:s'));
        $this->assertSame(EstadoSaldo::Adeudado, $reserva->estado_saldo);

        // Las plazas se liberan en el origen y se ocupan en el destino.
        $this->assertSame(12, $this->origen->obtenerCupoDisponible());
        $this->assertSame(10, $this->destino->obtenerCupoDisponible());
    }

    public function test_quien_llama_ve_la_reserva_como_quedo(): void
    {
        $this->reserva->modificarExcursion($this->destino);

        $this->assertSame(EstadoReserva::Pendiente, $this->reserva->estado);
        $this->assertSame('01/03/2027', $this->reserva->excursion->getFechaSalida()->format('d/m/Y'));
        $this->assertSame(EstadoPermiso::Pendiente, $this->reserva->excursionistas->first()->estado_permiso);
    }

    public function test_con_el_saldo_abonado_el_cambio_deja_la_fecha_limite_del_saldo_en_null(): void
    {
        $this->reserva->update(['estado_saldo' => EstadoSaldo::Abonado]);

        $this->reserva->fresh()->modificarExcursion($this->destino);

        $reserva = $this->reserva->fresh();
        $this->assertNull($reserva->fecha_limite_saldo);
        $this->assertSame('2027-02-01 23:59:59', $reserva->fecha_limite_confirmacion->format('Y-m-d H:i:s'));
    }

    public function test_si_el_saldo_se_paga_despues_de_leer_la_reserva_el_cambio_no_le_pone_fecha_limite(): void
    {
        // La pantalla leyó la reserva con el saldo «Adeudado». Antes de que confirme el cambio, el pago del saldo queda
        // registrado en la base (Pago::registrarPagoSaldo()): esta instancia no se entera.
        $leida = $this->reserva->fresh();
        Reserva::where('id_reserva', $leida->id_reserva)->update(['estado_saldo' => EstadoSaldo::Abonado]);

        $leida->modificarExcursion($this->destino);

        $reserva = $this->reserva->fresh();
        $this->assertSame(EstadoSaldo::Abonado, $reserva->estado_saldo);
        $this->assertNull($reserva->fecha_limite_saldo);
        $this->assertSame(EstadoReserva::Pendiente, $reserva->estado);
        $this->assertSame($this->destino->id_excursion, $reserva->id_excursion);
    }

    public function test_si_la_reserva_se_cancela_despues_de_leerla_el_cambio_no_se_hace(): void
    {
        $leida = $this->reserva->fresh();
        Reserva::where('id_reserva', $leida->id_reserva)->update(['estado' => EstadoReserva::Cancelada]);

        try {
            $leida->modificarExcursion($this->destino);
            $this->fail('Se cambió de excursión una reserva cancelada.');
        } catch (DomainException $excepcion) {
            $this->assertSame('La reserva no admite modificación.', $excepcion->getMessage());
        }

        $this->assertNoCambio(EstadoReserva::Cancelada);
    }

    #[DataProvider('estadosQueNoAdmitenModificacion')]
    public function test_solo_una_reserva_confirmada_se_puede_modificar(EstadoReserva $estado): void
    {
        $this->reserva->update(['estado' => $estado]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('La reserva no admite modificación.');

        $this->reserva->fresh()->iniciarModificacion();
    }

    public static function estadosQueNoAdmitenModificacion(): array
    {
        return [
            'Pendiente' => [EstadoReserva::Pendiente],
            'Sin Permiso' => [EstadoReserva::SinPermiso],
            'Cancelada' => [EstadoReserva::Cancelada],
            'Finalizada' => [EstadoReserva::Finalizada],
        ];
    }

    public function test_con_la_salida_a_menos_de_3_meses_no_se_puede_modificar(): void
    {
        // Desde el 02/11/2026, la salida del 01/02/2027 queda a menos de 3 meses.
        $this->travelTo(Carbon::parse('2026-11-02 09:00:00'));

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Ya no se puede modificar: falta poco para la salida.');

        $this->reserva->iniciarModificacion();
    }

    public function test_con_la_salida_justo_a_3_meses_se_puede_modificar(): void
    {
        // El 01/11/2026, aunque sea de noche, la salida del 01/02/2027 está justo a 3 meses.
        $this->travelTo(Carbon::parse('2026-11-01 23:00:00'));

        $this->assertSame(['01/03/2027'], $this->fechasHabilitadas());
    }

    public function test_la_lista_tiene_solo_las_otras_salidas_a_3_meses_con_lugar_para_todos(): void
    {
        // El destino, con lugar justo para los dos integrantes.
        $this->destino->update(['cupo' => 2]);

        $this->assertSame(['01/03/2027'], $this->fechasHabilitadas());
    }

    public function test_la_lista_descuenta_las_plazas_retenidas(): void
    {
        // Otro cliente está pagando 11 lugares del destino: queda 1, y el grupo es de dos.
        $this->destino->update(['plazas_retenidas' => 11]);

        $this->assertSame([], $this->fechasHabilitadas());
    }

    public function test_elegir_una_salida_sin_lugar_para_todos_avisa_que_no_esta_disponible(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('La excursión elegida ya no está disponible.');

        $this->reserva->elegirExcursionDestino(Carbon::parse('2027-03-08'));
    }

    public function test_elegir_una_salida_a_menos_de_3_meses_avisa_que_no_esta_disponible(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('La excursión elegida ya no está disponible.');

        $this->reserva->elegirExcursionDestino(Carbon::parse('2027-01-11'));
    }

    public function test_elegir_la_misma_salida_avisa_que_no_esta_disponible(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('La excursión elegida ya no está disponible.');

        $this->reserva->elegirExcursionDestino(Carbon::parse('2027-02-01'));
    }

    public function test_el_resumen_trae_las_salidas_y_las_nuevas_fechas_limite(): void
    {
        $cambio = $this->reserva->elegirExcursionDestino(Carbon::parse('2027-03-01'));

        $this->assertSame($this->origen->id_excursion, $cambio['origen']->id_excursion);
        $this->assertSame($this->destino->id_excursion, $cambio['destino']->id_excursion);
        $this->assertSame('2027-02-01 23:59:59', $cambio['fecha_limite']->format('Y-m-d H:i:s'));
        $this->assertSame('2027-02-01 23:59:59', $cambio['fecha_limite_saldo']->format('Y-m-d H:i:s'));

        // El resumen no guarda nada.
        $this->assertNoCambio(EstadoReserva::Confirmada);
    }

    public function test_con_el_saldo_abonado_el_resumen_no_trae_fecha_limite_del_saldo(): void
    {
        $this->reserva->update(['estado_saldo' => EstadoSaldo::Abonado]);

        $cambio = $this->reserva->fresh()->elegirExcursionDestino(Carbon::parse('2027-03-01'));

        $this->assertNull($cambio['fecha_limite_saldo']);
        $this->assertSame('2027-02-01 23:59:59', $cambio['fecha_limite']->format('Y-m-d H:i:s'));
    }

    public function test_si_otra_reserva_ocupa_el_ultimo_lugar_antes_de_confirmar_el_cambio_no_se_hace(): void
    {
        $this->destino->update(['cupo' => 2]);
        $cambio = $this->reserva->elegirExcursionDestino(Carbon::parse('2027-03-01'));

        // Entre la elección y la confirmación, otra reserva de un integrante entra en el destino.
        $this->crearOtraReserva($this->destino, '000122-3', 'valentina.diaz@mail.com');

        try {
            $this->reserva->modificarExcursion($cambio['destino']);
            $this->fail('El cambio se hizo sin lugar para todo el grupo.');
        } catch (DomainException $excepcion) {
            $this->assertSame('La excursión elegida ya no tiene lugar.', $excepcion->getMessage());
        }

        $this->assertNoCambio(EstadoReserva::Confirmada);
    }

    public function test_al_confirmar_no_se_cambia_a_una_salida_que_ya_no_cumple_la_anticipacion(): void
    {
        try {
            $this->reserva->modificarExcursion($this->menosDeTresMeses);
            $this->fail('Se cambió a una salida a menos de 3 meses.');
        } catch (DomainException $excepcion) {
            $this->assertSame('La excursión elegida ya no cumple la anticipación mínima.', $excepcion->getMessage());
        }

        $this->assertNoCambio(EstadoReserva::Confirmada);
    }

    /* --------------------------------- Pantalla -------------------------------- */

    public function test_sin_acceso_en_la_sesion_vuelve_a_mi_reserva(): void
    {
        $this->get('/mi-reserva/000125-9/modificar')->assertRedirect('/mi-reserva');
    }

    public function test_con_el_acceso_de_otra_reserva_vuelve_a_mi_reserva(): void
    {
        $this->crearOtraReserva($this->origen, '000122-3', 'valentina.diaz@mail.com');
        $this->darAcceso('valentina.diaz@mail.com', '000122-3');

        $this->get('/mi-reserva/000125-9/modificar')->assertRedirect('/mi-reserva');
    }

    public function test_la_pantalla_lleva_del_listado_al_resumen_y_a_la_confirmacion(): void
    {
        $this->darAcceso();

        $this->abrirPantalla()
            ->assertSee('Elegí la nueva salida')
            ->assertSee('01/03/2027')
            ->assertDontSee('08/03/2027')
            ->assertDontSee('11/01/2027')
            // Después de una llamada, el orden se mira en el HTML del componente: assertSeeInOrder miraría la respuesta
            // de Livewire, que es JSON.
            ->call('elegir', '2027-03-01')
            ->assertSeeHtmlInOrder(['Resumen del cambio', '01/02/2027', '01/03/2027'])
            ->assertSeeHtmlInOrder(['Nueva fecha límite de confirmación', '01/02/2027'])
            ->assertSeeHtmlInOrder(['Nueva fecha límite de pago del saldo', '01/02/2027'])
            ->call('confirmar')
            ->assertSee('¡Excursión cambiada!')
            ->assertSeeHtmlInOrder(['Reserva 000125-9', EstadoReserva::Pendiente->value])
            ->assertSeeHtmlInOrder(['Salida actual', '01/03/2027']);

        $reserva = $this->reserva->fresh();
        $this->assertSame(EstadoReserva::Pendiente, $reserva->estado);
        $this->assertSame($this->destino->id_excursion, $reserva->id_excursion);
    }

    public function test_una_fecha_mal_escrita_avisa_que_la_excursion_no_esta_disponible(): void
    {
        $this->darAcceso();

        // Lo que manda el navegador se puede tocar: ni una fecha que no existe ni un texto cualquiera dan error 500.
        foreach (['cualquier-cosa', '2027-02-30', '01/03/2027'] as $fecha) {
            $this->abrirPantalla()
                ->call('elegir', $fecha)
                ->assertSet('paso', 2)
                ->assertSee('La excursión elegida ya no está disponible.')
                ->assertSee('Elegí la nueva salida')
                ->assertDontSee('Resumen del cambio');
        }

        $this->assertNoCambio(EstadoReserva::Confirmada);
    }

    public function test_con_una_reserva_que_no_admite_el_cambio_muestra_el_aviso_sin_la_lista(): void
    {
        $this->reserva->update(['estado' => EstadoReserva::Pendiente]);
        $this->darAcceso();

        $this->abrirPantalla()
            ->assertSee('No se puede modificar')
            ->assertSee('La reserva no admite modificación.')
            ->assertSee('Volver a mi reserva')
            ->assertDontSee('Elegí la nueva salida');
    }

    private function abrirPantalla()
    {
        return Livewire::test('modificar-reserva', ['numeroReserva' => '000125-9']);
    }

    // Como queda la sesión cuando el cliente consulta su reserva en Mi reserva.
    private function darAcceso(string $correo = 'federico.rios@mail.com', string $numeroReserva = '000125-9'): void
    {
        session(['acceso_reserva' => ['correo' => $correo, 'numeroReserva' => $numeroReserva]]);
    }

    // Las salidas que se le ofrecen al cliente, como las ve en la pantalla.
    private function fechasHabilitadas(): array
    {
        return $this->reserva->fresh()->iniciarModificacion()
            ->map(fn (Excursion $excursion) => $excursion->getFechaSalida()->format('d/m/Y'))
            ->all();
    }

    // La reserva sigue como antes del cambio: en el origen, con los permisos y las fechas límite de antes.
    private function assertNoCambio(EstadoReserva $estado): void
    {
        $reserva = $this->reserva->fresh();

        $this->assertSame($estado, $reserva->estado);
        $this->assertSame($this->origen->id_excursion, $reserva->id_excursion);
        $this->assertSame([EstadoPermiso::Obtenido, EstadoPermiso::Obtenido], $reserva->excursionistas->pluck('estado_permiso')->all());
        $this->assertSame('2027-01-01 23:59:59', $reserva->fecha_limite_confirmacion->format('Y-m-d H:i:s'));
        $this->assertSame('2027-01-01 23:59:59', $reserva->fecha_limite_saldo->format('Y-m-d H:i:s'));
    }

    private function crearPaquete(): void
    {
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
    }

    private function crearExcursion(string $fechaSalida, int $cupo): Excursion
    {
        return Excursion::create([
            'id_paquete' => $this->paquete->id_paquete,
            'id_guia' => Guia::value('id_usuario'),
            'fecha_salida' => $fechaSalida,
            'cupo' => $cupo,
            'plazas_retenidas' => 0,
        ]);
    }

    // Como la 000125-9 de los seeders.
    private function crearReservaConfirmada(): Reserva
    {
        $reserva = Reserva::create([
            'id_excursion' => $this->origen->id_excursion,
            'numero_reserva' => '000125-9',
            'correo_electronico' => 'federico.rios@mail.com',
            'fecha_reserva' => '2026-10-01 18:20:00',
            'estado' => EstadoReserva::Confirmada,
            'estado_saldo' => EstadoSaldo::Adeudado,
            'noches_extra_antes' => 0,
            'noches_extra_despues' => 0,
            'fecha_limite_saldo' => '2027-01-01 23:59:59',
            'fecha_limite_confirmacion' => '2027-01-01 23:59:59',
        ]);

        foreach ([['Federico', 'AAH715300'], ['Lorena', 'AAH715301']] as [$nombre, $documento]) {
            $reserva->excursionistas()->create([
                'nombre' => $nombre,
                'apellido' => 'Ríos',
                'documento_pasaporte' => $documento,
                'equipo_camping' => false,
                'estado_permiso' => EstadoPermiso::Obtenido,
            ]);
        }

        Pago::registrarPago($reserva, 750, '2026-10-01 18:20:00', 'Tarjeta de crédito', TipoPago::Sena);

        return $reserva;
    }

    // Otra reserva confirmada, de otro cliente y con un integrante, en la excursión que se indique.
    private function crearOtraReserva(Excursion $excursion, string $numeroReserva, string $correo): Reserva
    {
        $reserva = Reserva::create([
            'id_excursion' => $excursion->id_excursion,
            'numero_reserva' => $numeroReserva,
            'correo_electronico' => $correo,
            'fecha_reserva' => '2026-09-10 16:45:00',
            'estado' => EstadoReserva::Confirmada,
            'estado_saldo' => EstadoSaldo::Adeudado,
            'noches_extra_antes' => 0,
            'noches_extra_despues' => 0,
            'fecha_limite_saldo' => '2027-01-01 23:59:59',
            'fecha_limite_confirmacion' => '2027-01-01 23:59:59',
        ]);

        $reserva->excursionistas()->create([
            'nombre' => 'Valentina',
            'apellido' => 'Díaz',
            'documento_pasaporte' => 'AAE402215',
            'equipo_camping' => false,
            'estado_permiso' => EstadoPermiso::Obtenido,
        ]);

        return $reserva;
    }
}
