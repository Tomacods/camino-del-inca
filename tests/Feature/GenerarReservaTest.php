<?php

namespace Tests\Feature;

use App\Enums\EstadoPaquete;
use App\Enums\EstadoPermiso;
use App\Enums\EstadoReserva;
use App\Enums\EstadoSaldo;
use App\Enums\Rol;
use App\Enums\TipoPago;
use App\Jobs\LiberarCupoRetenido;
use App\Models\Excursion;
use App\Models\Guia;
use App\Models\Paquete;
use App\Models\Recorrido;
use App\Models\Reserva;
use App\Models\Usuario;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class GenerarReservaTest extends TestCase
{
    use RefreshDatabase;

    private Excursion $excursion;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-06 10:00:00'));

        // 5 plazas retenidas: 3 de la reserva en curso del cliente y 2 de otro. Así se nota si se descuenta de más.
        $this->excursion = $this->crearExcursion(plazasRetenidas: 5);
    }

    public function test_con_la_sena_registra_la_reserva_pendiente_con_el_saldo_adeudado(): void
    {
        $reserva = Reserva::generarReserva($this->reservaEnCurso(), TipoPago::Sena, 'Tarjeta de crédito')->fresh();

        $this->assertSame(1, Reserva::count());
        $this->assertSame($this->excursion->id_excursion, $reserva->id_excursion);
        $this->assertSame('000001-2', $reserva->numero_reserva);
        $this->assertSame('lucia.fernandez@mail.com', $reserva->correo_electronico);
        $this->assertSame(1, $reserva->noches_extra_antes);
        $this->assertSame(1, $reserva->noches_extra_despues);
        $this->assertSame(EstadoReserva::Pendiente, $reserva->estado);
        $this->assertSame(EstadoSaldo::Adeudado, $reserva->estado_saldo);
        $this->assertSame('2026-10-06 10:00:00', $reserva->fecha_reserva->format('Y-m-d H:i:s'));

        // La salida es el 18/01/2027: las dos vencen un mes antes.
        $this->assertSame('2026-12-18 23:59:59', $reserva->fecha_limite_confirmacion->format('Y-m-d H:i:s'));
        $this->assertSame('2026-12-18 23:59:59', $reserva->fecha_limite_saldo->format('Y-m-d H:i:s'));
    }

    public function test_registra_un_excursionista_por_integrante_con_el_permiso_pendiente(): void
    {
        $reserva = Reserva::generarReserva($this->reservaEnCurso(), TipoPago::Sena, 'Tarjeta de crédito');

        $integrantes = $reserva->excursionistas()->orderBy('id_excursionista')->get()
            ->map(fn ($integrante) => [
                $integrante->nombre,
                $integrante->apellido,
                $integrante->documento_pasaporte,
                $integrante->equipo_camping,
                $integrante->estado_permiso,
            ])
            ->all();

        $this->assertSame([
            ['Lucía', 'Fernández', 'AAG604118', true, EstadoPermiso::Pendiente],
            ['Martín', 'Fernández', 'AAG604119', true, EstadoPermiso::Pendiente],
            ['Sofía', 'Ruiz', 'AAG611025', false, EstadoPermiso::Pendiente],
        ], $integrantes);
    }

    public function test_con_la_sena_registra_el_pago_de_la_mitad_con_su_comprobante(): void
    {
        $reserva = Reserva::generarReserva($this->reservaEnCurso(), TipoPago::Sena, 'Tarjeta de crédito');

        // 3 × 750 + 2 noches × 3 × 60 + 2 equipos × 45 = 2.700; la seña es la mitad.
        $pago = $reserva->pagos()->sole();
        $this->assertSame(TipoPago::Sena, $pago->tipo_pago);
        $this->assertSame('1350.00', $pago->monto);
        $this->assertSame('2026-10-06', $pago->fecha->toDateString());
        $this->assertSame('Tarjeta de crédito', $pago->medio_pago);

        // 0001-, y el id_pago con ocho cifras.
        $comprobante = $pago->comprobante;
        $this->assertSame(sprintf('0001-%08d', $pago->id_pago), $comprobante->numero_comprobante);
        $this->assertSame('2026-10-06 10:00:00', $comprobante->fecha_emision->format('Y-m-d H:i:s'));
    }

    public function test_con_el_total_el_saldo_queda_abonado_y_sin_fecha_limite(): void
    {
        $reserva = Reserva::generarReserva($this->reservaEnCurso(), TipoPago::Total, 'Dinero en cuenta de Mercado Pago')->fresh();

        $this->assertSame(EstadoSaldo::Abonado, $reserva->estado_saldo);
        $this->assertNull($reserva->fecha_limite_saldo);
        $this->assertSame('2026-12-18 23:59:59', $reserva->fecha_limite_confirmacion->format('Y-m-d H:i:s'));

        $pago = $reserva->pagos()->sole();
        $this->assertSame(TipoPago::Total, $pago->tipo_pago);
        $this->assertSame('2700.00', $pago->monto);
        $this->assertSame('Dinero en cuenta de Mercado Pago', $pago->medio_pago);
    }

    public function test_las_plazas_pasan_de_retenidas_a_reservadas(): void
    {
        $cupoAntes = $this->excursion->obtenerCupoDisponible();

        Reserva::generarReserva($this->reservaEnCurso(), TipoPago::Sena, 'Tarjeta de crédito');

        $excursion = $this->excursion->fresh();
        $this->assertSame(2, $excursion->plazas_retenidas);
        $this->assertSame($cupoAntes, $excursion->obtenerCupoDisponible());
    }

    public function test_la_tarea_demorada_que_llega_despues_no_descuenta_de_nuevo(): void
    {
        $enCurso = $this->reservaEnCurso();
        Reserva::generarReserva($enCurso, TipoPago::Sena, 'Tarjeta de crédito');

        // Al vencer el plazo, la cola corre la tarea que se despachó al retener.
        $this->travel(5)->minutes();
        (new LiberarCupoRetenido($this->excursion->id_excursion, 3, $enCurso['id_retencion']))->handle();

        $this->assertSame(2, $this->excursion->fresh()->plazas_retenidas);
    }

    public function test_si_la_retencion_vencio_no_registra_nada(): void
    {
        $enCurso = $this->reservaEnCurso();

        // Justo al vencer ya no se registra, igual que en la pantalla de pago.
        $this->travel(5)->minutes();

        $this->assertNull(Reserva::generarReserva($enCurso, TipoPago::Sena, 'Tarjeta de crédito'));
        $this->assertNoSeGuardoNada();
    }

    public function test_el_mismo_pago_dos_veces_registra_una_sola_reserva(): void
    {
        $enCurso = $this->reservaEnCurso();

        $primera = Reserva::generarReserva($enCurso, TipoPago::Sena, 'Tarjeta de crédito');
        $segunda = Reserva::generarReserva($enCurso, TipoPago::Sena, 'Tarjeta de crédito');

        $this->assertNotNull($primera);
        $this->assertNull($segunda);
        $this->assertDatabaseCount('reserva', 1);
        $this->assertDatabaseCount('excursionista', 3);
        $this->assertDatabaseCount('pago', 1);
        $this->assertDatabaseCount('comprobante', 1);
        $this->assertSame(2, $this->excursion->fresh()->plazas_retenidas);
    }

    public function test_si_la_retencion_ya_se_libero_no_registra_nada_aunque_no_haya_vencido(): void
    {
        $enCurso = $this->reservaEnCurso();

        // El cliente la canceló en otra pestaña: la retención se liberó antes de que se confirme el pago.
        (new LiberarCupoRetenido($this->excursion->id_excursion, 3, $enCurso['id_retencion']))->handle();

        $this->assertNull(Reserva::generarReserva($enCurso, TipoPago::Sena, 'Tarjeta de crédito'));
        $this->assertDatabaseEmpty('reserva');
        $this->assertDatabaseEmpty('excursionista');
        $this->assertDatabaseEmpty('pago');
        $this->assertDatabaseEmpty('comprobante');

        // Bajó una sola vez, con la cancelación.
        $this->assertSame(2, $this->excursion->fresh()->plazas_retenidas);
    }

    public function test_si_algo_falla_en_el_medio_no_queda_nada_guardado(): void
    {
        $enCurso = $this->reservaEnCurso();
        $enCurso['integrantes'][1]['documento_pasaporte'] = $enCurso['integrantes'][0]['documento_pasaporte'];

        // El documento es único dentro de la reserva: falla al guardar el segundo integrante, con la reserva ya creada.
        $this->assertThrows(
            fn () => Reserva::generarReserva($enCurso, TipoPago::Sena, 'Tarjeta de crédito'),
            QueryException::class,
        );

        $this->assertNoSeGuardoNada();
    }

    public function test_si_algo_falla_el_candado_queda_libre_para_el_siguiente_pago(): void
    {
        // Con la hora real: block() mide la espera con now(), y con la hora detenida un candado que quedara tomado haría
        // esperar a la prueba para siempre en vez de fallar a los 5 segundos.
        $this->travelBack();
        $enCurso = $this->reservaEnCurso();
        $enCurso['integrantes'][1]['documento_pasaporte'] = $enCurso['integrantes'][0]['documento_pasaporte'];

        $this->assertThrows(fn () => Reserva::generarReserva($enCurso, TipoPago::Sena, 'Tarjeta de crédito'));

        $this->assertNotNull(Reserva::generarReserva($this->reservaEnCurso(), TipoPago::Sena, 'Tarjeta de crédito'));
    }

    public function test_el_saldo_no_registra_una_reserva(): void
    {
        $this->assertThrows(
            fn () => Reserva::generarReserva($this->reservaEnCurso(), TipoPago::Saldo, 'Tarjeta de crédito'),
            InvalidArgumentException::class,
        );

        $this->assertNoSeGuardoNada();
    }

    public function test_dos_reservas_seguidas_reciben_numeros_consecutivos(): void
    {
        $primera = Reserva::generarReserva($this->reservaEnCurso(), TipoPago::Sena, 'Tarjeta de crédito');

        // La otra retención de la excursión: 2 plazas de otro cliente.
        $segunda = Reserva::generarReserva($this->reservaEnCurso('federico.rios@mail.com', [
            ['nombre' => 'Federico', 'apellido' => 'Ríos', 'documento_pasaporte' => 'AAH700201', 'equipo_camping' => false],
            ['nombre' => 'Sofía', 'apellido' => 'Ríos', 'documento_pasaporte' => 'AAH700202', 'equipo_camping' => false],
        ]), TipoPago::Total, 'Tarjeta de débito');

        $this->assertSame('000001-2', $primera->numero_reserva);
        $this->assertSame('000002-4', $segunda->numero_reserva);
        $this->assertSame(0, $this->excursion->fresh()->plazas_retenidas);
    }

    public function test_la_reserva_generada_se_encuentra_con_el_correo_y_el_numero(): void
    {
        $reserva = Reserva::generarReserva($this->reservaEnCurso(), TipoPago::Sena, 'Tarjeta de crédito');

        $encontrada = Reserva::buscarPorCorreoYNumero('lucia.fernandez@mail.com', '000001-2');

        $this->assertTrue($encontrada->is($reserva));
    }

    private function assertNoSeGuardoNada(): void
    {
        $this->assertDatabaseEmpty('reserva');
        $this->assertDatabaseEmpty('excursionista');
        $this->assertDatabaseEmpty('pago');
        $this->assertDatabaseEmpty('comprobante');
        $this->assertSame(5, $this->excursion->fresh()->plazas_retenidas);
    }

    // Como la guarda CU-14 en la sesión al confirmar. Por defecto, la reserva del prototipo: 3 integrantes, 2 con
    // equipo de camping y una noche extra antes y una después.
    private function reservaEnCurso(string $correo = 'lucia.fernandez@mail.com', ?array $integrantes = null): array
    {
        return [
            'id_retencion' => (string) Str::uuid(),
            'id_excursion' => $this->excursion->id_excursion,
            'correo_electronico' => $correo,
            'noches_extra_antes' => 1,
            'noches_extra_despues' => 1,
            'integrantes' => $integrantes ?? [
                ['nombre' => 'Lucía', 'apellido' => 'Fernández', 'documento_pasaporte' => 'AAG604118', 'equipo_camping' => true],
                ['nombre' => 'Martín', 'apellido' => 'Fernández', 'documento_pasaporte' => 'AAG604119', 'equipo_camping' => true],
                ['nombre' => 'Sofía', 'apellido' => 'Ruiz', 'documento_pasaporte' => 'AAG611025', 'equipo_camping' => false],
            ],
            'vence' => now()->addMinutes(5)->toIso8601String(),
        ];
    }

    private function crearExcursion(int $plazasRetenidas): Excursion
    {
        $recorrido = Recorrido::create(['nombre' => 'Camino Inca de 4 días', 'duracion_dias' => 4, 'cantidad_campings' => 3]);

        $paquete = Paquete::create([
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

        return Excursion::create([
            'id_paquete' => $paquete->id_paquete,
            'id_guia' => $usuario->id_usuario,
            'fecha_salida' => '2027-01-18',
            'cupo' => 12,
            'plazas_retenidas' => $plazasRetenidas,
        ]);
    }
}
